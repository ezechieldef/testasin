# Incident non signalé — Injection SQL et exposition possible de données personnelles

## Synthèse et priorité

**Qualification : tentative d’injection SQL avérée, avec de forts indices d’exploitation réussie et d’extraction de données personnelles.** Cet incident répond au bonus de l’étude de cas et ne fait pas partie des huit signalements initiaux.

Le 20 novembre 2026, une même adresse IP enchaîne une recherche ordinaire, une apostrophe provoquant une erreur SQL, une condition toujours vraie, puis deux injections `UNION SELECT` visant les NPI, noms et dates de naissance de la table `candidats`. Les deux dernières requêtes obtiennent HTTP 200 et le journal indique **18 535 lignes pour chacune**.

**Priorité proposée : P1 — critique**, à prendre en charge immédiatement tant que l’endpoint peut encore exposer des données. Cette priorité doit être intégrée à la réévaluation du classement initial d’[INTERVENTION.md](INTERVENTION.md), établi avant découverte de cet incident. Il ne faut pas réécrire ce classement historique comme si la découverte était connue dès le début.

**État : constat documentaire.** Les mesures ci-dessous sont proposées ; ce document n’atteste ni une restriction de l’endpoint d’origine, ni une correction déployée, ni une notification envoyée.

## Sources et preuves reproductibles

- [Étude de cas](etude_de_cas_pratique.md), section 3 : la recherche par nom interroge `candidats`, avec 50 résultats au plus ; cette table conserve aussi les candidats des campagnes précédentes.
- `journal_production.log`, lignes **7873 à 7877** pour la séquence suspecte, et lignes 747, 2529, 3845, 4044, 5766, 7447 et 7873 pour les recherches ordinaires.
- Empreinte SHA-256 du journal : `c97fd8d3a35af7eccc573f89291924671f84b40114f94cb380e18dc284295205`.

Retrouver les événements sans rejouer les requêtes contre l’application :

```sh
rg -n '/api/inscrits/recherche' journal_production.log
php artisan journal:tracer journal_production.log --requete=1e91b46a
php artisan journal:tracer journal_production.log --requete=d1479185 --json
php artisan journal:tracer journal_production.log --requete=5c783014 --json
```

Les commandes consultent uniquement le fichier local. Les identifiants et numéros de ligne permettent de contrôler les constats sans publier le journal complet ni les données personnelles de la base.

## Chronologie observée

Les horaires suivants sont des **fins de traitement en heure du Bénin, UTC+01:00**, obtenues à partir des horodatages UTC du journal. Ils ne sont pas les heures de début des demandes.

Toutes les requêtes de cette séquence portent l’adresse IP **102.64.217.33**. Celle-ci constitue un indice de corrélation, pas l’identité d’une personne.

| Date et heure de fin au Bénin | Ligne | Requête | Paramètre `nom` décodé / comportement | Réponse observée |
| --- | --- | --- | --- | --- |
| 20/11/2026, 04:12:40.580 | 7873 | `4fd4b53c` | `KPADONOU` : recherche ordinaire. | HTTP 200, 88 ms, `lignes=390`. |
| 20/11/2026, 04:13:05.084 | 7874 | `1e91b46a` | Une apostrophe seule, encodée `%27`. | HTTP 500, 75 ms ; erreur PostgreSQL `SQLSTATE[42601]`, chaîne entre apostrophes non terminée. |
| 20/11/2026, 04:13:27.960 | 7875 | `578397d9` | `' OR '1'='1` : tentative de modifier la condition SQL. | HTTP 200, 1 328 ms, `lignes=50`. |
| 20/11/2026, 04:13:50.708 | 7876 | `d1479185` | `' UNION SELECT npi, nom, date_naissance FROM candidats --` | HTTP 200, 1 103 ms, `lignes=18535`. |
| 20/11/2026, 04:14:16.689 | 7877 | `5c783014` | Répétition du même `UNION SELECT`. | HTTP 200, 1 061 ms, `lignes=18535`. |

Cette progression est cohérente avec une reconnaissance suivie d’une exploitation : test d’une apostrophe, tentative de modification du filtre, puis sélection de colonnes personnelles et répétition de la demande. L’erreur seule ne suffirait pas à prouver une extraction ; les deux réponses réussies et leur volume renforcent fortement cette hypothèse.

## Cause probable et anomalies associées

**Cause probable : construction de la requête SQL de recherche en incorporant directement le paramètre `nom`, sans liaison de paramètres adaptée.** Une donnée utilisateur peut alors modifier la structure de la requête au lieu d’être traitée comme une simple valeur de recherche.

Cette conclusion est étayée par l’erreur de syntaxe provoquée par l’apostrophe, puis par les réponses aux charges SQL. Le code source d’origine n’est pas fourni : l’emplacement exact de la concaténation et la requête SQL complète restent à vérifier.

Le commentaire SQL `--` de la dernière charge vise à neutraliser la suite de la requête. L’erreur précédente mentionne un fragment contenant `LIMIT 50`, alors que les deux appels `UNION` annoncent 18 535 lignes : ces éléments sont cohérents avec un contournement de la requête et de sa limitation, sans permettre de reconstruire avec certitude toute sa syntaxe.

**Anomalie supplémentaire :** sept recherches ordinaires annoncent déjà entre **336 et 419 lignes**, malgré la limite de 50 résultats décrite dans le dossier. Il faut vérifier la signification exacte du champ `lignes` et l’endroit où la limite est appliquée. Si ce champ représente les résultats retournés au client, la limitation est aussi défaillante hors injection.

