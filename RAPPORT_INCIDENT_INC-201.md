# Rapport d’incident INC-201 — Numéros de table attribués plusieurs fois

## Synthèse et état de l’intervention

**Incident retenu pour la consigne 6 : INC-201.** Le chef du centre de Parakou signale que deux candidats ont reçu le numéro P-0464. Le journal confirme cette collision et révèle neuf autres numéros réutilisés dans trois autres centres.

La cause racine est une allocation de numéro non atomique : le maximum du centre est lu, puis le service d’identité est appelé, avant que l’inscription soit enregistrée. Plusieurs demandes peuvent donc retenir le même prochain numéro. L’absence de contrainte unique laisse ces collisions persister. La lenteur du service d’identité agrandit la fenêtre de concurrence ; elle n’est pas nécessaire au déclenchement du défaut.

| Élément | État constaté |
| --- | --- |
| Priorité | **P2 — majeure**, conformément à [INTERVENTION.md](INTERVENTION.md) : intégrité de l’identification des candidats dans les centres. |
| Impact observé dans le journal | **10 numéros réutilisés, 32 créations concernées, 25 NPI distincts, 4 centres.** |
| Correction applicative | Implémentée dans le projet ; tests fonctionnels et simultanés vérifiés localement, dont les tests de concurrence sur PostgreSQL. |
| Reprise des données locales | Audits présents : **7 doubles inscriptions retirées après archivage et 15 numéros réattribués** ; aucun groupe de numéros ou de candidatures en double ne subsiste dans la base locale consultée. |
| État en production | Déploiement, information des candidats et réédition des convocations **non attestés** par les éléments disponibles ; aucune clôture opérationnelle de l’incident n’est déclarée. |

Les dates de novembre 2026 ci-dessous sont celles du scénario fourni. Les vérifications de correction concernent l’environnement local de l’exercice ; elles ne constituent pas une chronologie de déploiement en production.

## Sources et méthode

Sources : [étude de cas, sections 2 à 4](etude_de_cas_pratique.md), `journal_production.log`, [analyse des incidents](ANALYSE_INCIDENTS.md), code de correction, [bilan de reprise](REPARATION_DONNEES.md), script SQL et tables d’audit de la base locale configurée.

Le journal couvre la période annoncée du 18 novembre 2026 à 01:00 au 21 novembre à 08:00, heure du Bénin. Son empreinte SHA-256 est :

```text
c97fd8d3a35af7eccc573f89291924671f84b40114f94cb380e18dc284295205
```

Tous les horaires du rapport sont en **heure du Bénin, UTC+01:00**. Les événements du journal sont horodatés en fin de traitement ; les débuts sont reconstruits en soustrayant leur durée. Les inscriptions réussies sont les réponses HTTP 201, regroupées par centre et numéro, tous concours confondus.

Commandes permettant de reproduire les constats :

```sh
php artisan journal:analyser journal_production.log --json
php artisan incidents:diagnostiquer journal_production.log --incident=INC-201 --json
php artisan journal:tracer journal_production.log --centre=P --numero=P-0464
```

Les numéros de ligne cités correspondent au journal portant cette empreinte. Les valeurs lues par les requêtes SQL d’origine ne sont pas journalisées : le mécanisme causal est établi en croisant les chevauchements observés et le déroulement décrit dans le dossier technique.

## Chronologie reconstituée

