<?php

namespace Tests\Feature;

use App\Services\ProductionLogAnalyzer;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AnalyzeProductionLogTest extends TestCase
{
    public function test_command_displays_the_six_indicators(): void
    {
        $this->artisan('journal:analyser', ['journal_path' => base_path('tests/Fixtures/production-log.txt')])
            ->expectsOutputToContain('Africa/Porto-Novo')
            ->expectsOutputToContain('1 numéros concernés.')
            ->expectsOutputToContain('1 candidatures concernées.')
            ->expectsOutputToContain('4 requêtes distinctes touchées.')
            ->expectsOutputToContain('2 refus ; 1 candidats distincts.')
            ->expectsOutputToContain('4 refus ; 3 candidats distincts.')
            ->expectsOutputToContain('Durée des listes par centre')
            ->assertExitCode(0);
    }

    public function test_json_output_exposes_the_complete_reusable_report_without_console_decoration(): void
    {
        $path = base_path('tests/Fixtures/production-log.txt');
        $this->withoutMockingConsoleOutput();

        $exitCode = Artisan::call('journal:analyser', ['journal_path' => $path, '--json' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame((new ProductionLogAnalyzer)->analyze($path)->toArray(), json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_missing_file_returns_a_failure_with_an_actionable_message(): void
    {
        $this->artisan('journal:analyser', ['journal_path' => base_path('tests/Fixtures/absent.txt')])
            ->expectsOutputToContain('Le journal doit être un fichier local lisible.')
            ->assertExitCode(1);
    }

    public function test_invalid_year_is_rejected_instead_of_silently_cast_to_an_integer(): void
    {
        $this->artisan('journal:analyser', ['journal_path' => base_path('tests/Fixtures/production-log.txt'), '--annee' => '2026abc'])
            ->expectsOutputToContain('L’année du concours doit comporter quatre chiffres.')
            ->assertExitCode(1);
    }

    public function test_closing_option_changes_the_refusal_count(): void
    {
        $this->artisan('journal:analyser', ['journal_path' => base_path('tests/Fixtures/production-log.txt'), '--cloture-tresor' => '2026-11-20 23:58:00'])
            ->expectsOutputToContain('0 refus ; 0 candidats distincts.')
            ->assertExitCode(0);
    }
}
