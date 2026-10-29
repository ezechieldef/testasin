# Testasin

Application Laravel 13 avec vues Blade, Tailwind CSS et PostgreSQL.

## Prérequis

PHP 8.4 avec `pdo_pgsql`, Composer, Node.js/npm et PostgreSQL.

## Installation

```sh
composer install
cp .env.example .env
php artisan key:generate
npm ci
npm run build
```

Configurer les variables `DB_*` dans `.env`. En local, la base `testasin` a déjà été importée et appartient à `root`.

Les sessions et le cache sont stockés dans des fichiers ; les tâches utilisent le mode synchrone. Aucune migration Laravel n'est nécessaire pour afficher la page d'accueil.

Les instants applicatifs sont conservés en UTC. Les dates destinées aux utilisateurs devront être affichées dans le fuseau `Africa/Porto-Novo`.

## Démarrage

```sh
php artisan serve
```

Ouvrir http://localhost:8000. Pour modifier les styles en direct, lancer `npm run dev` dans un second terminal.

## Vérification

### Consigne 2 — Analyse du journal de production

Depuis la racine du projet :

```sh
php artisan journal:analyser /Users/ezechiel/Projects/testasin/journal_production.log
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

### Application

```sh
php artisan db:show
php artisan test
```

La base importée contient les tables `concours`, `centres`, `candidats` et `inscriptions`. Ne pas exécuter `migrate:fresh` sur cette base : cette commande supprimerait les données du sujet.
