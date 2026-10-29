-- PostgreSQL 18. Exécuter avec psql -X -v ON_ERROR_STOP=1 -d BASE -f ce_fichier.
-- Le search_path doit désigner le schéma contenant l’export du sujet.
BEGIN;
SET LOCAL lock_timeout = '5s';
SET LOCAL statement_timeout = '60s';
SET LOCAL TIME ZONE 'UTC';
LOCK TABLE candidats, concours, centres, inscriptions IN ACCESS EXCLUSIVE MODE;

-- Arrêt atomique sur les cas qui exigent une décision humaine plutôt qu’une fusion.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM candidats GROUP BY npi HAVING count(*) > 1) THEN
        RAISE EXCEPTION 'NPI candidat dupliqué : arbitrer les identités avant réparation';
    END IF;
    IF EXISTS (
        SELECT 1 FROM inscriptions i JOIN centres ce ON ce.id = i.centre_id
        WHERE i.numero_table !~ ('^' || ce.code || '-[0-9]{4,}$')
    ) THEN
        RAISE EXCEPTION 'Numéro de table mal formé : intervention manuelle requise';
    END IF;
    IF EXISTS (
        SELECT 1 FROM inscriptions i JOIN centres ce ON ce.id = i.centre_id
        WHERE ce.code = 'L' AND EXISTS (
            SELECT 1 FROM inscriptions d WHERE d.npi = i.npi
              AND d.concours_id = i.concours_id AND d.id <> i.id
        )
    ) THEN
        RAISE EXCEPTION 'Double candidature impliquant Lokossa : aucune suppression automatique autorisée';
    END IF;
    IF EXISTS (
        SELECT 1 FROM inscriptions i JOIN concours co ON co.id = i.concours_id
        GROUP BY i.npi, co.date_epreuve HAVING count(DISTINCT co.id) > 1
    ) THEN
        RAISE EXCEPTION 'Concours différents le même jour : arbitrage requis avant réparation';
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS reparation_doublons_inscriptions (
    inscription_id bigint PRIMARY KEY,
    inscription_conservee_id bigint NOT NULL,
    donnees_originales jsonb NOT NULL,
    repare_le timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS reparation_numeros_table (
    inscription_id bigint PRIMARY KEY,
    ancien_numero varchar(32) NOT NULL,
    nouveau_numero varchar(32) NOT NULL,
    repare_le timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS reparation_lokossa_initiale (
    inscription_id bigint PRIMARY KEY,
    donnees_originales jsonb NOT NULL,
    capacite_initiale integer NOT NULL,
    constate_le timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO reparation_lokossa_initiale (inscription_id, donnees_originales, capacite_initiale)
SELECT i.id, to_jsonb(i), ce.capacite FROM inscriptions i
JOIN centres ce ON ce.id = i.centre_id WHERE ce.code = 'L'
ON CONFLICT (inscription_id) DO NOTHING;

-- Conserver la première inscription enregistrée, avec l’id comme départage stable.
-- Dédupliquer avant de renuméroter évite de remplacer un numéro qui sera retiré.
WITH classees AS (
    SELECT id, first_value(id) OVER (
        PARTITION BY npi, concours_id ORDER BY cree_le, id
    ) AS conservee_id FROM inscriptions
)
INSERT INTO reparation_doublons_inscriptions (inscription_id, inscription_conservee_id, donnees_originales)
SELECT i.id, c.conservee_id, to_jsonb(i) FROM classees c
JOIN inscriptions i ON i.id = c.id WHERE c.id <> c.conservee_id
ON CONFLICT (inscription_id) DO NOTHING;
WITH classees AS (
    SELECT id, row_number() OVER (PARTITION BY npi, concours_id ORDER BY cree_le, id) AS rang
    FROM inscriptions
)
DELETE FROM inscriptions i USING classees c
WHERE i.id = c.id AND c.rang > 1;

-- Les nouveaux numéros sont au-delà du maximum existant, sans réutiliser un ancien numéro.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = 'inscriptions'
          AND column_name = 'numero_table' AND character_maximum_length < 32
    ) THEN
        ALTER TABLE inscriptions ALTER COLUMN numero_table TYPE varchar(32);
    END IF;
END $$;
WITH classees AS (
    SELECT id, centre_id, numero_table,
        row_number() OVER (PARTITION BY centre_id, numero_table ORDER BY cree_le, id) AS rang
    FROM inscriptions
), maximums AS (
    SELECT centre_id, max(substring(numero_table FROM 3)::bigint) AS maximum
    FROM inscriptions GROUP BY centre_id
), remplacements AS (
    SELECT c.id, c.numero_table, ce.code,
        m.maximum + row_number() OVER (PARTITION BY c.centre_id ORDER BY c.id) AS nouveau
    FROM classees c JOIN maximums m USING (centre_id) JOIN centres ce ON ce.id = c.centre_id
    WHERE c.rang > 1
)
INSERT INTO reparation_numeros_table (inscription_id, ancien_numero, nouveau_numero)
SELECT id, numero_table, code || '-' || lpad(nouveau::text, greatest(4, length(nouveau::text)), '0')
FROM remplacements ON CONFLICT (inscription_id) DO NOTHING;
UPDATE inscriptions i SET numero_table = a.nouveau_numero
FROM reparation_numeros_table a
WHERE i.id = a.inscription_id AND i.numero_table = a.ancien_numero;

-- Contraintes d’unicité et références. Chaque nom est vérifié dans la table cible.
DO $$
DECLARE
    c record;
BEGIN
    FOR c IN SELECT * FROM (VALUES
        ('candidats', 'candidats_npi_unique', 'UNIQUE (npi)'),
        ('centres', 'centres_code_unique', 'UNIQUE (code)'),
        ('concours', 'concours_code_unique', 'UNIQUE (code)'),
        ('inscriptions', 'inscriptions_npi_concours_unique', 'UNIQUE (npi, concours_id)'),
        ('inscriptions', 'inscriptions_centre_numero_unique', 'UNIQUE (centre_id, numero_table)'),
        ('inscriptions', 'inscriptions_npi_foreign', 'FOREIGN KEY (npi) REFERENCES candidats(npi)')
    ) AS definitions(table_name, constraint_name, definition)
    LOOP
        IF NOT EXISTS (SELECT 1 FROM pg_constraint
            WHERE conrelid = to_regclass(c.table_name) AND conname = c.constraint_name) THEN
            EXECUTE format('ALTER TABLE %I ADD CONSTRAINT %I %s', c.table_name, c.constraint_name, c.definition);
        END IF;
    END LOOP;
END $$;

-- La date copiée est liée au concours par une FK ; son unicité protège aussi les accès SQL directs.
ALTER TABLE inscriptions ADD COLUMN IF NOT EXISTS date_epreuve date;
UPDATE inscriptions i SET date_epreuve = co.date_epreuve FROM concours co
WHERE co.id = i.concours_id AND i.date_epreuve IS DISTINCT FROM co.date_epreuve;
ALTER TABLE inscriptions ALTER COLUMN date_epreuve SET NOT NULL;
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'concours'::regclass AND conname = 'concours_id_date_unique') THEN
        ALTER TABLE concours ADD CONSTRAINT concours_id_date_unique UNIQUE (id, date_epreuve);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'inscriptions'::regclass AND conname = 'inscriptions_concours_date_foreign') THEN
        ALTER TABLE inscriptions ADD CONSTRAINT inscriptions_concours_date_foreign
            FOREIGN KEY (concours_id, date_epreuve) REFERENCES concours(id, date_epreuve);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conrelid = 'inscriptions'::regclass AND conname = 'inscriptions_npi_date_unique') THEN
        ALTER TABLE inscriptions ADD CONSTRAINT inscriptions_npi_date_unique UNIQUE (npi, date_epreuve);
    END IF;
