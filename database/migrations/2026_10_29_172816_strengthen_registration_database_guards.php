<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
LOCK TABLE candidats, concours, centres, inscriptions IN ACCESS EXCLUSIVE MODE;
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

SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS inscriptions_compteur ON inscriptions;
DROP TRIGGER IF EXISTS inscriptions_protection ON inscriptions;
DROP FUNCTION IF EXISTS compter_inscription();
DROP FUNCTION IF EXISTS proteger_inscription();
ALTER TABLE inscriptions DROP CONSTRAINT IF EXISTS inscriptions_npi_date_unique;
ALTER TABLE inscriptions DROP CONSTRAINT IF EXISTS inscriptions_concours_date_foreign;
ALTER TABLE inscriptions DROP COLUMN IF EXISTS date_epreuve;
ALTER TABLE concours DROP CONSTRAINT IF EXISTS concours_id_date_unique;
ALTER TABLE centres DROP COLUMN IF EXISTS nb_inscrits;
SQL);
    }
};
