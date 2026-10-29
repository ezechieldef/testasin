# Réparation des données — Consigne 5

## Source et exécution

Le script [database/reparer_inscriptions.sql](database/reparer_inscriptions.sql) cible PostgreSQL et les tables de l’export du sujet. Le fichier original `base_inscriptions.sql`, fourni séparément et exclu du dépôt parce qu’il contient des données nominatives, porte l’empreinte SHA-256 `f4e5771ff0dda8d7d0091d97c6456f2a1f43e4ce74c713c480c5e9a317290a37`. Il a été importé dans un schéma PostgreSQL isolé pour vérifier la réparation et son rejeu directement sur la source du sujet.

Avant toute modification, une sauvegarde complète a été créée dans `storage/app/private/reparations/avant_consigne5.dump`, au format `pg_dump` personnalisé. Son SHA-256 est `cfa973de4d7c2abdeed8e2fe38bef80d445a4d883fe6daf8c9e6cabdb24a77e4`. Les données nominatives et les sauvegardes restent dans le stockage privé ignoré par Git.

La sauvegarde a été restaurée dans une base isolée pour valider le script et son rejeu. Après ces contrôles, le script a été appliqué deux fois à `testasin`. Les empreintes des inscriptions et des deux tables d’audit sont identiques avant et après le second passage. Les journaux d’exécution, empreintes et sauvegarde après réparation sont conservés dans le même répertoire privé.

Le test exécuté sur l’export original confirme les mêmes volumes de reprise : 6 400 inscriptions avant, 6 393 après, 7 doublons archivés et 15 numéros réattribués. Il vérifie aussi que les candidats restent inchangés, que les 307 identifiants d’inscription de Lokossa sont conservés avec une capacité de 300, et que le second passage conserve les données et les audits à l’identique.

## Résultats constatés

| Élément | Avant | Après |
| --- | ---: | ---: |
| Inscriptions | 6 400 | 6 393 |
| Inscriptions excédentaires pour un même NPI et concours | 7 | 0 |
| Groupes de numéros de table réutilisés | 10 | 0 |
| Inscriptions conservées ayant changé de numéro | — | 15 |
| Inscriptions de Lokossa | 307 | 307 |
| Capacité de Lokossa | 300 | 300 |

| Centre | Inscriptions avant | Inscriptions après | Doublons retirés et archivés | Numéros changés |
| --- | ---: | ---: | ---: | ---: |
| Abomey | 509 | 509 | 0 | 0 |
| Cotonou | 4 187 | 4 181 | 6 | 5 |
| Lokossa | 307 | 307 | 0 | 9 |
| Porto-Novo | 783 | 782 | 1 | 0 |
| Parakou | 614 | 614 | 0 | 1 |

Vingt NPI distincts sont concernés par un retrait de doublon ou une renumérotation. Ces populations se recoupent : les 7 retraits et les 15 renumérotations ne correspondent pas à 22 personnes distinctes.

## Politique de réparation et traçabilité

Pour chaque couple NPI–concours, la première inscription enregistrée est conservée, selon `cree_le`, puis `id` en cas d’égalité. Les 7 autres lignes sont copiées intégralement en JSON dans `reparation_doublons_inscriptions`, avec l’identifiant de l’inscription conservée et la date de réparation, puis retirées de la table active. Aucun candidat n’est supprimé. Si une double candidature implique Lokossa, le script s’arrête au lieu de supprimer une inscription de ce centre.

Cette déduplication précède la renumérotation pour éviter d’attribuer un nouveau numéro à une ligne qui sera retirée. Parmi les inscriptions conservées partageant un numéro, la première garde ce numéro. Les autres reçoivent des numéros au-delà du maximum de leur centre, tous concours confondus. Il n’y a pas de renumérotation générale. `reparation_numeros_table` conserve la correspondance entre identifiant, ancien numéro et nouveau numéro.

Le script forme une seule transaction, avec verrou exclusif des quatre tables, délai d’acquisition de 5 secondes et délai par instruction de 60 secondes. Une erreur annule la transaction. Les cas qui nécessitent un arbitrage, notamment des identités candidates dupliquées, des numéros mal formés ou deux concours différents le même jour pour un même NPI, provoquent un arrêt. Une référence candidat manquante fait également échouer toute la réparation lors de l’ajout de la clé étrangère.

Le rejeu conserve les archives et leurs dates, ne retire aucun nouveau doublon et n’attribue aucun nouveau numéro sur l’état réparé. Les séquences d’identifiants sont initialisées au-delà des identifiants actifs **et archivés**, sans recul lors du rejeu : une nouvelle inscription ne peut pas réutiliser l’identifiant d’un doublon retiré.

## Protections ajoutées

- Unicité du NPI dans `candidats`, du couple NPI–concours et du couple centre–numéro dans `inscriptions` ; codes de centres et de concours uniques.
- Clé étrangère reliant l’inscription au candidat par son NPI.
- Date d’épreuve recopiée automatiquement depuis le concours, rendue obligatoire et liée à celui-ci par une clé étrangère composite ; unicité NPI–date d’épreuve, y compris entre centres différents. Un changement de date d’un concours ayant déjà des inscrits exige un traitement explicite, car la clé étrangère empêche une désynchronisation.
- Compteur `centres.nb_inscrits` initialisé avec les effectifs réels. Un déclencheur valide atomiquement une place dans la transaction de chaque insertion ou changement de centre, et libère la place lors d’un retrait. Une opération refusée annule aussi l’écriture de l’inscription. Le compteur n’est incrémenté que pour une insertion réelle, y compris si une instruction utilise `ON CONFLICT`. Les inscriptions en attente consomment une place.