END $$;

-- Compteur atomique : 307 à Lokossa reste 307, avec une capacité toujours égale à 300.
-- Aucun CHECK compteur <= capacité ne validerait l’état historique à préserver.
ALTER TABLE centres ADD COLUMN IF NOT EXISTS nb_inscrits integer NOT NULL DEFAULT 0;
UPDATE centres ce SET nb_inscrits = (SELECT count(*) FROM inscriptions i WHERE i.centre_id = ce.id);
CREATE OR REPLACE FUNCTION proteger_inscription() RETURNS trigger
LANGUAGE plpgsql SET search_path FROM CURRENT AS $$
BEGIN
    SELECT date_epreuve INTO STRICT NEW.date_epreuve FROM concours WHERE id = NEW.concours_id FOR SHARE;
    RETURN NEW;
END $$;
CREATE OR REPLACE TRIGGER inscriptions_protection
BEFORE INSERT OR UPDATE ON inscriptions
FOR EACH ROW EXECUTE FUNCTION proteger_inscription();

-- AFTER ne s’exécute que pour une ligne réellement écrite : ON CONFLICT DO NOTHING
-- ne doit pas consommer de place, et DO UPDATE ne doit pas compter deux inscriptions.
CREATE OR REPLACE FUNCTION compter_inscription() RETURNS trigger
LANGUAGE plpgsql SET search_path FROM CURRENT AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        UPDATE centres SET nb_inscrits = nb_inscrits - 1 WHERE id = OLD.centre_id;
        RETURN OLD;
    END IF;
    IF TG_OP = 'INSERT' THEN
        UPDATE centres SET nb_inscrits = nb_inscrits + 1
        WHERE id = NEW.centre_id AND nb_inscrits < capacite;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'Capacité du centre atteinte ou centre inconnu' USING ERRCODE = '23514';
        END IF;
    ELSIF NEW.centre_id IS DISTINCT FROM OLD.centre_id THEN
        UPDATE centres SET nb_inscrits = nb_inscrits + 1
        WHERE id = NEW.centre_id AND nb_inscrits < capacite;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'Capacité du centre atteinte ou centre inconnu' USING ERRCODE = '23514';
        END IF;
        UPDATE centres SET nb_inscrits = nb_inscrits - 1 WHERE id = OLD.centre_id;
    END IF;
    RETURN NEW;
