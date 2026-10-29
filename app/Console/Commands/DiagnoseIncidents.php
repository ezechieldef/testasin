<?php

namespace App\Console\Commands;

use App\Services\IncidentDiagnostics;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use PDOException;
use Symfony\Component\Console\Output\OutputInterface;

#[Signature('incidents:diagnostiquer {journal_path}
    {--incident=all : all ou INC-201 à INC-208}
    {--base : Compléter par des lectures de la base configurée}
    {--limite=5 : Nombre de preuves affichées par incident, 0 pour toutes}
    {--json : Exporter tous les détails et liens en JSON}')]
#[Description('Reproduire le raisonnement de la consigne 3 : faits, causes, limites et liens entre incidents')]
class DiagnoseIncidents extends Command
{
    public function handle(IncidentDiagnostics $diagnostics): int
    {
        try {
            $limit = (string) $this->option('limite');
            if (! preg_match('/^\d{1,5}$/', $limit)) {
                throw new InvalidArgumentException('La limite doit être un entier de 0 à 99999.');
            }
            $report = $diagnostics->diagnose((string) $this->argument('journal_path'), (string) $this->option('incident'), (bool) $this->option('base'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (PDOException $exception) {
            $this->error('Lecture de la base impossible. Vérifier la connexion et l’import des tables du sujet ; aucune réparation n’a été exécutée.');

            return self::FAILURE;
        }
        if ($this->option('json')) {
            $this->writeJson($report);

            return self::SUCCESS;
        }
        $this->info('Diagnostic — consigne 3 — Africa/Porto-Novo (UTC+01:00)');
        $this->line('SHA-256 du journal : '.$report['meta']['sha256']);
        $this->line('Source métier : '.$report['meta']['source_metier']);
        $this->line('Base consultée : '.($report['meta']['base_lue'] ? 'oui, état actuel' : 'non'));
        foreach ($report['incidents'] as $id => $incident) {
            $this->newLine();
            $this->info($id.' — '.$incident['titre']);
            $this->line('Verdict : '.$incident['verdict']);
            foreach ($incident['procedure'] as $index => $step) {
                $this->line(($index + 1).'. '.$step);
            }
            $this->line('Constats :');
            $this->writeJson($incident['constats']);
            $this->line('Cause / explication issue du dossier : '.$incident['cause']);
            foreach ($incident['limites'] as $limitation) {
                $this->line('Limite : '.$limitation);
            }
            $proofs = (int) $limit === 0 ? $incident['preuves'] : array_slice($incident['preuves'], 0, (int) $limit);
            $this->line('Preuves : '.count($proofs).' affichée(s) sur '.count($incident['preuves']).' ; --json ou --limite=0 pour tout afficher.');
            $this->writeJson($proofs);
        }
        $this->newLine();
        $this->info('Liens entre incidents');
        $this->writeJson($report['liens']);
        $this->line($report['independance']);

        return self::SUCCESS;
    }

    private function writeJson(array $value): void
    {
        $this->output->writeln(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
    }
}
