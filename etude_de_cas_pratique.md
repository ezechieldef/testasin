Étude de cas pratique
Développeur(se) senior(e) Full Stack / TMA : inscriptions aux concours administratifs
Niveau Développeur(se) senior(e) Full Stack / TMA
Candidat(e)
Durée 2 heures
Langage, outils, base Au choix du candidat
Ressources autorisées Toutes, y compris Internet et les assistants IA
Fichiers fournis journal_production.log (journal), base_inscriptions.sql (export de la base)
Rendu Dépôt Git, URL envoyée par email
1.Contexte
Les candidats aux concours de la fonction publique s’inscrivent en ligne. Chaque inscription est rattachée à un centre d’examen à la capacité limitée et reçoit un numéro de table. Avant d’enregistrer l’inscription, la plateforme vérifie l’identité du candidat auprès du service national d’identification.
La campagne d’inscription est ouverte depuis dix jours. Plusieurs incidents ont été remontés, et certains ont abîmé des données en base. Vous êtes le référent technique de l’équipe de tierce maintenance applicative (TMA) : vous devez établir les faits, trouver les causes, corriger et réparer les données.
Le code source de l’application ne vous est pas remis. Vous disposez du dossier technique de la section 3, du journal de production du 18 novembre 2026 à 1 h 00 au 21 novembre à 8 h 00, et d’un export de la base au 21 novembre 2026 à 8 h 00. Vous écrivez vos programmes dans le langage de votre choix.
2.Fonctionnement attendu
Ces règles font référence. Un comportement qui les respecte n’est pas une anomalie, même s’il est signalé.
Un candidat s’inscrit à un concours avec son NPI, sa date de naissance et le centre d’examen de son choix.
Le candidat a au plus 35 ans au 31 décembre de l’année du concours.
Les inscriptions restent ouvertes jusqu’à la date et l’heure de clôture du concours, heure du Bénin (GMT+1).
Un candidat ne s’inscrit qu’une fois à un même concours. Il ne peut pas s’inscrire à deux concours dont les épreuves ont lieu le même jour.
Un centre d’examen n’accepte pas plus d’inscrits que sa capacité, tous concours confondus.
Chaque inscrit reçoit un numéro de table unique dans son centre, de la forme P-0412.
Si le service d’identification ne répond pas dans les 5 secondes, l’inscription est enregistrée « en attente de vérification ». Une lenteur de ce service ne doit jamais bloquer les candidats.
La liste des inscrits d’un centre s’affiche en moins de 2 secondes, même avec 5 000 inscrits.
3.Dossier technique (extrait)
Architecture
Une API REST et un écran web, sur un seul serveur d’application réglé sur l’heure du Bénin.
Une base PostgreSQL. Les instants (colonnes cloture et cree_le) y sont enregistrés en UTC.
Le service national d’identification est appelé en HTTP (GET /identite/{npi}) avec un client HTTP standard, dans sa configuration par défaut.
Déroulement d’une inscription (POST /api/inscriptions)

1. Contrôle de forme du NPI, de la date de naissance, du concours et du centre (refus 400).
2. Clôture : refus si l’heure courante du serveur est postérieure à la valeur concours.cloture lue en base.
3. Âge : nombre de jours entre la date de naissance et le 31 décembre de l’année du concours, divisé par 365 ; refus si le résultat dépasse 35.
4. Refus si une inscription existe déjà pour ce NPI et ce concours, ou pour un concours du même jour.
5. Refus si le nombre d’inscriptions du centre atteint sa capacité.
6. Calcul du numéro de table : plus grand numéro du centre + 1.
7. Appel au service d’identification ; refus si le NPI est inconnu.
8. Enregistrement du candidat dans candidats s’il n’y figure pas, puis de l’inscription.
   Autres traitements
   Convocation (GET /api/inscriptions/{id}/convocation) : rappelle le service d’identification pour afficher les nom et prénoms du candidat.
   Liste des inscrits (GET /api/centres/{code}/inscrits?concours=…) : lit les inscriptions du centre et du concours, puis, pour chacune, le candidat correspondant dans candidats.
   Recherche par nom (GET /api/inscrits/recherche?nom=…) : recherche dans candidats, 50 résultats au plus.
   Base de données
   Table Colonnes
   concours id, code (DOUANES, IMPOTS, TRESOR), libelle, date_epreuve, cloture (UTC)
   centres id, code (C, P, L, N, A), nom, capacite
   candidats id, npi, nom, prenoms, date_naissance, cree_le (UTC) ; conserve aussi les candidats des campagnes précédentes
   inscriptions id, concours_id, centre_id, npi, numero_table, statut, cree_le (UTC)