END $$;
CREATE OR REPLACE TRIGGER inscriptions_compteur
AFTER INSERT OR UPDATE OR DELETE ON inscriptions
FOR EACH ROW EXECUTE FUNCTION compter_inscription();

-- L’export ne fournit pas de séquences pour ces ids ; ne jamais repartir de 1 ni reculer au rejeu.
DO $$
DECLARE
    table_name text;
    sequence_name text;
    maximum bigint;
    sequence_value bigint;
BEGIN
    FOREACH table_name IN ARRAY ARRAY['candidats', 'inscriptions'] LOOP
        sequence_name := pg_get_serial_sequence(table_name, 'id');
        IF sequence_name IS NULL THEN
            EXECUTE format('ALTER TABLE %I ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY', table_name);
            sequence_name := pg_get_serial_sequence(table_name, 'id');
        END IF;
        EXECUTE format('SELECT max(id) FROM %I', table_name) INTO maximum;
        IF table_name = 'inscriptions' THEN
            SELECT greatest(maximum, max(inscription_id)) INTO maximum FROM reparation_doublons_inscriptions;
        END IF;
        EXECUTE format('SELECT last_value FROM %s', sequence_name) INTO sequence_value;
        IF maximum IS NOT NULL AND maximum >= sequence_value THEN
            PERFORM setval(sequence_name::regclass, maximum, true);
        END IF;
    END LOOP;
END $$;

-- Le rang chronologique est informatif et ne vaut jamais décision de transfert ou d’annulation.
CREATE OR REPLACE VIEW reparation_lokossa_arbitrage AS
SELECT i.id AS inscription_id, i.npi, c.nom, c.prenoms, co.code AS concours,
    a.donnees_originales->>'numero_table' AS numero_initial, i.numero_table AS numero_actuel,
    i.cree_le AT TIME ZONE 'UTC' AT TIME ZONE 'Africa/Porto-Novo' AS inscription_heure_benin,
    row_number() OVER (ORDER BY i.cree_le, i.id) AS rang_chronologique,
    ce.capacite, count(*) OVER () AS total_inscrits,
    greatest(count(*) OVER () - ce.capacite, 0) AS places_a_arbitrer
FROM inscriptions i JOIN centres ce ON ce.id = i.centre_id
JOIN candidats c ON c.npi = i.npi JOIN concours co ON co.id = i.concours_id
LEFT JOIN reparation_lokossa_initiale a ON a.inscription_id = i.id WHERE ce.code = 'L';

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM reparation_lokossa_initiale a
        LEFT JOIN inscriptions i ON i.id = a.inscription_id WHERE i.id IS NULL) THEN
        RAISE EXCEPTION 'Une inscription de Lokossa a disparu : annulation de la réparation';
    END IF;
END $$;
COMMIT;
