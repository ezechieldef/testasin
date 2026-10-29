<?php

namespace App\Console\Commands;

use App\Data\LogAnalysisReport;
use App\Services\ProductionLogAnalyzer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;

#[Signature('journal:analyser {journal_path : Chemin du journal de production}
    {--json : Retourner le rapport complet en JSON}
    {--annee=2026 : Année du concours}
    {--cloture-tresor=2026-11-20 23:59:00 : Clôture du Trésor en heure du Bénin (Y-m-d H:i:s)}')]
#[Description('Analyser les six indicateurs du journal en heure du Bénin, sans accès à la base')]
class AnalyzeProductionLog extends Command
{
    public function handle(ProductionLogAnalyzer $analyzer): int
    {
        try {
            $year = (string) $this->option('annee');
            if (! preg_match('/^\d{4}$/', $year)) {
                throw new InvalidArgumentException('L’année du concours doit comporter quatre chiffres.');
            }
            $report = $analyzer->analyze((string) $this->argument('journal_path'), (int) $year, (string) $this->option('cloture-tresor'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->output->writeln(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $this->renderReport($report);
        }

        return self::SUCCESS;
    }

    private function renderReport(LogAnalysisReport $report): void
    {
        $meta = $report->metadata;
        $this->info('Analyse du journal — Africa/Porto-Novo (UTC+01:00)');
        $this->line("{$meta['lignes_lues']} lignes ; {$meta['evenements_uniques']} événements uniques ; {$meta['evenements_repetes']} répétitions ignorées.");
        $this->line("Fins des événements : {$meta['premiere_fin']} → {$meta['derniere_fin']}");
        $this->newLine();

        $this->info('1. Numéros de table attribués plusieurs fois');
        $this->line(count($report->duplicateNumbers).' numéros concernés.');
        $this->table(['Centre', 'Numéro', 'Attributions', 'NPI', 'Requêtes'], array_map(fn (array $group): array => [$group['centre'], $group['numero'], $group['attributions'], implode(', ', array_column($group['details'], 'npi')), implode(', ', array_column($group['details'], 'requete'))], $report->duplicateNumbers));

        $this->info('2. Inscriptions en double (NPI + concours, tous centres confondus)');
        $this->line(count($report->duplicateRegistrations).' candidatures concernées.');
        $this->table(['Concours', 'NPI', 'Inscriptions', 'Numéros', 'Requêtes'], array_map(fn (array $group): array => [$group['concours'], $group['npi'], $group['attributions'], implode(', ', array_column($group['details'], 'numero')), implode(', ', array_column($group['details'], 'requete'))], $report->duplicateRegistrations));

        $slow = $report->slowIdentity;
        $this->info('3. Service d’identification : appels de 5 secondes ou plus');
        $this->line("{$slow['requetes_touchees']} requêtes distinctes touchées.");
        if ($slow['debut_observe'] !== null) {
            $this->line("Enveloppe observée : {$slow['debut_observe']} → {$slow['fin_observee']}");
        }
        $this->line('Début reconstruit = fin de l’appel − durée ; cette enveloppe ne prouve pas une panne continue.');
        $this->table(['Requête HTTP', 'Nombre'], array_map(fn (string $endpoint, int $count): array => [$endpoint, $count], array_keys($slow['par_endpoint']), array_values($slow['par_endpoint'])));

        $closure = $report->wrongfulClosures;
        $this->info('4. Refus de clôture injustifiés le soir du Trésor');
        $this->line("{$closure['nombre_refus']} refus ; {$closure['candidats_distincts']} candidats distincts.");
        $this->line('Clôture officielle : '.$meta['cloture_tresor'].' ; comparaison au début reconstruit de la requête.');

        $age = $report->wrongfulAge;
        $this->info('5. Refus d’âge injustifiés');
        $this->line("{$age['nombre_refus']} refus ; {$age['candidats_distincts']} candidats distincts.");
        $this->table(['NPI', 'Concours', 'Naissance', 'Âge au 31/12', 'Début de requête'], array_map(fn (array $refusal): array => [$refusal['npi'], $refusal['concours'], $refusal['naissance'], $refusal['age_au_31_decembre'], $refusal['debut']], $age['details']));

        $this->info('6. Durée des listes par centre (réponses HTTP 200)');
        $this->table(['Centre', 'Requêtes', 'Moyenne (ms)', 'Maximum (ms)', '≥ 2 000 ms'], array_map(fn (array $duration): array => [$duration['centre'], $duration['requetes'], $duration['moyenne_ms'], $duration['max_ms'], $duration['hors_objectif']], $report->listDurations));
        $this->line('Le rapport JSON contient les détails, lignes et identifiants de requête pour vérification (--json).');
        $this->line('Les doublons sont ceux observables dans le journal ; les inscriptions antérieures nécessitent un contrôle de la base.');
    }
}
