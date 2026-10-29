# Étude de cas TMA DEP/ASIN 2026 — Inscriptions aux concours

**Candidat : DENAKPO EZECHIEL AHOUANDJINOU**

[Dépôt GitHub](https://github.com/ezechieldef/testasin) · [Énoncé](etude_de_cas_pratique.md)

Ce dépôt contient la priorisation des incidents, un analyseur de journal en ligne de commande, les diagnostics reproductibles, une API Laravel 13 d’inscription corrigée, ses tests et un script PostgreSQL de réparation rejouable. Les rapports distinguent les faits observés, les résultats locaux et les actions à mener en production.

## Parcours de lecture du jury

| Consigne | Livrable | Point d’entrée / vérification |
| --- | --- | --- |
| 1 — Prioriser avant analyse | [INTERVENTION.md](INTERVENTION.md) | Premier commit `6fc40f5`, contenant uniquement ce fichier ; INC-208 identifié comme conforme. |
| 2 — Analyser le journal | [ProductionLogAnalyzer](app/Services/ProductionLogAnalyzer.php) et [commande Artisan](app/Console/Commands/AnalyzeProductionLog.php) | `php artisan journal:analyser journal_production.log` ; `--json` pour les détails. |
| 3 — Causes et liens | [ANALYSE_INCIDENTS.md](ANALYSE_INCIDENTS.md) | `php artisan incidents:diagnostiquer journal_production.log` et `php artisan journal:tracer journal_production.log --requete=a061413f`. |
| 4 — Corriger et tester | [RegisterInscription](app/Services/RegisterInscription.php), [IdentityClient](app/Services/IdentityClient.php), [tests fonctionnels](tests/Feature/RegisterInscriptionTest.php) et [concurrence](tests/Feature/ConcurrentInscriptionTest.php) | `POST /api/inscriptions` ; tests de capacité, unicité, âge, clôture et délai externe. |
| 5 — Réparer les données | [Script SQL](database/reparer_inscriptions.sql) et [REPARATION_DONNEES.md](REPARATION_DONNEES.md) | Application sur une copie de l’export, rejeu, contrôles et arbitrage de Lokossa. |
| 6 — Rapport d’incident | [RAPPORT_INCIDENT_INC-201.md](RAPPORT_INCIDENT_INC-201.md) | Chronologie, cause, impact chiffré, correction et prévention. |
| Bonus — Incident non signalé | [INCIDENT_NON_SIGNALE.md](INCIDENT_NON_SIGNALE.md) | Injection SQL observée, exposition possible et mesures immédiates proposées. |

## Résultats de référence

Sur le journal fourni : **10 numéros réutilisés**, **7 doubles candidatures**, **60 requêtes touchées par une identification lente**, **147 refus de clôture injustifiés pour 100 NPI distincts** et **36 refus d’âge injustifiés**. Les moyennes et maxima des listes sont calculés pour les cinq centres.

La reprise locale a archivé et retiré 7 doubles inscriptions, puis réattribué 15 numéros : 6 393 inscriptions restent actives. Les **307 inscriptions de Lokossa sont conservées pour une capacité de 300** ; les sept places manquantes nécessitent une décision métier. Les résultats complets, leur périmètre et leurs limites figurent dans les documents correspondants.

## Prérequis

- PHP **8.4**, Composer et les extensions validées par `composer check-platform-reqs` ; `pdo_sqlite` pour les tests par défaut, `pdo_pgsql` pour PostgreSQL et cURL pour le transport HTTP vérifié.
- PostgreSQL **18** et ses outils `psql`, `createdb`, `pg_dump`, `pg_restore` pour la reprise et les tests SQL ; un compte autorisé à créer des schémas temporaires pour les tests PostgreSQL.
- Node.js **20.19 ou supérieur compatible avec Vite 8** : `20.19+` sur la branche 20 ou `22.12+` ; npm. Ils servent à compiler les ressources de la page web, pas à exécuter l’analyse du journal.

Validation : **103 tests réussis avec les tests PostgreSQL et l’export original activés**, plus **3 tests de concurrence réussis sur PostgreSQL**. La compilation web et le rejeu du script de réparation ont également été vérifiés.

## Installation

```sh
git clone https://github.com/ezechieldef/testasin.git
cd testasin
composer install
cp .env.example .env
php artisan key:generate
composer check-platform-reqs
```

Les fichiers `journal_production.log` et `base_inscriptions.sql` sont les données d’entrée fournies séparément par le sujet : ils ne sont pas inclus dans le dépôt. Les placer à la racine ou adapter les chemins des commandes. Les fixtures synthétiques versionnées suffisent pour les tests automatisés.

Essai immédiat sans base métier ni service externe :

```sh
php artisan journal:analyser tests/Fixtures/production-log.txt
php artisan test --compact
```

Les tests fonctionnels utilisent SQLite en mémoire, les tests concurrents une base SQLite temporaire et les appels d’identité sont simulés, sauf un test de délai qui utilise un serveur local. Sans `REPAIR_TEST_DATABASE`, les tests SQL PostgreSQL sont explicitement ignorés ; leur activation est décrite plus bas. Aucun de ces tests ne nécessite le journal réel.

Pour utiliser la base du sujet, créer une **base de travail distincte**, importer l’export avec `psql`, puis configurer `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` et `DB_PASSWORD` dans `.env`. Les exemples `-U votre_role` ci-dessous doivent être adaptés au compte PostgreSQL disponible. **Ne pas exécuter les migrations Laravel sur cet export**, ni `composer run setup`, qui lance des migrations : le script SQL de la consigne 5 assure sa mise à niveau.

Pour la page web, compiler ensuite les ressources :

```sh
npm ci
npm run build
```

Les sessions et le cache utilisent des fichiers ; les tâches sont synchrones. La page d’accueil ne nécessite aucune migration. L’API d’inscription utilise les tables et la configuration du service d’identité décrites dans la consigne 4.

Les instants applicatifs sont conservés en UTC. Les commandes de restitution affichent les horaires dans le fuseau `Africa/Porto-Novo`.

## Démarrage

```sh
php artisan serve
```

Ouvrir http://localhost:8000. Pour modifier les styles en direct, lancer `npm run dev` dans un second terminal.

## Vérification

### Consigne 2 — Analyse du journal de production

Depuis la racine du projet :

```sh
php artisan journal:analyser journal_production.log
```

Le chemin est un argument obligatoire : le programme accepte tout fichier respectant le format du sujet. Le journal est lu ligne par ligne, sans modification du fichier, accès à la base ou appel au service d’identification.

Pour obtenir tous les détails dans un format réutilisable :

```sh
php artisan journal:analyser journal_production.log --json
```

Les paramètres métier sont ceux du sujet par défaut. Ils peuvent être remplacés ensemble pour une autre campagne :

```sh
php artisan journal:analyser journal_production.log --annee=2027 --cloture-tresor="2027-11-20 23:59:00"
```

Le service `App\Services\ProductionLogAnalyzer` retourne un objet `LogAnalysisReport`, indépendant de l’affichage console. Une future interface web pourra appeler ce même service et utiliser `toArray()` ; aucune interface web n’est ajoutée à cette étape.

Les six résultats suivent ces conventions :

- **Numéros multiples** : regroupement des créations HTTP 201 par centre et numéro, tous concours confondus.
- **Inscriptions multiples** : regroupement des créations HTTP 201 par NPI et concours, tous centres confondus ; les refus ne créent pas de doublons.
- **Identité lente** : durée de l’appel supérieure ou égale à 5 000 ms, indépendamment du niveau du log et du statut HTTP ; un identifiant de requête est compté une fois. Le rapport fournit les appels, leur répartition par endpoint, l’enveloppe observée et les intervalles fusionnés des appels qui se chevauchent. Cette enveloppe ne démontre pas une indisponibilité continue.
- **Clôture du Trésor** : refus explicitement motivés par la clôture, le jour local de celle-ci, alors que la requête avait commencé au plus tard à l’instant officiel de clôture. Le début est reconstruit par soustraction de la durée ; l’instant exact du contrôle interne n’est pas journalisé.
- **Âge** : refus explicitement motivés par l’âge alors que le candidat a au plus 35 années révolues au 31 décembre de l’année du concours, sans division du nombre de jours par 365.
- **Listes par centre** : moyenne arithmétique et maximum en millisecondes des réponses HTTP 200, tous concours confondus ; nombre de réponses ne respectant pas l’objectif strict de moins de 2 000 ms.

Tous les instants du rapport sont convertis en `Africa/Porto-Novo` et incluent le décalage `+01:00`. Les détails JSON conservent les numéros de ligne et les identifiants de requête. Les nombres de refus et de candidats distincts sont séparés, et les NPI conservent leurs zéros initiaux. Les doublons observés ne couvrent que la fenêtre du journal ; une vérification de l’ensemble des données nécessitera l’export SQL.

Une ligne non vide illisible, une date invalide ou un champ nécessaire absent interrompt l’analyse avec une erreur et un code de sortie 1. Les lignes vides sont ignorées ; les répétitions identiques d’un même événement sont comptées séparément puis ignorées pour les indicateurs ; deux événements contradictoires pour le même identifiant et le même type (HTTP ou identité) provoquent une erreur. Une analyse réussie retourne le code 0, même si elle révèle des incidents.

Les tests utilisent un journal synthétique versionné ; le journal réel, ignoré par Git, n’est pas nécessaire pour les exécuter :

```sh
php artisan test --compact tests/Feature/AnalyzeProductionLogTest.php tests/Unit/Services/ProductionLogAnalyzerTest.php
```

### Consigne 3 — Diagnostics et preuves reproductibles

L’analyse rédigée, avec les causes, preuves, limites et liens entre les huit signalements, se trouve dans [ANALYSE_INCIDENTS.md](ANALYSE_INCIDENTS.md).

Afficher le raisonnement et les preuves calculées pour tous les incidents, ou pour un seul :

```sh
php artisan incidents:diagnostiquer journal_production.log
php artisan incidents:diagnostiquer journal_production.log --incident=INC-201
```

Chaque diagnostic expose la procédure suivie, les constats chiffrés, l’explication tirée du dossier, les limites et les preuves avec leurs lignes et identifiants de requête. Les chevauchements des requêtes et les intersections entre incidents sont calculés. Les explications métier sont propres au cas 2026 ; elles ne sont pas des causes découvertes automatiquement pour n’importe quelle application.

La sortie texte affiche cinq éléments de preuve par incident par défaut. Utiliser `--limite=0` pour tout afficher ou `--json` pour obtenir le rapport complet, sans troncature. `--incident` conserve les liens concernant l’incident sélectionné.

L’option `--base` ajoute les comptages de tous les centres, les dates de concours et de clôture, les antécédents des refus pour épreuve le même jour et les index des tables concernées :

```sh
php artisan incidents:diagnostiquer journal_production.log --base --json
```

Elle lit la connexion définie dans `.env` et suppose que les tables du sujet ont été importées. Aucune migration ni réparation n’est exécutée. Sur PostgreSQL, les lectures utilisent une transaction en lecture seule avec un instantané cohérent. La base pouvant évoluer, vérifier sa provenance avant d’assimiler les résultats à l’export initial. Sans `--base`, aucune connexion à la base n’est nécessaire ; le total final de Lokossa reste alors à vérifier.

Pour suivre une requête et son appel d’identité, un numéro de table ou les démarches d’un NPI :

```sh
php artisan journal:tracer journal_production.log --requete=a061413f
php artisan journal:tracer journal_production.log --centre=P --numero=P-0464
php artisan journal:tracer journal_production.log --npi=2256599771 --concours=DOUANES --json
php artisan journal:tracer journal_production.log --centre=L --debut="2026-11-19 09:10:00" --fin="2026-11-19 09:13:00"
```

Au moins un filtre est obligatoire. Les filtres sont combinés par **ET**, avec des correspondances exactes. Les périodes sont saisies en heure du Bénin, au format `Y-m-d H:i:s`, avec bornes inclusives. Elles sélectionnent les requêtes dont l’intervalle reconstruit chevauche la période, puis affichent tous leurs événements corrélés, même ceux situés hors de la période. Le NPI peut être retrouvé dans l’URL d’un appel d’identité, ce qui permet de retrouver également une convocation. Centre et concours sont aussi extraits des URL de listes.

Les traces sont triées par début reconstruit, et non par ordre de fin du fichier. Le SHA-256 affiché permet de comparer la source utilisée à celle du rapport rédigé. Une recherche sans résultat réussit avec zéro requête ; les erreurs de fichier, de filtre ou de base retournent le code 1. Les règles de parsing et de répétition des événements restent celles de l’analyse précédente.

Tests des nouvelles commandes :

```sh
php artisan test --compact tests/Feature/TraceJournalTest.php tests/Feature/DiagnoseIncidentsTest.php
```

Ils utilisent un journal synthétique et, pour `--base`, une base SQLite en mémoire construite par le test. La base métier locale n’est pas utilisée par ces tests.

### Consigne 4 — Enregistrement d’une inscription

`POST /api/inscriptions` accepte un corps JSON :

```json
{
  "npi": "3076016910",
  "date_naissance": "1991-06-20",
  "concours": "TRESOR",
  "centre": "P"
}
```

Configurer `IDENTITY_SERVICE_URL` dans `.env`. Le service doit répondre à `GET /identite/{npi}` avec `{"nom":"DOSSOU","prenoms":"Alice"}` ou un statut 404 pour un NPI inconnu. La création renvoie 201 avec `data.id`, `data.numero_table` et `data.statut`. Les erreurs de forme ou références inconnues renvoient 400, les conflits de capacité ou d’inscription 409, les refus d’âge, de clôture ou d’identité 422. Une configuration d’identification absente renvoie 503.

Les doublons, conflits de jour d’épreuve, centres pleins et dates de naissance incohérentes sont contrôlés avant l’appel externe. Ces contrôles sont répétés dans la transaction sous verrou pour couvrir les changements concurrents. L’appel externe précède la transaction, avec des délais de connexion et de réponse de 5 secondes, sans répétition ni redirection. Une panne réseau, un dépassement du délai, une réponse non exploitable ou une erreur du service enregistre `en_attente_verification` ; une identité reconnue enregistre `validee`. Pour un nouveau candidat en attente, les noms restent vides jusqu’à vérification. Un 404 refuse l’inscription. Le traitement ultérieur des inscriptions en attente reste à implémenter.

Sur PostgreSQL, un verrou transactionnel consultatif par NPI couvre aussi le cas où le candidat n’existe pas encore, et empêche deux inscriptions incompatibles dans des centres différents. Un verrou partagé protège le concours et un verrou exclusif protège le centre. La vérification des inscriptions existantes, le contrôle de capacité tous concours et statuts confondus, le calcul du prochain numéro et les écritures sont exécutés dans cette même transaction. Les verrous sont libérés au commit ou au rollback ; tous les chemins d’enregistrement doivent passer par ce service. SQLite est également pris en charge : une écriture initiale acquiert son verrou d’écriture avant les lectures métier.

L’âge est le nombre d’années révolues au 31 décembre de l’année de l’épreuve. La clôture stockée en UTC est comparée à l’instant d’admission dans le service, avant l’appel externe : cet instant est accepté jusqu’à la clôture incluse, même si l’identification se termine après. Le serveur peut fonctionner dans un autre fuseau. Une date de naissance différente de celle d’un candidat déjà enregistré est refusée.

Les migrations initialisent **une base vierge**, avec les clés étrangères et les contraintes uniques sur NPI candidat, NPI/concours et centre/numéro. Sur PostgreSQL, la migration `strengthen_registration_database_guards` ajoute aussi l’unicité NPI/jour d’épreuve, la cohérence de cette date avec le concours et les compteurs de capacité atomiques, comme le script de réparation. Les tests `RegistrationSchemaTest` exercent les mêmes écritures SQL sur les deux chemins. SQLite utilise les contrôles transactionnels applicatifs pour le jour d’épreuve et la capacité. Ne pas exécuter les migrations initiales sur l’export existant : sa mise à niveau est effectuée par le script SQL de la consigne 5 ci-dessous.

Tests fonctionnels et concurrence, sans dépendance au service d’identification réel :

```sh
php artisan test --compact tests/Feature/RegisterInscriptionTest.php tests/Feature/ConcurrentInscriptionTest.php
```

Par défaut, les tests fonctionnels utilisent SQLite en mémoire et les tests concurrents un fichier SQLite temporaire. Les tests concurrents démarrent plusieurs processus PHP, chacun envoyant une requête HTTP au noyau Laravel. Une barrière dans le faux service d’identification garantit que toutes les requêtes ont commencé avant leur entrée dans la transaction. Ils vérifient les places disponibles, l’unicité des numéros, les doubles soumissions et le conflit de jour d’épreuve entre centres. Le test réseau réel lance uniquement un serveur local qui ne répond pas à temps ; il dure environ 5 secondes.

Pour vérifier les verrous PostgreSQL avec la connexion locale :

```sh
DB_CONNECTION=pgsql DB_DATABASE=testasin php artisan test --compact tests/Feature/ConcurrentInscriptionTest.php
```

Adapter les variables `DB_*` à la connexion disponible. Ce test crée un schéma temporaire distinct et le supprime à la fin ; le compte PostgreSQL doit pouvoir créer des schémas. Les tables importées restent intactes. Ne pas lancer les tests fonctionnels utilisant la remise à zéro des migrations contre la base importée.

### Consigne 5 — Réparation de l’export PostgreSQL

Le bilan exécuté, les règles de conservation, l’arbitrage de Lokossa et les destinataires à prévenir sont décrits dans [REPARATION_DONNEES.md](REPARATION_DONNEES.md). Le script est [database/reparer_inscriptions.sql](database/reparer_inscriptions.sql). Il a été exécuté et rejoué sur la base locale `testasin` après validation sur une copie restaurée : 7 doublons archivés et retirés, 15 numéros corrigés, 307 inscriptions conservées à Lokossa.

Pour l’appliquer à une autre copie vierge de l’export fourni, adapter le compte et le nom de base :

```sh
createdb -U votre_role testasin_reprise
psql -X -U votre_role -v ON_ERROR_STOP=1 -d testasin_reprise -f base_inscriptions.sql
pg_dump -U votre_role -d testasin_reprise -Fc -f avant_reparation.dump
psql -X -U votre_role -v ON_ERROR_STOP=1 -d testasin_reprise -f database/reparer_inscriptions.sql
# Rejeu : les données et les archives restent identiques.
psql -X -U votre_role -v ON_ERROR_STOP=1 -d testasin_reprise -f database/reparer_inscriptions.sql
```

Exécuter en fenêtre de maintenance : le script utilise une transaction et des verrous exclusifs. Un conflit de verrou de plus de 5 secondes ou une erreur interrompt la réparation ; `ON_ERROR_STOP` impose l’arrêt de `psql`. Le `search_path` doit désigner le schéma contenant les quatre tables du sujet, habituellement `public`. Ne pas combiner ce script avec `migrate:fresh`. Les archives et la photographie de Lokossa sont conservées dans la même base.

Contrôler les résultats et consulter la liste d’arbitrage :

```sh
psql -X -U votre_role -d testasin_reprise -c 'SELECT code, capacite, nb_inscrits FROM centres ORDER BY code'
psql -X -U votre_role -d testasin_reprise -c 'SELECT * FROM reparation_numeros_table ORDER BY inscription_id'
psql -X -U votre_role -d testasin_reprise -c 'SELECT inscription_id, inscription_conservee_id FROM reparation_doublons_inscriptions ORDER BY inscription_id'
psql -X -U votre_role -d testasin_reprise -c "\copy (SELECT * FROM reparation_lokossa_arbitrage ORDER BY rang_chronologique) TO 'lokossa_arbitrage.csv' WITH (FORMAT csv, HEADER true, ENCODING 'UTF8')"
```

La liste CSV contient des données nominatives : la conserver dans un emplacement privé. La liste issue de l’exécution locale et les sauvegardes se trouvent dans `storage/app/private/reparations/`, ignoré par Git. Pour restaurer l’état local d’avant la reprise dans **une nouvelle base**, utiliser `createdb -U votre_role testasin_avant_reprise`, puis `pg_restore -U votre_role -d testasin_avant_reprise --no-owner --no-privileges storage/app/private/reparations/avant_consigne5.dump`.

Les tests SQL nécessitent PostgreSQL et un compte autorisé à créer des schémas. Le nom de base est obligatoire pour les activer ; ils créent puis suppriment leurs propres schémas, sans toucher aux tables importées :

```sh
REPAIR_TEST_DATABASE=testasin php artisan test --compact tests/Feature/RepairInscriptionsTest.php
```

Les paramètres de connexion proviennent de la configuration PostgreSQL Laravel, avec `REPAIR_TEST_DATABASE` comme nom de base. Sans cette variable, les tests SQL sont signalés comme ignorés. Pour tout vérifier, y compris les tests SQL :

```sh
REPAIR_TEST_DATABASE=testasin php artisan test --compact
```

Pour vérifier aussi la réparation et le rejeu sur l’export original du sujet, avec contrôle de son empreinte et conservation des candidats et de toutes les inscriptions de Lokossa :

```sh
REPAIR_TEST_DATABASE=testasin REPAIR_TEST_EXPORT=base_inscriptions.sql php artisan test --compact
```

### Consigne 6 — Rapport d’incident

Le [rapport d’incident INC-201](RAPPORT_INCIDENT_INC-201.md) présente la chronologie reconstituée, la cause racine, l’impact chiffré, les corrections et les actions préventives. Il explique les liens avec INC-202, INC-203 et INC-206, et distingue les résultats vérifiés localement du déploiement et des communications en production, qui ne sont pas attestés.

Les commandes de reproduction des preuves et de validation sont indiquées dans le rapport.

### Bonus — Incident non signalé

Le document [INCIDENT_NON_SIGNALE.md](INCIDENT_NON_SIGNALE.md) décrit l’injection SQL observée sur la recherche par nom, les données potentiellement exposées, les limites des preuves et les mesures immédiates proposées. Les actions de confinement, de correction et de notification y sont distinguées des constats du journal.

### Vérification générale

```sh
php artisan db:show
php artisan test
```

La base importée contient les tables `concours`, `centres`, `candidats` et `inscriptions`. Ne pas exécuter `migrate:fresh` sur cette base : cette commande supprimerait les données du sujet.