Le compteur de Lokossa reste égal à 307, sans modifier sa capacité de 300. Le script ne crée pas de contrainte `nb_inscrits <= capacite` qui rendrait impossible la conservation de cet état historique ; il refuse les nouvelles admissions tant que le centre ne dispose pas d’une place. Une baisse de capacité ou une modification administrative du compteur doit rester une opération contrôlée ; le compteur est entretenu par le déclencheur des inscriptions, pas par une saisie manuelle.

L’export ne définissait pas de génération automatique pour les identifiants des candidats et inscriptions. Le script ajoute les identités nécessaires pour permettre au service d’inscription de la consigne 4 de continuer à créer des lignes.

## Lokossa : liste et décision à demander

La vue `reparation_lokossa_arbitrage` contient les **307 inscriptions**, avec identifiant, NPI, nom, prénoms, concours, ancien et nouveau numéro, date d’inscription en heure du Bénin, capacité et dépassement. La photographie initiale reste dans `reparation_lokossa_initiale`. La liste complète a été exportée dans `storage/app/private/reparations/lokossa_arbitrage.csv` ; elle ne doit pas être publiée dans le dépôt.

**Décision demandée à la direction des concours, avec le chef de centre et la logistique : comment accueillir les 307 candidats pour 300 places déclarées, soit 7 places à organiser ?**

1. Faire confirmer si 7 places supplémentaires peuvent être ouvertes à Lokossa avec salles, sécurité et surveillance suffisantes. La capacité ne sera portée à au moins 307 qu’après validation écrite de cette possibilité.
2. Si cette extension est impossible, faire approuver un transfert de 7 candidats vers des centres ayant des places disponibles, ainsi que les critères de sélection, les conditions de transport et les modalités de notification. Les destinations devront être vérifiées au moment de la décision.
3. Valider les affectations, mettre à jour les listes et rééditer les convocations concernées. Aucun transfert, changement de capacité ni retrait de candidature n’a été effectué par le script.

Le rang chronologique fourni dans la liste sert à l’examen du dossier ; il ne désigne pas automatiquement les sept personnes à transférer. Une sélection arbitraire des sept derniers inscrits n’est pas une réparation technique autorisée.

## Personnes à prévenir et actions à organiser

| Destinataire | Information et action attendue |
| --- | --- |
| Direction des concours | Bilan de reprise, validation de la politique de conservation des premières inscriptions, décision écrite sur les 7 places manquantes à Lokossa. |
| Chef du centre de Lokossa et équipe logistique | Liste sécurisée des 307 inscrits, 9 changements de numéro et faisabilité de l’accueil ou d’un transfert. |
| Chefs des centres de Cotonou, Porto-Novo et Parakou | Listes corrigées, anciennes convocations à remplacer ou doublons à invalider. |
| Support et équipe TMA/exploitation | Correspondances des identifiants et numéros, accompagnement des candidats, réédition des convocations et surveillance des refus de capacité. |
| 20 candidats concernés par la reprise | Confirmation de l’inscription conservée, invalidation de la convocation en double et/ou nouveau numéro. Ne pas communiquer la liste nominative complète. |
| Candidats ultérieurement concernés par la décision sur Lokossa | Notification individuelle de toute nouvelle affectation, uniquement après décision validée. |

Ces destinataires et messages sont préparés ; aucun courriel ni autre notification externe n’a été envoyé dans le cadre de cette consigne.

## Vérifications reproductibles

Le [README](README.md) donne les commandes de sauvegarde, application, rejeu et export de la liste. Les tests [RepairInscriptionsTest](tests/Feature/RepairInscriptionsTest.php) utilisent PostgreSQL dans des schémas temporaires avec une fixture synthétique sans données du sujet. Ils couvrent la conservation de Lokossa, la traçabilité, le rejeu, les contraintes d’unicité, le jour d’épreuve, les séquences, l’annulation complète d’une réparation en erreur, les compteurs lors des transferts et retraits, deux admissions SQL simultanées pour une dernière place et la compatibilité avec l’API de la consigne 4.

Les choix SQL s’appuient sur la documentation PostgreSQL des [contraintes](https://www.postgresql.org/docs/18/ddl-constraints.html), [déclencheurs](https://www.postgresql.org/docs/18/sql-createtrigger.html) et [verrous](https://www.postgresql.org/docs/18/explicit-locking.html).

Pour inclure le contrôle de l’export original dans les tests, placer le fichier fourni à la racine puis lancer :

```sh
REPAIR_TEST_DATABASE=testasin REPAIR_TEST_EXPORT=base_inscriptions.sql php artisan test --compact tests/Feature/RepairInscriptionsTest.php
```

L’import et la réparation de ce test s’effectuent uniquement dans son schéma temporaire. Sans `REPAIR_TEST_EXPORT`, seul ce contrôle du fichier original est ignoré ; les tests sur les fixtures synthétiques restent disponibles.