| Date et heure au Bénin | Événement | Preuve / interprétation |
| --- | --- | --- |
| 18/11, 16:42:07.318 | Début d’une inscription recevant N-0574. | `da7305cf`, ligne HTTP 2388 ; fin à 16:42:07.576. |
| 18/11, 16:42:07.498 | Une seconde demande du même candidat commence avant la fin de la première. | `cd15b304`, ligne HTTP 2390. |
| 18/11, 16:42:07.777 | La seconde demande réussit avec N-0574. | Première collision nouvellement enregistrée identifiable dans la fenêtre analysée. Les deux traitements durent moins d’une seconde : le défaut existe avant la forte lenteur du lendemain. |
| 19/11, 09:09:48.265 | Début reconstruit du premier appel d’identité d’au moins cinq secondes de la période observée. | Début de l’enveloppe des appels lents d’INC-206. |
| 19/11, 09:11:01.871 à 09:12:43.370 | Onze demandes de Lokossa démarrent puis aboutissent avec seulement deux numéros distincts. | Cinq attributions de L-0297 et six de L-0298 ; toutes sont associées à une identification lente. |
| 19/11, 09:21:14.000 | La première demande du cas de Parakou commence. | `a93cfec6`, ligne HTTP 4478 ; appel d’identité de 33 410 ms, ligne 4477. |
| 19/11, 09:21:16.300 | Une autre demande de Parakou commence pendant le traitement de la précédente. | `a061413f`, ligne HTTP 4473 ; appel d’identité de 22 766 ms, ligne 4472. |
| 19/11, 09:21:39.081 | La demande commencée en second réussit la première et reçoit P-0464. | HTTP 201 pour `a061413f`. |
| 19/11, 09:21:47.432 | La demande commencée en premier réussit à son tour avec P-0464. | HTTP 201 pour `a93cfec6` : deux candidats distincts partagent désormais ce numéro. |
| 19/11, 09:34:13.236 | Dernière création observée participant aux groupes de numéros réutilisés. | `3dc725d8`, ligne 4528, numéro C-3313. Cette heure ne marque pas la réparation des données. |
| 19/11, 09:39:49.805 | Fin du dernier appel d’identité lent de l’enveloppe observée. | Fin d’une période de lenteurs, sans preuve d’un arrêt continu de toute la plateforme. |
| 21/11, 08:00 | Instant de l’export annoncé dans l’énoncé. | Point de référence documentaire pour la reprise, distinct de l’état local après réparation. |

L’heure exacte du signalement du chef de centre, celle du déploiement correctif et celle d’une éventuelle résolution en production ne sont pas fournies. Les délais de détection et de rétablissement ne peuvent donc pas être calculés de façon fiable.

## Impact chiffré

### Périmètre du journal avant réparation

| Centre | Numéros réutilisés | Créations concernées | NPI distincts concernés | Attributions au-delà d’une par numéro |
| --- | --- | ---: | ---: | ---: |
| Cotonou, C | C-3305, C-3306, C-3308, C-3309, C-3311, C-3313 | 17 | 11 | 11 |
| Lokossa, L | L-0297, L-0298 | 11 | 11 | 9 |
| Centre N | N-0574 | 2 | 1 | 1 |
| Parakou, P | P-0464 | 2 | 2 | 1 |
| **Total** | **10 numéros** | **32** | **25** | **22** |

Le signalement initial porte sur **deux candidats à Parakou** ; les autres chiffres décrivent l’extension découverte pendant l’analyse. Les 22 attributions excédentaires ne représentent pas 22 candidatures à supprimer : certaines lignes sont des doubles candidatures, d’autres concernent des candidats différents qu’il faut conserver en réattribuant leur numéro.

Les conséquences établies sont la violation de l’unicité des numéros et la présence de références ambiguës pour les candidats concernés. Les risques métier sont un placement incorrect, des convocations ambiguës et des erreurs de rapprochement lors des épreuves. Le journal ne démontre ni erreur effective sur une copie d’examen, ni exclusion d’un candidat à l’entrée d’un centre, ni préjudice sur un résultat.

### Résultat constaté dans la base locale après reprise

Une lecture des tables d’audit et des données actuelles confirme :

- `reparation_doublons_inscriptions` contient **7 inscriptions archivées**, retirées de la table active en conservant une inscription par NPI et concours.
- `reparation_numeros_table` contient **15 réattributions** : 5 à Cotonou, 9 à Lokossa et 1 à Parakou. Le groupe du centre N disparaît par déduplication, sans réattribution nécessaire.
- L’inscription locale **5066** est tracée de **P-0464 vers P-0614**, qui est également son numéro actuel.
- Les recherches de groupes multiples par `(centre_id, numero_table)` et par `(npi, concours_id)` retournent chacune **zéro groupe**.

Les **7 retraits de doublons et 15 réattributions** expliquent les 22 attributions excédentaires observées. Ces opérations sont de natures différentes et conservent la traçabilité des anciennes références.

Le rapprochement des deux audits identifie **20 NPI distincts ayant subi une modification**. Ce chiffre se distingue des 25 NPI touchés par les collisions initiales : certains candidats conservent leur inscription et leur numéro sans modification. Les listes de communication doivent différencier ces populations.

