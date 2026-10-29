<?php

namespace App\Services;

use App\Data\LogEntry;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class IncidentDiagnostics
{
    public function __construct(private ProductionLogAnalyzer $analyzer, private JournalInvestigator $investigator) {}

    /** @return array<string, mixed> */
    public function diagnose(string $path, string $incident = 'all', bool $includeDatabase = false): array
    {
        $ids = array_map(fn (int $number): string => 'INC-'.$number, range(201, 208));
        if ($incident !== 'all' && ! in_array($incident, $ids, true)) {
            throw new InvalidArgumentException('Incident inconnu : utiliser all ou INC-201 à INC-208.');
        }
        $report = $this->analyzer->analyze($path);
        $entries = $this->investigator->read($path);
        $slowIds = array_column($report->slowIdentity['details'], 'requete');
        $http = $identity = [];
        foreach ($entries as $entry) {
            if ($entry->identityCall) {
                $identity[$entry->requestId] = $entry;
            } else {
                $http[$entry->requestId] = $entry;
            }
        }
        $numberEvidence = $this->groupEvidence($report->duplicateNumbers, $identity);
        $registrationEvidence = $this->groupEvidence($report->duplicateRegistrations, $identity);
        $lokossa = array_values(array_filter($http, fn (LogEntry $entry): bool => $entry->method === 'POST' && $entry->uri === '/api/inscriptions' && $entry->status === 201 && ($entry->context['centre'] ?? '') === 'L'));
        $lists = array_values(array_filter($http, fn (LogEntry $entry): bool => $entry->method === 'GET' && $entry->status === 200 && preg_match('~^/api/centres/[^/?]+/inscrits(?:\?|$)~', $entry->uri)));
        $sameDay = array_values(array_filter($http, fn (LogEntry $entry): bool => $entry->method === 'POST' && $entry->uri === '/api/inscriptions' && $entry->status === 409 && str_contains($entry->context['erreur'] ?? '', 'épreuve a lieu le même jour')));
        $sameDayNpis = array_values(array_unique(array_map(fn (LogEntry $entry): string => $entry->context['npi'], $sameDay)));
        $database = $includeDatabase ? $this->databaseEvidence($sameDayNpis) : null;
        $sameDayEvidence = array_map(function (LogEntry $entry) use ($database): array {
            $evidence = $this->investigator->evidence($entry);
            $evidence['antecedents_meme_jour'] = null;
            if ($database !== null) {
                $competition = array_values(array_filter($database['concours'], fn (array $row): bool => $row['code'] === $entry->context['concours']))[0] ?? null;
                $evidence['antecedents_meme_jour'] = array_values(array_filter($database['inscriptions_refus_meme_jour'], fn (array $row): bool => $competition !== null && $row['npi'] === $entry->context['npi'] && $row['concours'] !== $competition['code'] && $row['date_epreuve'] === $competition['date_epreuve'] && new DateTimeImmutable($row['cree_le_UTC'], new DateTimeZone('UTC')) <= $entry->startedAt()));
            }

            return $evidence;
        }, $sameDay);
        $ageEvidence = [];
        foreach ($report->wrongfulAge['details'] as $row) {
            $birth = new DateTimeImmutable($row['naissance'], new DateTimeZone('Africa/Porto-Novo'));
            $end = new DateTimeImmutable('2026-12-31', new DateTimeZone('Africa/Porto-Novo'));
            $row['jours_divises_par_365'] = round($birth->diff($end)->days / 365, 6);
            $ageEvidence[] = $row;
        }
        $slowEvidence = [];
        foreach ($report->slowIdentity['details'] as $row) {
            $request = $http[$row['requete']] ?? null;
            $row['surcout_http_ms'] = $request === null ? null : $request->durationMs - $row['duree_ms'];
            $slowEvidence[] = $row;
        }
        $listEvidence = array_map(function (LogEntry $entry): array {
            $lines = isset($entry->context['lignes']) ? (int) $entry->context['lignes'] : null;

            return $this->investigator->evidence($entry) + ['lignes_retournees' => $lines, 'lectures_sql_selon_dossier' => $lines === null ? null : $lines + 1];
        }, $lists);
        $diagnostics = [
            'INC-201' => $this->incident('Numéros de table identiques', $numberEvidence === [] ? 'Non observé dans cette fenêtre' : 'Anomalie observée ; évaluer la concurrence avec les chevauchements ci-dessous',
                ['Regrouper les HTTP 201 par centre et numéro.', 'Reconstruire les intervalles des requêtes et rechercher leurs chevauchements.', 'Rapprocher les appels d’identité et les étapes 6 à 8 du dossier.'],
                ['numeros_concernes' => count($report->duplicateNumbers), 'attributions_excedentaires' => array_sum(array_column($report->duplicateNumbers, 'excedent')), 'groupes_avec_chevauchement' => count(array_filter($report->duplicateNumbers, fn (array $group): bool => $this->overlaps($group['details']) !== []))],
                'Le calcul « maximum du centre + 1 » précède l’appel HTTP et l’insertion. Sans allocation atomique ni contrainte unique, des requêtes concurrentes peuvent retenir le même numéro avant l’enregistrement de la première.',
                ['Les lectures du maximum et les verrous ne sont pas journalisés : le chevauchement étaye ce scénario, sans révéler chaque instruction SQL.'], $numberEvidence),
            'INC-202' => $this->incident('Capacité de Lokossa', $database === null ? 'Dépassement signalé ; total à vérifier en base' : 'État de capacité mesuré dans la base configurée',
                ['Isoler les créations du centre L, tous concours confondus.', 'Chercher les admissions simultanées, notamment les groupes L-0297 et L-0298.', 'Avec --base, compter toutes les inscriptions du centre et comparer à sa capacité.'],
                ['creations_L_dans_journal' => count($lokossa), 'creations_L_avec_identite_lente' => count(array_intersect(array_map(fn (LogEntry $entry): string => $entry->requestId, $lokossa), $slowIds)), 'etat_L_en_base' => $database === null ? null : array_values(array_filter($database['centres'], fn (array $row): bool => $row['code'] === 'L'))],
                'Le contrôle « nombre d’inscriptions < capacité » est séparé de l’insertion par le calcul du numéro et l’appel d’identité. Plusieurs admissions peuvent franchir le contrôle sur le même état ; le contrôle et la réservation de place doivent être atomiques.',
                ['Le journal commence après le début de campagne : le maximum des numéros ne constitue pas un compteur fiable.', 'Le total de la base est son état actuel ; son origine et sa date doivent être rapprochées de l’export fourni.', 'Les comptages lus par les requêtes ne figurent pas dans le journal ; aucune inscription ne doit être supprimée pour établir ce diagnostic.'], array_map($this->investigator->evidence(...), $lokossa)),
            'INC-203' => $this->incident('Inscriptions multiples', $registrationEvidence === [] ? 'Non observé dans cette fenêtre' : 'Anomalie observée ; évaluer la concurrence avec les chevauchements ci-dessous',
                ['Regrouper les créations par NPI et concours, indépendamment du centre.', 'Comparer les intervalles des demandes pour distinguer succès et refus de doublon.', 'Vérifier les appels d’identité et l’absence d’unicité indiquée dans le dossier.'],
                ['candidatures_multiples' => count($report->duplicateRegistrations), 'inscriptions_excedentaires' => array_sum(array_column($report->duplicateRegistrations, 'excedent')), 'groupes_avec_chevauchement' => count(array_filter($report->duplicateRegistrations, fn (array $group): bool => $this->overlaps($group['details']) !== []))],
                'Le contrôle d’existence intervient avant l’appel d’identité et l’écriture, sans garantie d’unicité en base. Une deuxième demande peut donc être acceptée alors que la première est encore en cours.',
                ['Le geste du double clic n’est pas journalisé : les répétitions sont établies, leur origine exacte dans le navigateur ne l’est pas.'], $registrationEvidence),
            'INC-204' => $this->incident('Clôture anticipée du Trésor', $report->wrongfulClosures['nombre_refus'] === 0 ? 'Non observé dans cette fenêtre' : 'Refus prématurés observés ; décalage de fuseau cohérent avec le dossier',
                ['Sélectionner les refus de clôture du Trésor le 20 novembre local.', 'Reconstruire le début des requêtes et comparer à 23:59:00 en heure du Bénin.', 'Avec --base, vérifier la clôture UTC enregistrée ; confronter à la comparaison de l’étape 2.'],
                ['refus_injustifies' => $report->wrongfulClosures['nombre_refus'], 'candidats_distincts' => $report->wrongfulClosures['candidats_distincts'], 'cloture_officielle' => $report->metadata['cloture_tresor'], 'cloture_UTC_attendue' => '2026-11-20T22:59:00.000+00:00', 'concours_en_base' => $database['concours'] ?? null],
                'Comparer une heure locale GMT+1 à une valeur UTC dépourvue de conversion explicite avance le seuil apparent d’une heure : 22:59 UTC est interprété comme 22:59 locale, au lieu de 23:59 locale.',
                ['L’implémentation d’origine n’est pas fournie : la conversion manquante est déduite du dossier et du décalage observé.', 'Le début est reconstruit ; l’instant exact du contrôle de clôture n’est pas enregistré.'], $report->wrongfulClosures['details']),
            'INC-205' => $this->incident('Refus liés à l’âge', $ageEvidence === [] ? 'Non observé dans cette fenêtre' : 'Erreur de calcul démontrée par le dossier et les refus observés',
                ['Sélectionner les refus explicitement motivés par l’âge.', 'Calculer les années révolues au 31 décembre 2026.', 'Comparer au calcul jours / 365 décrit dans le dossier, notamment aux bornes de 35 ans.'],
                ['refus_injustifies' => $report->wrongfulAge['nombre_refus'], 'candidats_distincts' => $report->wrongfulAge['candidats_distincts']],
                'Une durée en jours divisée par 365 est une durée fractionnaire, pas un âge civil en années révolues. Les mois suivant le 35e anniversaire et les années bissextiles font dépasser artificiellement 35.',
                ['La fenêtre du journal ne couvre pas tous les refus de la campagne.'], $ageEvidence),
            'INC-206' => $this->incident('Lenteur des inscriptions et convocations', $slowEvidence === [] ? 'Non observé dans cette fenêtre' : 'Attente du service d’identité démontrée ; saturation globale non prouvée',
                ['Retenir les appels d’identité dont la durée est au moins 5 000 ms.', 'Les corréler aux requêtes HTTP par identifiant et comparer leurs durées.', 'Reconstruire les intervalles des appels ; distinguer enveloppe observée et panne continue.'],
                ['requetes_touchees' => $report->slowIdentity['requetes_touchees'], 'debut_observe' => $report->slowIdentity['debut_observe'], 'fin_observee' => $report->slowIdentity['fin_observee'], 'par_endpoint' => $report->slowIdentity['par_endpoint'], 'maximum_identite_ms' => $slowEvidence === [] ? null : max(array_column($slowEvidence, 'duree_ms'))],
                'Les inscriptions et convocations attendent un service externe synchrone. Aucun délai applicatif conforme à 5 secondes ni repli en attente de vérification n’interrompt ces appels dans les traces ; le client dans sa configuration par défaut ne garantit pas cette règle métier.',
                ['La cause de la lenteur du service externe reste inconnue.', 'Le nombre de workers, leur occupation et les files d’attente ne sont pas fournis : une saturation du serveur est une hypothèse, pas une mesure.', 'Des réponses lentes aboutissent ; le journal ne démontre pas que chaque candidat a été bloqué pendant toute la période.'], $slowEvidence),
            'INC-207' => $this->incident('Listes des inscrits lentes', $listEvidence === [] ? 'Non observé dans cette fenêtre' : 'Lenteurs mesurées ; lectures N+1 décrites dans le dossier',
                ['Sélectionner les listes HTTP 200 par centre.', 'Comparer durée et nombre de lignes retournées, puis l’objectif strict de moins de 2 secondes.', 'Rapprocher du traitement une liste puis une lecture candidat par inscription ; inspecter les index avec --base.'],
                ['durees_par_centre' => $report->listDurations, 'index_en_base' => $database['index'] ?? null],
                'Le traitement décrit effectue une lecture initiale puis une lecture candidat pour chacune des N inscriptions : N+1 lectures. L’absence d’index adaptés aux filtres des inscriptions et au NPI des candidats aggrave ce coût.',
                ['N+1 est déduit du dossier ; le nombre de requêtes SQL exécutées n’est pas enregistré.', 'Le journal ne permet pas d’attribuer précisément la durée aux lectures, au réseau, au rendu ou à une autre attente ; il faudrait un profilage SQL.'], $listEvidence),
            'INC-208' => $this->incident('Deux concours le même jour', 'Comportement conforme à la règle métier',
                ['Isoler les refus mentionnant une épreuve le même jour.', 'Les confronter à la règle et à l’étape 4 du dossier.', 'Avec --base, comparer les dates d’épreuve des concours.'],
                ['refus_observes' => count($sameDay), 'dates_concours_en_base' => $database['concours'] ?? null],
                'L’interdiction de deux concours dont les épreuves ont lieu le même jour est volontaire. Ce signalement appelle une explication au candidat, pas une suppression de la règle.',
                ['Sans lecture de la base, le journal seul ne fournit pas les dates d’épreuve ni toutes les inscriptions antérieures du candidat.', 'Avec --base, antecedents_meme_jour expose les inscriptions antérieures à la demande refusée, dans un autre concours du même jour ; une liste vide ne prouve pas à elle seule un refus erroné si la base a été modifiée.'], $sameDayEvidence),
        ];
        $links = $this->links($report->duplicateNumbers, $report->duplicateRegistrations, $slowIds, $lokossa);

        return [
            'meta' => $report->metadata + ['sha256' => hash_file('sha256', $path), 'source_metier' => 'etude_de_cas_pratique.md, sections 2 à 4', 'base_lue' => $includeDatabase],
            'incidents' => $incident === 'all' ? $diagnostics : [$incident => $diagnostics[$incident]],
            'liens' => $incident === 'all' ? $links : array_values(array_filter($links, fn (array $link): bool => in_array($incident, [$link['de'], $link['vers']], true))),
            'independance' => 'Aucun lien causal établi entre les erreurs de fuseau (INC-204), d’âge (INC-205) et les incidents de concurrence ; INC-208 est conforme.',
            'base' => $database,
        ];
    }

    /** @return array<string, mixed> */
    private function incident(string $title, string $verdict, array $procedure, array $facts, string $cause, array $limits, array $evidence): array
    {
        return ['titre' => $title, 'verdict' => $verdict, 'procedure' => $procedure, 'constats' => $facts, 'cause' => $cause, 'limites' => $limits, 'preuves' => $evidence];
    }

    /** @return list<array<string, mixed>> */
    private function groupEvidence(array $groups, array $identity): array
    {
        return array_map(function (array $group) use ($identity): array {
            $group['chevauchements'] = $this->overlaps($group['details']);
            foreach ($group['details'] as &$row) {
                $row['appel_identite'] = isset($identity[$row['requete']]) ? $this->investigator->evidence($identity[$row['requete']]) : null;
            }
            unset($row);

            return $group;
        }, $groups);
    }

    /** @return list<array{requetes: list<string>, debut: string, fin: string}> */
    private function overlaps(array $rows): array
    {
        $pairs = [];
        for ($a = 0; $a < count($rows); $a++) {
            for ($b = $a + 1; $b < count($rows); $b++) {
                $start = max($rows[$a]['debut'], $rows[$b]['debut']);
                $end = min($rows[$a]['fin'], $rows[$b]['fin']);
                if ($start < $end) {
                    $pairs[] = ['requetes' => [$rows[$a]['requete'], $rows[$b]['requete']], 'debut' => $start, 'fin' => $end];
                }
            }
        }

        return $pairs;
    }

    /** @return list<array<string, mixed>> */
    private function links(array $numbers, array $registrations, array $slowIds, array $lokossa): array
    {
        $numberIds = $registrationIds = [];
        foreach ($numbers as $group) {
            array_push($numberIds, ...array_column($group['details'], 'requete'));
        }
        foreach ($registrations as $group) {
            array_push($registrationIds, ...array_column($group['details'], 'requete'));
        }
        $links = [];
        foreach (['INC-201' => $numberIds, 'INC-202' => array_map(fn (LogEntry $entry): string => $entry->requestId, $lokossa), 'INC-203' => $registrationIds] as $target => $ids) {
            $shared = array_values(array_unique(array_intersect($ids, $slowIds)));
            $links[] = ['de' => 'INC-206', 'vers' => $target, 'nature' => $shared === [] ? 'Hypothèse sans requête commune observée' : 'Corrélation observée ; facteur aggravant cohérent avec le dossier', 'explication' => 'L’appel d’identité intervient après les contrôles et avant l’écriture ; sa lenteur allonge la fenêtre pendant laquelle plusieurs demandes peuvent franchir les mêmes contrôles.', 'requetes_communes' => $shared, 'nombre' => count($shared)];
        }
        $shared = array_values(array_unique(array_intersect($numberIds, $registrationIds)));
        $links[] = ['de' => 'INC-201', 'vers' => 'INC-203', 'nature' => 'Défauts d’atomicité apparentés ; une anomalie n’implique pas l’autre', 'explication' => 'Une même création peut violer à la fois l’unicité du numéro et celle de la candidature. Les collisions entre candidats différents et les doubles candidatures doivent être distinguées.', 'requetes_communes' => $shared, 'nombre' => count($shared)];
        $lokossaCollisions = array_values(array_unique(array_intersect($numberIds, array_map(fn (LogEntry $entry): string => $entry->requestId, $lokossa))));
        $links[] = ['de' => 'INC-201', 'vers' => 'INC-202', 'nature' => $lokossaCollisions === [] ? 'Défaut d’atomicité apparenté ; aucune requête commune observée' : 'Collisions de numéros observées parmi les créations de Lokossa', 'explication' => 'Les numéros réutilisés et le dépassement peuvent résulter des mêmes admissions concurrentes ; le numéro dupliqué ne cause pas à lui seul le dépassement de capacité.', 'requetes_communes' => $lokossaCollisions, 'nombre' => count($lokossaCollisions)];
        $lokossaDuplicates = array_values(array_unique(array_intersect($registrationIds, array_map(fn (LogEntry $entry): string => $entry->requestId, $lokossa))));
        $links[] = ['de' => 'INC-203', 'vers' => 'INC-202', 'nature' => $lokossaDuplicates === [] ? 'Lien possible en général, non observé à Lokossa dans cette fenêtre' : 'Candidatures multiples observées à Lokossa', 'explication' => 'Une double candidature peut consommer plusieurs places, mais ne suffit pas à expliquer un dépassement si le contrôle de capacité est atomique.', 'requetes_communes' => $lokossaDuplicates, 'nombre' => count($lokossaDuplicates)];
        $links[] = ['de' => 'INC-207', 'vers' => 'INC-206', 'nature' => 'Hypothèse non démontrée', 'explication' => 'Des listes coûteuses pourraient concurrencer les autres traitements sur le serveur unique ; aucune métrique de workers ou de charge ne démontre ce lien.', 'requetes_communes' => [], 'nombre' => 0];

        return $links;
    }

    /** @return array<string, mixed> */
    private function databaseEvidence(array $sameDayNpis): array
    {
        $connection = DB::connection();

        return $connection->transaction(function () use ($connection, $sameDayNpis): array {
            if ($connection->getDriverName() === 'pgsql') {
                $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            $centres = $connection->table('centres as c')->leftJoin('inscriptions as i', 'i.centre_id', '=', 'c.id')
                ->select('c.id', 'c.code', 'c.capacite')->selectRaw('COUNT(i.id) AS inscriptions, COUNT(DISTINCT i.npi) AS candidats_distincts')
                ->groupBy('c.id', 'c.code', 'c.capacite')->orderBy('c.code')->get()
                ->map(fn (object $row): array => ['code' => $row->code, 'capacite' => (int) $row->capacite, 'inscriptions' => (int) $row->inscriptions, 'candidats_distincts' => (int) $row->candidats_distincts, 'depassement' => max(0, (int) $row->inscriptions - (int) $row->capacite)])->all();
            $competitions = $connection->table('concours')->orderBy('code')->get(['code', 'date_epreuve', 'cloture'])->map(fn (object $row): array => ['code' => $row->code, 'date_epreuve' => $row->date_epreuve, 'cloture_UTC' => $row->cloture, 'cloture_Benin' => LogEntry::localTime(new DateTimeImmutable($row->cloture, new DateTimeZone('UTC')))])->all();
            $indexes = [];
            foreach (['candidats', 'inscriptions'] as $table) {
                $indexes[$table] = $connection->getSchemaBuilder()->getIndexes($table);
            }

            $sameDayRegistrations = $sameDayNpis === [] ? [] : $connection->table('inscriptions as i')->join('concours as q', 'q.id', '=', 'i.concours_id')->whereIn('i.npi', $sameDayNpis)->orderBy('i.npi')->orderBy('i.id')->get(['i.npi', 'q.code', 'q.date_epreuve', 'i.cree_le'])->map(fn (object $row): array => ['npi' => $row->npi, 'concours' => $row->code, 'date_epreuve' => $row->date_epreuve, 'cree_le_UTC' => $row->cree_le])->all();

            return ['provenance' => 'État actuel de la base configurée ; vérifier séparément qu’elle correspond à l’export du sujet.', 'centres' => $centres, 'concours' => $competitions, 'index' => $indexes, 'inscriptions_refus_meme_jour' => $sameDayRegistrations];
        });
    }
}
