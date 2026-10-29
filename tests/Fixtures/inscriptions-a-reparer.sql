CREATE TABLE concours (id integer PRIMARY KEY, code varchar(10) NOT NULL, libelle varchar(100) NOT NULL, date_epreuve date NOT NULL, cloture timestamp NOT NULL);
CREATE TABLE centres (id integer PRIMARY KEY, code varchar(1) NOT NULL, nom varchar(50) NOT NULL, capacite integer NOT NULL);
CREATE TABLE candidats (id integer PRIMARY KEY, npi varchar(10) NOT NULL, nom varchar(60) NOT NULL, prenoms varchar(80) NOT NULL, date_naissance date NOT NULL, cree_le timestamp NOT NULL);
CREATE TABLE inscriptions (id integer PRIMARY KEY, concours_id integer NOT NULL REFERENCES concours(id), centre_id integer NOT NULL REFERENCES centres(id), npi varchar(10) NOT NULL, numero_table varchar(6) NOT NULL, statut varchar(30) NOT NULL, cree_le timestamp NOT NULL);
INSERT INTO concours VALUES
(1, 'DOUANES', 'Douanes', '2026-12-12', '2026-11-20 22:59:00'),
(2, 'IMPOTS', 'Impôts', '2026-12-12', '2026-11-20 22:59:00'),
(3, 'TRESOR', 'Trésor', '2026-12-19', '2026-11-20 22:59:00');
INSERT INTO centres VALUES (1, 'P', 'Parakou', 5), (2, 'L', 'Lokossa', 2), (3, 'C', 'Cotonou', 2);
INSERT INTO candidats VALUES
(1, '0000000001', 'Nom1', 'Prenom1', '1991-06-20', '2026-11-18 08:00:00'),
(2, '0000000002', 'Nom2', 'Prenom2', '1991-06-20', '2026-11-18 08:00:00'),
(3, '0000000003', 'Nom3', 'Prenom3', '1991-06-20', '2026-11-18 08:00:00'),
(4, '0000000004', 'Nom4', 'Prenom4', '1991-06-20', '2026-11-18 08:00:00'),
(5, '0000000005', 'Nom5', 'Prenom5', '1991-06-20', '2026-11-18 08:00:00');
INSERT INTO inscriptions VALUES
(10, 1, 1, '0000000001', 'P-0001', 'validee', '2026-11-19 08:00:00'),
(11, 1, 1, '0000000001', 'P-0001', 'validee', '2026-11-19 08:00:01'),
(12, 1, 1, '0000000002', 'P-0001', 'validee', '2026-11-19 08:00:02'),
(20, 1, 2, '0000000003', 'L-0001', 'validee', '2026-11-19 08:00:00'),
(21, 1, 2, '0000000004', 'L-0001', 'validee', '2026-11-19 08:00:01'),
(22, 3, 2, '0000000005', 'L-0002', 'validee', '2026-11-19 08:00:02');