Cette base est un environnement de travail déjà réparé ; les journaux historiques restent inchangés et continueront à révéler les collisions initiales. Le résultat local ne prouve pas que des convocations ont été rééditées ou que des candidats ont été prévenus en production.

## Cause racine et liens entre incidents

Le déroulement d’origine sépare trois opérations qui doivent être cohérentes : lire le prochain numéro, vérifier les conditions d’admission et enregistrer l’inscription. Entre le calcul du numéro et l’écriture, un appel HTTP externe peut durer plusieurs dizaines de secondes.

Dans le cas de Parakou, deux demandes sont simultanément en cours entre 09:21:16.300 et 09:21:39.081. Le dossier explique comment elles peuvent retenir P-0464 avant que l’une ait enregistré son attribution. La base d’origine ne possède aucune unicité sur le couple centre–numéro pour rejeter la seconde écriture.

**Cause racine : allocation non atomique du numéro et absence de protection d’unicité en base. Facteur aggravant : attente du service d’identité entre lecture et écriture.** Les dix groupes de numéros réutilisés comportent des requêtes qui se chevauchent. Le journal ne trace toutefois ni la lecture exacte du maximum ni les instructions de verrouillage de l’ancienne application.

| Incident lié | Lien démontré ou limite |
| --- | --- |
| **INC-206 — Identification lente** | 30 des 32 créations concernées par INC-201 ont un appel d’identité d’au moins cinq secondes. L’attente amplifie la fenêtre de concurrence. La collision du 18 novembre, avec des demandes de moins d’une seconde, confirme qu’elle n’est pas une condition nécessaire. |
| **INC-203 — Doubles candidatures** | Les 14 créations constituant les sept doubles candidatures appartiennent aussi à des groupes de numéros réutilisés. Le contrôle d’existence et l’allocation du numéro souffrent du même défaut de séparation entre contrôle et écriture. Une collision entre deux candidats distincts reste une anomalie différente d’une double candidature. |
| **INC-202 — Capacité de Lokossa** | Les onze créations de Lokossa cumulent identification lente et numéros réutilisés. Le comptage de capacité non atomique permet un dépassement indépendant de l’unicité du numéro. Les 307 NPI distincts pour 300 places montrent que supprimer des doubles candidatures ne résout pas ce cas ; aucune inscription de Lokossa ne doit être supprimée. |

## Correction et validation

### Prévenir de nouvelles collisions

La correction de [RegisterInscription](app/Services/RegisterInscription.php) réalise l’appel d’identité **avant** de prendre les verrous transactionnels. Avec [IdentityClient](app/Services/IdentityClient.php), cet appel dispose d’une limite de cinq secondes, sans répétition ni redirection ; un dépassement conduit à une inscription en attente de vérification.

Dans la transaction PostgreSQL, un verrou par NPI sérialise les demandes d’un même candidat, y compris entre centres. Un verrou exclusif sur le centre protège ensemble le contrôle de capacité, le calcul du prochain numéro et l’écriture. Le numéro « maximum + 1 » est ainsi calculé dans une section protégée, au lieu d’être réservé implicitement pendant l’attente réseau.

La reprise de la base ajoute une contrainte unique `(centre_id, numero_table)` comme protection complémentaire. Les protections NPI–concours et NPI–date d’épreuve couvrent les doubles candidatures et les concours incompatibles. La migration applicative vise une base vierge ; elle ne remplace pas le script de reprise de l’export existant.

### Réparer les données existantes

Le script [reparer_inscriptions.sql](database/reparer_inscriptions.sql) archive puis retire les doubles inscriptions avant de réattribuer les numéros encore en conflit. Il conserve les premières créations selon `cree_le`, avec `id` comme départage stable : la demande commencée la première n’est donc pas nécessairement celle qui garde le numéro. Les réattributions sont conservées dans une table d’audit.

Le traitement est transactionnel et rejouable, avec des contrôles d’arrêt sur les cas ambigus. Les inscriptions de Lokossa sont préservées et une liste d’arbitrage est produite pour la direction. La réattribution de numéros n’augmente pas la capacité d’accueil : les sept places manquantes à Lokossa nécessitent toujours une décision métier.

