<?php

namespace Tests\Unit\Services;

use App\Services\ProductionLogAnalyzer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProductionLogAnalyzerTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            unlink($path);
        }
        parent::tearDown();
    }

    public function test_duplicates_use_centre_and_number_but_registration_uniqueness_spans_centres(): void
    {
        $report = (new ProductionLogAnalyzer)->analyze($this->fixture());

        $this->assertCount(1, $report->duplicateNumbers);
        $this->assertSame('P', $report->duplicateNumbers[0]['centre']);
        $this->assertSame(3, $report->duplicateNumbers[0]['attributions']);
        $this->assertSame(['reg1', 'reg2', 'reg3'], array_column($report->duplicateNumbers[0]['details'], 'requete'));
        $this->assertCount(1, $report->duplicateRegistrations);
        $this->assertSame('0012345678', $report->duplicateRegistrations[0]['npi']);
        $this->assertSame(2, $report->duplicateRegistrations[0]['excedent']);
        $this->assertSame(['reg1', 'reg2', 'reg4'], array_column($report->duplicateRegistrations[0]['details'], 'requete'));
    }

    public function test_slow_calls_are_counted_once_per_request_and_intervals_are_reconstructed_and_merged(): void
    {
        $report = (new ProductionLogAnalyzer)->analyze($this->fixture());

        $this->assertSame(4, $report->slowIdentity['requetes_touchees']);
        $this->assertSame('2026-11-20T00:59:54.500+01:00', $report->slowIdentity['debut_observe']);
        $this->assertSame('2026-11-20T01:10:00.000+01:00', $report->slowIdentity['fin_observee']);
        $this->assertSame([
            ['debut' => '2026-11-20T00:59:54.500+01:00', 'fin' => '2026-11-20T01:00:06.000+01:00', 'appels' => 3],
            ['debut' => '2026-11-20T01:09:55.000+01:00', 'fin' => '2026-11-20T01:10:00.000+01:00', 'appels' => 1],
        ], $report->slowIdentity['intervalles_appels_lents']);
        $this->assertSame(['GET /api/inscriptions/{id}/convocation' => 1, 'POST /api/inscriptions' => 1, 'requete_absente_du_journal' => 2], $report->slowIdentity['par_endpoint']);
        $this->assertSame('2026-11-20T00:59:50.500+01:00', $report->slowIdentity['details'][0]['requete_http']['debut']);
    }

    public function test_closing_comparison_uses_request_start_and_includes_the_exact_deadline(): void
    {
        $report = (new ProductionLogAnalyzer)->analyze($this->fixture());

        $this->assertSame(2, $report->wrongfulClosures['nombre_refus']);
        $this->assertSame(1, $report->wrongfulClosures['candidats_distincts']);
        $this->assertSame(['close1', 'close2'], array_column($report->wrongfulClosures['details'], 'requete'));
        $this->assertSame('2026-11-20T23:59:00.000+01:00', $report->wrongfulClosures['details'][1]['debut']);
    }

    public function test_age_uses_completed_years_at_year_end_and_distinguishes_people_from_attempts(): void
    {
        $report = (new ProductionLogAnalyzer)->analyze($this->fixture());

        $this->assertSame(4, $report->wrongfulAge['nombre_refus']);
        $this->assertSame(3, $report->wrongfulAge['candidats_distincts']);
        $this->assertSame([35, 35, 34, 35], array_column($report->wrongfulAge['details'], 'age_au_31_decembre'));
        $this->assertSame(['age1', 'age2', 'age4', 'age5'], array_column($report->wrongfulAge['details'], 'requete'));
    }

    public function test_list_statistics_are_aggregated_across_competitions_and_exclude_failed_responses(): void
    {
        $report = (new ProductionLogAnalyzer)->analyze($this->fixture());

        $this->assertSame([
            ['centre' => 'C', 'requetes' => 1, 'max_ms' => 2000, 'hors_objectif' => 1, 'moyenne_ms' => 2000.0],
            ['centre' => 'P', 'requetes' => 2, 'max_ms' => 3001, 'hors_objectif' => 1, 'moyenne_ms' => 2000.5],
        ], $report->listDurations);
    }

    public function test_age_uses_the_supplied_competition_year_instead_of_the_log_year(): void
    {
        $report = (new ProductionLogAnalyzer)->analyze($this->fixture(), 2027, '2027-11-20 23:59:00');

        $this->assertSame(1, $report->wrongfulAge['nombre_refus']);
        $this->assertSame('0092345678', $report->wrongfulAge['details'][0]['npi']);
        $this->assertSame(35, $report->wrongfulAge['details'][0]['age_au_31_decembre']);
        $this->assertSame(0, $report->wrongfulClosures['nombre_refus']);
    }

    public function test_repeated_log_lines_do_not_create_false_duplicates_and_service_has_no_state_between_runs(): void
    {
        $line = '2026-11-19T08:00:00.100Z INFO [req:one] 127.0.0.1 POST /api/inscriptions -> 201 (100 ms) concours=DOUANES centre=P npi=0012345678 numero=P-0001';
        $analyzer = new ProductionLogAnalyzer;
        $path = $this->temporaryFile($line."\n\n".$line."\n");

        $report = $analyzer->analyze($path);
        $secondReport = $analyzer->analyze($path);

        $this->assertSame(1, $report->metadata['evenements_repetes']);
        $this->assertSame(1, $report->metadata['requetes_http']);
        $this->assertSame([], $report->duplicateNumbers);
        $this->assertSame([], $report->duplicateRegistrations);
        $this->assertSame(0, $report->slowIdentity['requetes_touchees']);
        $this->assertNull($report->slowIdentity['debut_observe']);
        $this->assertSame([], $report->listDurations);
        $this->assertSame($report->toArray(), $secondReport->toArray());
    }

    public function test_unsorted_log_produces_the_same_indicators(): void
    {
        $text = file_get_contents($this->fixture());
        $reversed = implode("\n", array_reverse(explode("\n", trim($text))));
        $analyzer = new ProductionLogAnalyzer;

        $report = $analyzer->analyze($this->temporaryFile($reversed));
        $expected = $analyzer->analyze($this->fixture());

        $this->assertSame($expected->metadata, $report->metadata);
        $this->assertSame($expected->listDurations, $report->listDurations);
        $this->assertSame($expected->slowIdentity['debut_observe'], $report->slowIdentity['debut_observe']);
        $this->assertSame($expected->slowIdentity['intervalles_appels_lents'], $report->slowIdentity['intervalles_appels_lents']);
        $this->assertSame($expected->wrongfulAge['nombre_refus'], $report->wrongfulAge['nombre_refus']);
    }

    #[DataProvider('invalidLogs')]
    public function test_invalid_input_fails_explicitly_instead_of_producing_incomplete_statistics(string $text, string $message): void
    {
        $path = $this->temporaryFile($text);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (new ProductionLogAnalyzer)->analyze($path);
    }

    /** @return array<string, array{string, string}> */
    public static function invalidLogs(): array
    {
        return [
            'empty' => ['', 'aucun événement exploitable'],
            'malformed' => ["\nnot a log line", 'Ligne 2 : format de journal non reconnu'],
            'invalid timestamp' => ['2026-02-30T08:00:00.100Z INFO [req:a] 127.0.0.1 GET /api/centres -> 200 (10 ms)', 'Ligne 1 : horodatage UTC invalide'],
            'missing number' => ['2026-11-19T08:00:00.100Z INFO [req:a] 127.0.0.1 POST /api/inscriptions -> 201 (10 ms) concours=DOUANES centre=P npi=0012345678', 'champ numero manquant'],
            'invalid birth date' => ['2026-11-19T08:00:00.100Z INFO [req:a] 127.0.0.1 POST /api/inscriptions -> 409 (10 ms) concours=DOUANES centre=P npi=0012345678 naissance=1991-02-29 erreur="Âge limite dépassé"', 'Ligne 1 : date invalide'],
            'conflicting request' => ["2026-11-19T08:00:00.100Z INFO [req:a] 127.0.0.1 GET /api/centres -> 200 (10 ms)\n2026-11-19T08:00:00.200Z INFO [req:a] 127.0.0.1 GET /api/centres -> 200 (20 ms)", 'événements contradictoires'],
        ];
    }

    #[DataProvider('invalidParameters')]
    public function test_invalid_competition_parameters_are_rejected(int $year, string $closing): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ProductionLogAnalyzer)->analyze($this->fixture(), $year, $closing);
    }

    /** @return array<string, array{int, string}> */
    public static function invalidParameters(): array
    {
        return [
            'year out of range' => [0, '2026-11-20 23:59:00'],
            'invalid closing' => [2026, '2026-02-30 23:59:00'],
            'different year' => [2027, '2026-11-20 23:59:00'],
        ];
    }

    private function fixture(): string
    {
        return dirname(__DIR__, 2).'/Fixtures/production-log.txt';
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'testasin-test-');
        file_put_contents($path, $contents);
        $this->temporaryPaths[] = $path;

        return $path;
    }
}
