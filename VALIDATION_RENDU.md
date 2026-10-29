# Validation du rendu — Étude de cas TMA DEP/ASIN 2026

**Candidat : DENAKPO EZECHIEL AHOUANDJINOU**

Vérification réalisée le **29 octobre 2026**, avant l’échéance communiquée de **18h30, heure du Bénin**. Les dates de novembre figurant dans les incidents appartiennent au scénario de l’énoncé ; elles ne sont pas les dates des vérifications locales.

## Périmètre et provenance

Le dépôt public [ezechieldef/testasin](https://github.com/ezechieldef/testasin) a été cloné dans un répertoire distinct de l’espace de développement. Le code fonctionnel vérifié correspond au commit `bc7e3517d891199d2086dc9d59b49b895b8f3bd8`. La préparation du rendu ajoute ensuite la présentation du README, des règles d’exclusion des données d’entrée et les documents de validation et d’email, sans modification du code fonctionnel.

Les dépendances ont été installées à partir des fichiers de verrouillage présents dans ce clone, avec `composer install` et `npm ci`.

Le fichier original `base_inscriptions.sql` n’étant pas présent dans l’environnement, la vérification de reprise a utilisé une restauration de la sauvegarde locale **antérieure à la consigne 5**, dans une nouvelle base réservée à cette validation. Son empreinte est :

```text
cfa973de4d7c2abdeed8e2fe38bef80d445a4d883fe6daf8c9e6cabdb24a77e4
```

Cette sauvegarde contient 6 400 inscriptions et le schéma d’origine, dont le seul index des inscriptions est la clé primaire. La procédure d’import du fichier SQL fourni au jury est documentée dans le README, mais l’identité exacte de ce fichier avec la sauvegarde restaurée ne peut pas être vérifiée sans disposer du fichier original.

Le journal réel a été passé au programme par son chemin, sans être ajouté au clone ou au dépôt. Son SHA-256 est `c97fd8d3a35af7eccc573f89291924671f84b40114f94cb380e18dc284295205`.

## Environnement vérifié

| Composant | Version utilisée |
| --- | --- |
| PHP | 8.4.17 |
| Composer | 2.9.4 |
| Laravel | 13.39.0, verrouillé dans `composer.lock` |
| PHPUnit | 12.5.42, verrouillé dans `composer.lock` |
| PostgreSQL et outils clients | 18.6 |
| Node.js | 25.6.0 |
| npm | 11.8.0 |

`composer check-platform-reqs` réussit dans cet environnement. Les connexions PostgreSQL de test utilisent des schémas temporaires ; les tests fonctionnels ordinaires utilisent SQLite.

## Résultats de validation

| Vérification depuis le clone neuf | Résultat |
| --- | --- |
| Installation Composer depuis le verrouillage | Réussie. |
| Installation npm depuis le verrouillage | Réussie. |
| Génération de la clé et compilation `npm run build` | Réussies. |
| Suite complète avec tests SQL activés | **95 tests réussis, 401 assertions, aucun test ignoré.** |
| Tests de concurrence réexécutés avec PostgreSQL | **3 tests réussis, 34 assertions.** |
| Analyse du journal réel | 10 numéros réutilisés, 7 doubles candidatures, 60 requêtes touchées par une identification lente, 147 refus de clôture injustifiés pour 100 NPI distincts et 36 refus d’âge injustifiés ; statistiques des listes pour les 5 centres. |
| Diagnostic avec la base avant réparation | Réussit ; les effectifs et les index correspondent à l’état historique décrit dans l’analyse. |
| Réparation de la copie restaurée | Réussit : **6 400 → 6 393 inscriptions**, **7 doublons archivés et retirés**, **15 numéros réattribués**. |
| Contrôle des unicités après réparation | **0 groupe** de numéros multiples et **0 groupe** de doubles candidatures. |
| Contrôle des compteurs de capacité | **0 compteur incohérent** avec les inscriptions actives. |
| Conservation de Lokossa | **307 inscriptions conservées, capacité inchangée à 300.** |
| Rejeu du script sur l’état réparé | Empreintes identiques pour les 4 tables métier et les 3 tables d’audit / photographie contrôlées. |

Les trois tables supplémentaires contrôlées lors du rejeu sont `reparation_doublons_inscriptions`, `reparation_numeros_table` et `reparation_lokossa_initiale`. Les empreintes portent sur les lignes triées par identifiant, y compris les dates d’audit. Les tests SQL vérifient également les séquences et les échecs transactionnels.

Les commandes centrales sont reproductibles avec une connexion adaptée à un environnement de test :

```sh
REPAIR_TEST_DATABASE=nom_de_base_de_test php artisan test --compact
DB_CONNECTION=pgsql DB_DATABASE=nom_de_base_de_test php artisan test --compact tests/Feature/ConcurrentInscriptionTest.php
php artisan journal:analyser /chemin/vers/journal_production.log --json
php artisan incidents:diagnostiquer /chemin/vers/journal_production.log --base --json
```

Pour `--base`, configurer `.env` vers la copie à examiner. Lancer le diagnostic avant réparation pour retrouver les index et effectifs historiques ; après réparation, les constats de la base reflètent naturellement l’état corrigé, tandis que ceux du journal restent historiques.

## Inventaire du dépôt et cohérence documentaire

- Les livrables des six consignes et le bonus sont présents et reliés depuis le [README](README.md).
- Le premier commit, `6fc40f5ceec7be997c03ee50d0babfb6af0cda97`, contient uniquement `INTERVENTION.md`. L’historique des étapes est conservé.
- Le dépôt est public : les membres du jury peuvent le consulter et le cloner sans invitation individuelle.
- Les fichiers `.env`, dépendances installées, journaux réels, sauvegardes et listes nominatives CSV ne sont pas versionnés. Les motifs de clés privées et de jetons vérifiés dans les fichiers suivis n’ont produit aucun résultat.
- Les chiffres des documents distinguent **25 NPI initialement touchés par les collisions**, **20 NPI dont les données ont été modifiées**, **7 retraits de doublons** et **15 réattributions**. Ils ne sont pas additionnés comme des personnes distinctes.
- Les limites restent explicites : attente de vérification à reprendre ultérieurement, arbitrage des 7 places de Lokossa, absence de déploiement et de notifications en production attestés, exposition liée à l’injection à investiguer.

Les sauvegardes, journaux techniques de reprise et exports nominatifs restent en stockage privé. Ce document fournit les résultats nécessaires à la lecture du rendu ; le jury peut relancer les programmes sur les fichiers qui lui ont été fournis.

## Transmission du rendu

Le [brouillon d’email](EMAIL_RENDU.md) reprend les destinataires et l’objet de l’énoncé avec le nom du candidat. **L’envoi est effectué par le candidat**, avant 18h30 heure du Bénin. Il faut conserver la confirmation d’envoi et vérifier que la branche distante contient les derniers commits avant cette échéance.