Index : clés primaires uniquement. Aucune contrainte d’unicité.
Format du journal
Une ligne par événement, horodatée en UTC. La ligne d’une requête est écrite à la fin de son traitement, avec sa durée. L’appel au service d’identification fait l’objet d’une ligne à part, portant le même identifiant de requête, au niveau WARN s’il a duré 5 secondes ou plus.
2026-11-19T08:21:39.070Z WARN [req:a061413f] appel identite GET
/identite/3076016910 -> 200 (22766 ms)
2026-11-19T08:21:39.081Z INFO [req:a061413f] 41.138.98.92 POST
/api/inscriptions -> 201 (22781 ms) concours=DOUANES centre=P
npi=3076016910 numero=P-0464
Les lignes sont coupées ici pour l’impression ; dans le fichier, chaque événement tient sur une ligne.
4.Incidents à traiter
Incident Signalé par Signalement
INC-201 Chef du centre de Parakou « Deux candidats ont reçu le même numéro de table, P-0464. »
INC-202 Direction des concours « Le centre de Lokossa compte 307 inscrits pour 300 places. »
INC-203 Candidat « La page ne répondait pas : j’ai cliqué une seconde fois sur “Valider”. J’ai maintenant deux convocations. »
INC-204 Support « Le 20 novembre, jour de la clôture du concours du Trésor fixée à 23 h 59, les candidats ont reçu “Inscriptions closes” dès 22 h 59. »
INC-205 Candidat « Je suis né le 20 juin 1991 : j’aurai 35 ans au 31 décembre 2026. L’inscription m’a répondu que je dépassais l’âge limite. »
INC-206 Exploitation « Le 19 novembre au matin, pendant une demi-heure, plus personne ne pouvait s’inscrire ni consulter sa convocation : les pages tournaient sans fin. »
INC-207 Direction des concours « La liste des inscrits du centre de Cotonou met plus de 40 secondes à s’afficher. »
INC-208 Candidat « Je suis inscrit au concours des douanes. Je ne peux pas m’inscrire au concours des impôts, dont l’épreuve a lieu le même jour. »

5.Travail demandé
Vous n’aurez pas le temps de tout faire. Votre priorisation s’applique aussi aux tâches ci-dessous.

1. Priorisez. Avant toute analyse détaillée, classez les incidents dans un fichier INTERVENTION.md selon leur gravité et leur urgence, en justifiant chaque rang en une ligne. Ce classement est votre premier commit.
2. Analysez le journal avec un programme écrit dans votre langage, que l’on relance avec le chemin du journal en argument. Il établit, en heure du Bénin : les numéros de table attribués plusieurs fois ; les inscriptions en double ; la période pendant laquelle le service d’identification a mis 5 secondes ou plus, et le nombre de requêtes touchées ; le nombre de refus à tort le soir de la clôture du Trésor ; les candidats refusés à tort pour l’âge ; la durée moyenne et maximale de la liste des inscrits par centre.
3. Trouvez la cause racine de chaque incident en croisant le dossier technique et le journal. Montrez les liens entre incidents.
4. Corrigez. Dans votre langage, et avec la base de votre choix, codez l’enregistrement d’une inscription (étapes 4 à 8) de sorte qu’il n’y ait ni numéro en double, ni dépassement de capacité, ni double inscription, même avec des requêtes simultanées, et que l’appel au service d’identification ne dure jamais plus de 5 secondes. Corrigez aussi les règles de l’âge et de la clôture. Ajoutez des tests automatisés, dont un qui envoie des requêtes simultanées.
5. Réparez les données avec un script SQL appliqué à l’export, qu’on peut rejouer sans risque : numéros en double, inscriptions en double, puis contraintes qui empêchent ces incidents de se reproduire. Pour Lokossa, ne supprimez aucune inscription : préparez la liste et la décision à demander. Indiquez qui doit être prévenu.
6. Rédigez un rapport d’incident pour INC-201, INC-202, INC-203 ou INC-206, au choix : chronologie, cause racine, impact chiffré, correction et actions pour éviter que l’incident se reproduise.
   6.Bonus
   Le journal révèle un incident que personne n’a signalé. Décrivez-le, évaluez ce qui a pu être exposé et proposez les mesures immédiates.
   7.Rendu du travail
   Déposez votre travail sur un dépôt Git (GitHub, GitLab ou équivalent) accessible au jury : dépôt public, ou dépôt privé avec accès accordé aux trois adresses ci-dessous.
   Avant la fin du temps imparti, envoyez l’URL du dépôt par email à sasabi@asin.bj, avec asaroukou@presidence.bj et fhounmenou@asin.bj en copie. Indiquez en objet : « Étude de cas TMA DEP/ASIN 2026 », suivi de votre nom.
   Seuls les commits effectués avant l’heure de fin sont pris en compte. Faites des commits réguliers : l’historique du dépôt fait partie de l’évaluation.
   Le dépôt contient INTERVENTION.md, le programme d’analyse, la correction et ses tests, le script SQL, le rapport d’incident et un README qui explique comment tout lancer.
   8.Évaluation
   Le jury lit vos documents, relance votre programme sur le journal, exécute vos tests et applique votre script à l’export.
   Lors de l’entretien, vous pourrez être interrogé(e) sur vos choix de priorité et sur vos corrections.
   Critère Points
   Priorisation : classement justifié, signalement conforme bien identifié 2
   Analyse du journal : programme et chiffres justes 4
   Causes racines et liens entre incidents 4
   Correction codée et tests, dont concurrence 5
   Reprise des données : script SQL sûr et rejouable 2
   Rapport d’incident 2
   Bonus 1
   Total 20

Chemin d’exemple du journal pour les tests : journal_production.log