### Vérifications réalisées

- La revue de la consigne 4 a exécuté **33 tests fonctionnels et concurrents avec succès**, dont un appel à un serveur local volontairement lent.
- Les **3 tests de concurrence exécutés sur PostgreSQL** ont réussi : dernières places d’un centre, double soumission et même NPI dans deux centres pour des épreuves le même jour.
- Pour ce rapport, les **12 tests SQL de réparation sur un schéma PostgreSQL isolé** ont réussi : rejeu, unicités, archivage, conservation de Lokossa, rollback et compatibilité avec l’API corrigée.
- Les contrôles en lecture seule de la base locale réparée confirment zéro groupe de numéros ou de candidatures en double, ainsi que les volumes d’audit présentés ci-dessus.

Commandes de validation, depuis la racine du projet :

```sh
php artisan test --compact tests/Feature/RegisterInscriptionTest.php tests/Feature/ConcurrentInscriptionTest.php
DB_CONNECTION=pgsql DB_DATABASE=testasin php artisan test --compact tests/Feature/ConcurrentInscriptionTest.php
REPAIR_TEST_DATABASE=testasin php artisan test --compact tests/Feature/RepairInscriptionsTest.php
```

Les tests PostgreSQL créent puis suppriment des schémas temporaires ; les variables de connexion doivent correspondre à l’environnement de test. Les résultats ci-dessus ne valent pas validation d’un déploiement en production.

## Actions pour éviter la récidive et conditions de clôture

| Action | Responsable fonctionnel | Échéance / critère de réussite | État attesté |
| --- | --- | --- | --- |
| Déployer le service d’inscription corrigé et vérifier que tous les chemins d’écriture utilisent les mêmes protections. | Référent TMA et exploitation | Avant reprise des écritures ; vérifier la configuration du service d’identité et le comportement concurrent dans l’environnement cible. | Correction testée localement ; déploiement non attesté. |
| Sauvegarder la base cible, appliquer la reprise en fenêtre contrôlée et conserver les audits et résultats de contrôle. | Exploitation / administrateur de base | Avant réédition des convocations ; zéro numéro réutilisé, zéro double candidature, contraintes présentes et rejeu sans changement métier. | Résultat local vérifié ; application à la cible de production non attestée. |
| Rapprocher anciennes et nouvelles références, rééditer les convocations modifiées et informer individuellement les candidats concernés. | Direction des concours, support et responsables des centres C, L, N et P | Avant diffusion des listes définitives ; les listes de placement et convocations doivent porter les mêmes numéros corrigés. | Réalisation non attestée. |
| Arbitrer les sept places manquantes à Lokossa sans supprimer d’inscription. | Direction des concours et responsable du centre de Lokossa | Avant validation du plan d’accueil ; décision tracée sur une capacité supplémentaire ou une réaffectation organisée. | Dépassement maintenu à 307/300 ; décision non attestée. |
| Conserver les tests simultanés dans les contrôles de livraison, sur PostgreSQL. | Équipe de développement | À chaque modification du parcours d’inscription ; mêmes invariants vérifiés avec plusieurs processus. | Tests disponibles et exécutés ; automatisation de livraison à confirmer. |
| Surveiller les durées d’identification, les inscriptions en attente et les violations d’unicité ; vérifier périodiquement numéros, candidatures et capacité. | Exploitation et TMA | À l’ouverture et pendant la campagne ; alerte dès une violation d’unicité ou un nouveau dépassement, suivi des appels atteignant le seuil de cinq secondes. | Surveillance à mettre en place dans l’environnement cible. |

Les interlocuteurs à prévenir sont la direction des concours, l’exploitation, le support, les responsables des quatre centres concernés et les candidats dont les références changent. Le responsable du service national d’identification doit aussi recevoir les éléments de durée utiles à INC-206. Aucune de ces notifications n’est réputée envoyée par la rédaction de ce rapport.

**Clôture proposée uniquement après** validation du déploiement et de la reprise sur la cible, cohérence des convocations et listes définitives, information des personnes concernées et vérification des invariants sous charge simultanée. Le traitement de la capacité de Lokossa reste suivi séparément dans INC-202, avec un arbitrage explicite.
