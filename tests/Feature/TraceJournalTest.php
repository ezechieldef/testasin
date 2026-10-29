<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TraceJournalTest extends TestCase
{
    public function test_request_trace_correlates_identity_and_http_with_separate_intervals(): void
    {
        $report = $this->trace(['--requete' => 'slow1']);

        $this->assertSame(1, $report['requetes']);
        $this->assertSame(['http', 'identite'], array_column($report['evenements'], 'type'));
        $this->assertSame('2026-11-20T00:59:50.500+01:00', $report['evenements'][0]['debut']);
        $this->assertSame('2026-11-20T00:59:54.500+01:00', $report['evenements'][1]['debut']);
        $this->assertSame([9000, 5000], array_column($report['evenements'], 'duree_ms'));
    }

    public function test_npi_filter_preserves_leading_zeros_and_includes_convocation_via_identity(): void
    {
        $report = $this->trace(['--npi' => '0012345678']);

        $this->assertSame(9, $report['requetes']);
        $this->assertContains('/api/inscriptions/1/convocation', array_column($report['evenements'], 'uri'));
        $this->assertSame('0012345678', $report['filtres']['npi']);
    }

    public function test_filters_are_combined_by_and_and_number_does_not_match_refused_requests_without_that_number(): void
    {
        $report = $this->trace(['--numero' => 'P-0001', '--concours' => 'IMPOTS', '--centre' => 'P']);

        $this->assertSame(1, $report['requetes']);
        $this->assertSame(['reg3'], array_column($report['evenements'], 'requete'));
    }

    public function test_time_filter_matches_overlapping_requests_and_keeps_their_entire_correlated_trace(): void
    {
        $report = $this->trace(['--requete' => 'slow1', '--debut' => '2026-11-20 00:59:51', '--fin' => '2026-11-20 00:59:52']);

        $this->assertSame(1, $report['requetes']);
        $this->assertCount(2, $report['evenements']);
        $this->assertSame('2026-11-20T00:59:54.500+01:00', $report['evenements'][1]['debut']);
    }

    public function test_centre_and_competition_are_extracted_from_list_urls(): void
    {
        $report = $this->trace(['--centre' => 'P', '--concours' => 'TRESOR']);

        $this->assertContains('list1', array_column($report['evenements'], 'requete'));
        $this->assertContains('list3', array_column($report['evenements'], 'requete'));
        $this->assertNotContains('list2', array_column($report['evenements'], 'requete'));
    }

    public function test_absent_request_returns_an_empty_successful_trace(): void
    {
        $report = $this->trace(['--requete' => 'absent']);

        $this->assertSame(0, $report['requetes']);
        $this->assertSame([], $report['evenements']);
    }

    public function test_console_trace_is_readable_and_keeps_source_line_numbers(): void
    {
        $this->artisan('journal:tracer', ['journal_path' => base_path('tests/Fixtures/production-log.txt'), '--requete' => 'slow1'])
            ->expectsOutputToContain('SHA-256')
            ->expectsOutputToContain('1 requête(s) ; 2 événement(s).')
            ->expectsOutputToContain('00:59:54.500')
            ->assertExitCode(0);
    }

    public function test_repeated_event_does_not_duplicate_trace_evidence(): void
    {
        $line = '2026-11-19T08:00:00.100Z INFO [req:one] 127.0.0.1 GET /api/centres -> 200 (100 ms)';
        $path = tempnam(sys_get_temp_dir(), 'testasin-trace-');
        file_put_contents($path, $line."\n\n".$line);
        $this->withoutMockingConsoleOutput();

        try {
            $exitCode = Artisan::call('journal:tracer', ['journal_path' => $path, '--requete' => 'one', '--json' => true]);
            $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            unlink($path);
        }

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $report['requetes']);
        $this->assertCount(1, $report['evenements']);
        $this->assertSame(1, $report['evenements'][0]['ligne']);
    }

    #[DataProvider('invalidLogs')]
    public function test_invalid_log_fails_without_silently_omitting_evidence(string $text, string $message): void
    {
        $path = tempnam(sys_get_temp_dir(), 'testasin-invalid-trace-');
        file_put_contents($path, $text);

        try {
            $this->artisan('journal:tracer', ['journal_path' => $path, '--requete' => 'one'])
                ->expectsOutputToContain($message)
                ->assertExitCode(1)
                ->run();
        } finally {
            unlink($path);
        }
    }

    public static function invalidLogs(): array
    {
        return [
            'empty' => ['', 'aucun événement exploitable'],
            'malformed' => ["\nnot a log event", 'Ligne 2 : format de journal non reconnu'],
            'conflicting' => ["2026-11-19T08:00:00.100Z INFO [req:one] 127.0.0.1 GET /api/centres -> 200 (100 ms)\n2026-11-19T08:00:00.200Z INFO [req:one] 127.0.0.1 GET /api/centres -> 200 (200 ms)", 'événements contradictoires'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_return_a_clear_failure(array $filters, string $message): void
    {
        $this->artisan('journal:tracer', ['journal_path' => base_path('tests/Fixtures/production-log.txt')] + $filters)
            ->expectsOutputToContain($message)
            ->assertExitCode(1);
    }

    public static function invalidFilters(): array
    {
        return [
            'no filter' => [[], 'Fournir au moins un filtre reconnu'],
            'invalid date' => [['--debut' => '2026-02-30 12:00:00'], 'Date invalide'],
            'reversed interval' => [['--debut' => '2026-11-20 12:00:00', '--fin' => '2026-11-19 12:00:00'], 'Le début doit précéder la fin'],
        ];
    }

    private function trace(array $filters): array
    {
        $this->withoutMockingConsoleOutput();

        $exitCode = Artisan::call('journal:tracer', ['journal_path' => base_path('tests/Fixtures/production-log.txt'), '--json' => true] + $filters);

        $this->assertSame(0, $exitCode);

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }
}