La présence de l’erreur détaillée dans le journal ne prouve pas qu’elle a été affichée au client : le contenu de la réponse HTTP 500 n’est pas fourni. Ce point doit être vérifié avant de conclure à une fuite de détails techniques par la réponse d’erreur.

## Données qui ont pu être exposées

| Élément | Évaluation fondée sur les traces |
| --- | --- |
| Champs explicitement ciblés | **NPI, nom et date de naissance**, nommés dans le `UNION SELECT`. |
| Population potentiellement concernée | Candidats présents dans `candidats`, y compris ceux des campagnes précédentes ; le périmètre potentiel dépasse les seuls inscrits de la campagne actuelle. |
| Volume observable | Deux réponses HTTP 200 portant chacune `lignes=18535`. Cela constitue un fort indice d’extraction volumineuse. |
| Nombre de personnes distinctes | **Non établi.** Les deux requêtes identiques peuvent retourner largement les mêmes données ; il ne faut pas annoncer 37 070 personnes exposées ni assimiler automatiquement 18 535 lignes à autant de personnes distinctes. |
| Réception complète par le demandeur | Non démontrée : les corps de réponse, octets transmis et éventuelles interruptions de transfert ne sont pas disponibles dans ce journal. |
| Autres données ou opérations | Aucune preuve ici de lecture d’autres tables, de mots de passe, de modification, de suppression ou de prise de contrôle du serveur. Leur absence ne peut toutefois pas être garantie sans investigation complémentaire. |

Le risque porte sur la **confidentialité de données permettant d’identifier des candidats**. Le périmètre réel dépend également des droits du compte SQL, des contrôles d’accès à la recherche et des autres requêtes qui ont pu être exécutées. Le journal fourni ne permet pas de déterminer si les demandes étaient authentifiées ou autorisées.

## Mesures immédiates proposées

| Ordre | Action | Responsable proposé | Résultat attendu |
| --- | --- | --- | --- |
| 1 | Désactiver temporairement la recherche vulnérable ou en restreindre l’accès à un périmètre maîtrisé. Un filtrage des charges peut compléter ce confinement, sans se substituer à la correction. | Exploitation et responsable sécurité | Arrêt de l’exposition par ce point d’entrée, avec maintien des fonctions non concernées si possible. |
| 2 | Préserver une copie horodatée et les empreintes des journaux applicatifs, proxy, authentification et base disponibles ; conserver les configurations utiles et limiter l’accès aux preuves. | Exploitation et administrateur de base | Investigation possible sans altération ni publication des éléments sensibles. |
| 3 | Alerter la direction des concours, le référent TMA, le responsable sécurité et le référent chargé des données personnelles. | Responsable de l’incident | Décisions de confinement, d’investigation et de communication coordonnées. Aucun message n’est réputé envoyé ici. |
| 4 | Rechercher les mêmes charges, l’IP et d’autres sources, les requêtes volumineuses et les erreurs SQL sur une période plus large ; corréler avec les accès et les volumes effectivement transmis. | Équipe sécurité et TMA | Délimitation des données consultées, des accès et de la durée de l’exposition, sans se limiter à une seule IP. |
| 5 | Remplacer toute interpolation de valeurs par des requêtes paramétrées ; vérifier les autres requêtes construites de la même manière. | Développement | Les entrées contenant des apostrophes ou du SQL restent des valeurs, sans modifier la structure SQL. |
| 6 | Vérifier les autorisations et les champs accessibles ; faire respecter la limite de résultats côté serveur ; réduire les privilèges du compte SQL au nécessaire. | Développement, sécurité et administrateur de base | Limitation de la surface et du volume de données accessibles. |
| 7 | Vérifier que les réponses d’erreur ne divulguent pas de requêtes ou détails SQL ; conserver les détails utiles uniquement dans les journaux protégés. | Développement et exploitation | Erreurs exploitables par l’équipe technique, sans révélation inutile au client. |
| 8 | Établir le périmètre confirmé et faire évaluer les notifications nécessaires par les responsables compétents. | Direction et référent chargé des données personnelles | Information proportionnée aux faits établis ; aucun délai réglementaire n’est supposé dans ce document. |

La désactivation ou le blocage de la seule IP observée ne suffit pas à corriger la vulnérabilité. Une limitation à 50 lignes réduit le volume d’une réponse, mais ne remplace ni la paramétrisation SQL ni le contrôle des droits et des champs accessibles.

## Conditions de réouverture et suivi

Avant de réouvrir la recherche sur l’environnement concerné :

- Vérifier dans le code que les valeurs utilisateur sont liées comme paramètres, sans concaténation SQL.
- Exécuter des tests automatisés couvrant recherche légitime avec apostrophe, charges SQL observées, droits d’accès, champs retournés, limite de 50 résultats et réponses d’erreur.
- Confirmer que la correction est déployée et vérifier les réponses réelles dans un environnement de validation contrôlé.
- Mettre en place une surveillance des erreurs SQL, des charges suspectes et des volumes anormaux ; définir un responsable du suivi des données potentiellement exposées.

Cet incident concerne la confidentialité et doit être suivi séparément d’INC-201 à INC-207, qui portent sur l’intégrité et la disponibilité. Aucun élément ne démontre que l’injection a causé les collisions, les dépassements de capacité ou les erreurs d’âge et de clôture. La priorité P1 proposée repose sur son propre risque d’exposition.

## Référence technique

Les recommandations de paramétrisation, de séparation entre structure SQL et valeurs, et de moindre privilège s’appuient sur la fiche officielle [OWASP — SQL Injection Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/SQL_Injection_Prevention_Cheat_Sheet.html). La limitation des résultats et les contrôles d’accès complètent ces protections ; ils ne les remplacent pas.
