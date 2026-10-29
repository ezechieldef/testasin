# Plan d’intervention — Inscriptions aux concours administratifs

## Périmètre et méthode

Classement initial établi à partir des signalements et des règles de l’étude de cas, avant toute analyse détaillée du journal ou de l’export SQL. Les impacts sont déclarés et restent à confirmer ; aucune cause racine n’est considérée comme démontrée à ce stade.

La gravité mesure les conséquences pour le service et les candidats. L’urgence mesure le délai acceptable avant intervention. L’ordre de traitement combine ces deux dimensions : disponibilité des fonctions essentielles, équité d’accès, intégrité des inscriptions, risque d’aggravation et échéances des concours.

Échelle propre à cette intervention : **P1 — critique**, prise en charge immédiate ; **P2 — majeure**, traitement prioritaire pendant l’intervention ; **P3 — modérée**, après sécurisation des fonctions et des données ; **conforme**, sans correction fonctionnelle.

## Classement initial

| Rang | Incident | Gravité | Urgence | Priorité | Justification du rang |
| --- | --- | --- | --- | --- | --- |
| 1 | **INC-206 — Blocage des inscriptions et des convocations** | Critique | Immédiate : vérifier le rétablissement et le risque de récidive | **P1** | Le blocage général signalé pendant trente minutes touche deux fonctions essentielles et pourrait empêcher l’ensemble des candidats de terminer leurs démarches s’il se reproduit. |
| 2 | **INC-204 — Clôture anticipée du Trésor** | Majeure | Immédiate pour la prise en charge métier | **P1** | La fermeture signalée une heure avant l’échéance officielle a potentiellement privé des candidats de leur droit à s’inscrire ; leur identification et une décision de rattrapage ne doivent pas attendre la seule correction technique. |
| 3 | **INC-203 — Double inscription après une seconde validation** | Majeure | Élevée | **P2** | Les doubles inscriptions compromettent l’unicité des candidatures et peuvent consommer indûment des places ; chaque nouvelle tentative risque d’aggraver les données. |
| 4 | **INC-201 — Numéro de table P-0464 attribué plusieurs fois** | Majeure | Élevée | **P2** | Un numéro partagé rend l’identification dans le centre ambiguë et menace le placement ainsi que le traitement des épreuves ; prévenir les nouvelles collisions précède la réattribution contrôlée des numéros. |
| 5 | **INC-202 — Dépassement de capacité à Lokossa** | Majeure | Élevée | **P2** | Les 307 inscrits signalés pour 300 places représentent un dépassement apparent de 7 places ; il faut empêcher son aggravation et obtenir un arbitrage d’accueil sans supprimer d’inscription. |
| 6 | **INC-205 — Refus injustifié lié à l’âge** | Majeure | Élevée | **P2** | Le candidat né le 20 juin 1991 a bien 35 ans au 31 décembre 2026 ; son refus contrevient à la règle et peut concerner d’autres candidats éligibles, à retrouver et à prendre en charge. |
| 7 | **INC-207 — Liste des inscrits de Cotonou trop lente** | Modérée à ce stade | À traiter après les blocages et l’intégrité | **P3** | Le délai signalé de plus de 40 secondes dépasse largement l’objectif de moins de 2 secondes, mais aucun blocage des inscriptions ni dommage aux données n’est établi par ce signalement. |
| 8 | **INC-208 — Refus de deux concours ayant lieu le même jour** | Aucune anomalie | Information du candidat | **Conforme** | Le refus respecte explicitement l’interdiction de s’inscrire à deux concours dont les épreuves ont lieu le même jour ; expliquer la règle puis clôturer le signalement sans modifier ce contrôle. |

## Conditions de réévaluation

- **INC-206 est un événement passé, pas une panne actuelle démontrée.** Vérifier immédiatement la disponibilité ; si le service est rétabli et le risque contenu, les incidents encore actifs passent devant son analyse approfondie.
- **INC-204 concerne une clôture déjà passée dans le scénario.** La direction des concours doit décider des modalités de rattrapage ; l’équipe technique identifie les refus injustifiés et prépare les éléments nécessaires, sans rouvrir unilatéralement les inscriptions.
- **INC-203, INC-201 et INC-202 forment un groupe à examiner conjointement.** Une origine commune liée aux inscriptions simultanées est une hypothèse à vérifier. Les chiffres bruts du centre de Lokossa devront être rapprochés du nombre de candidatures distinctes.
- **INC-205 remonte en priorité si de nombreux candidats sont touchés ou si une clôture est imminente.** Les rangs départagent des urgences proches ; ils ne dispensent pas de traiter les exclusions injustifiées pendant l’intervention.
- **INC-207 remonte en priorité si sa lenteur sature le serveur ou bloque d’autres fonctions.** Toute découverte d’une exposition de données déclenche également une réévaluation immédiate selon son étendue et son caractère actif.

## Premières mesures et interlocuteurs

1. Préserver le journal et l’export d’origine avant toute réparation ; travailler sur une copie pour les analyses et essais.
2. Vérifier la disponibilité avec l’exploitation et informer le support de l’état du service.
3. Prévenir la direction des concours pour les refus injustifiés, le dépassement de capacité et les décisions de rattrapage ; associer les responsables des centres de Parakou et de Lokossa aux conséquences locales.
4. Sécuriser les nouvelles inscriptions avant la reprise des données, puis préparer les corrections et l’information des candidats concernés ; aucune inscription de Lokossa ne doit être supprimée.

## Références de méthode

Les références suivantes fondent la distinction entre impact, urgence et priorité. Le classement ci-dessus est une application au contexte de l’étude de cas, et non une échelle imposée par ces éditeurs.

- [Atlassian — Understanding incident severity levels](https://www.atlassian.com/incident-management/kpis/severity-levels/) : distinction entre gravité et priorité, prise en compte de l’impact sur les utilisateurs et les fonctions du service.
- [PagerDuty — Incident Severity Classification Best Practices](https://www.pagerduty.com/resources/incident-management-response/learn/incident-severity-classification/) : combinaison de l’impact et de l’urgence, et adaptation des critères au contexte de l’organisation.
- Référence métier : [Étude de cas pratique](etude_de_cas_pratique.md), sections 2, 4 et 5.
