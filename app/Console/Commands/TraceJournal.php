<?php

namespace App\Console\Commands;

use App\Services\JournalInvestigator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;

#[Signature('journal:tracer {journal_path}
    {--requete= : Identifiant de requête}
    {--npi= : NPI exact, zéros initiaux conservés}
    {--numero= : Numéro de table exact}
    {--centre= : Code du centre}
    {--concours= : Code du concours}
    {--debut= : Début de période en heure du Bénin (Y-m-d H:i:s)}
    {--fin= : Fin de période en heure du Bénin (Y-m-d H:i:s)}
    {--json : Exporter les preuves en JSON}')]
#[Description('Retracer les événements corrélés d’une requête, avec des filtres combinés par ET')]
class TraceJournal extends Command
{
    public function handle(JournalInvestigator $investigator): int
    {
        $filters = [];
        foreach (['requete', 'npi', 'numero', 'centre', 'concours', 'debut', 'fin'] as $name) {
            $filters[$name] = (string) $this->option($name);
        }
        try {
            $report = $investigator->trace((string) $this->argument('journal_path'), $filters);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        if ($this->option('json')) {
            $this->output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $this->info('Chronologie — Africa/Porto-Novo (UTC+01:00)');
            $this->line('SHA-256 : '.$report['sha256']);
            $this->line($report['requetes'].' requête(s) ; '.count($report['evenements']).' événement(s).');
            $this->line('Début = fin − durée ; tous les événements des requêtes retenues sont affichés.');
            $this->table(['Ligne', 'Requête', 'Type', 'Début', 'Fin', 'ms', 'HTTP', 'Chemin', 'Contexte'], array_map(fn (array $row): array => [$row['ligne'], $row['requete'], $row['type'], $row['debut'], $row['fin'], $row['duree_ms'], $row['statut'], $row['methode'].' '.$row['uri'], json_encode($row['contexte'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], $report['evenements']));
        }

        return self::SUCCESS;
    }
}
