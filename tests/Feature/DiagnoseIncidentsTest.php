<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DiagnoseIncidentsTest extends TestCase
{
    public function test_all_incidents_include_procedure_evidence_limits_and_explicit_links_without_database_access(): void
    {
        $report = $this->diagnose();

        $this->assertSame(['INC-201', 'INC-202', 'INC-203', 'INC-204', 'INC-205', 'INC-206', 'INC-207', 'INC-208'], array_keys($report['incidents']));
        $this->assertFalse($report['meta']['base_lue']);
        $this->assertNull($report['base']);
        $this->assertSame(1, $report['incidents']['INC-201']['constats']['numeros_concernes']);
        $this->assertSame(0, $report['incidents']['INC-201']['constats']['groupes_avec_chevauchement']);
        $this->assertSame([], $report['incidents']['INC-201']['preuves'][0]['chevauchements']);
        $this->assertSame(4, $report['incidents']['INC-206']['constats']['requetes_touchees']);
        $this->assertSame(4000, $report['incidents']['INC-206']['preuves'][0]['surcout_http_ms']);
        $this->assertSame('Comportement conforme à la règle métier', $report['incidents']['INC-208']['verdict']);
        $this->assertCount(7, $report['liens']);
        foreach ($report['incidents'] as $incident) {
            $this->assertNotEmpty($incident['procedure']);
            $this->assertNotEmpty($incident['cause']);
            $this->assertNotEmpty($incident['limites']);
        }
    }

    public function test_single_incident_keeps_only_its_related_links_and_explains_the_age_calculation(): void
    {
        $report = $this->diagnose(['--incident' => 'INC-205']);

        $this->assertSame(['INC-205'], array_keys($report['incidents']));
        $this->assertSame([], $report['liens']);
        $this->assertSame(35, $report['incidents']['INC-205']['preuves'][0]['age_au_31_decembre']);
        $this->assertGreaterThan(35.0, $report['incidents']['INC-205']['preuves'][0]['jours_divises_par_365']);
    }

    public function test_overlapping_creations_and_slow_identity_links_are_computed_from_evidence(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'testasin-overlap-');
        file_put_contents($path, implode("\n", [
            '2026-11-19T08:00:06.000Z WARN [req:a] appel identite GET /identite/0012345678 -> 200 (6000 ms)',
            '2026-11-19T08:00:06.010Z INFO [req:a] 127.0.0.1 POST /api/inscriptions -> 201 (6020 ms) concours=DOUANES centre=L npi=0012345678 numero=L-0297',
            '2026-11-19T08:00:07.000Z WARN [req:b] appel identite GET /identite/0012345678 -> 200 (6000 ms)',
            '2026-11-19T08:00:07.010Z INFO [req:b] 127.0.0.1 POST /api/inscriptions -> 201 (6020 ms) concours=DOUANES centre=L npi=0012345678 numero=L-0297',
        ]));

        try {
            $report = $this->diagnose(['journal_path' => $path]);
        } finally {
            unlink($path);
        }

        $this->assertSame(1, $report['incidents']['INC-201']['constats']['groupes_avec_chevauchement']);
        $this->assertSame(1, $report['incidents']['INC-203']['constats']['groupes_avec_chevauchement']);
        $this->assertSame(['a', 'b'], $report['incidents']['INC-201']['preuves'][0]['chevauchements'][0]['requetes']);
        $this->assertSame('2026-11-19T09:00:00.990+01:00', $report['incidents']['INC-201']['preuves'][0]['chevauchements'][0]['debut']);
        $this->assertSame([2, 2, 2, 2, 2, 2, 0], array_column($report['liens'], 'nombre'));
    }

    public function test_optional_database_evidence_counts_all_competitions_and_does_not_modify_records(): void
    {
        Schema::create('centres', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->integer('capacite');
        });
        Schema::create('concours', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->date('date_epreuve');
            $table->dateTime('cloture');
        });
        Schema::create('inscriptions', function (Blueprint $table): void {
            $table->id();
            $table->integer('centre_id');
            $table->integer('concours_id');
            $table->string('npi');
            $table->dateTime('cree_le');
        });
        Schema::create('candidats', function (Blueprint $table): void {
            $table->id();
            $table->string('npi');
        });
        DB::table('centres')->insert(['id' => 1, 'code' => 'L', 'capacite' => 1]);
        DB::table('concours')->insert([
            ['id' => 1, 'code' => 'DOUANES', 'date_epreuve' => '2026-12-12', 'cloture' => '2026-11-30 22:59:00'],
            ['id' => 2, 'code' => 'IMPOTS', 'date_epreuve' => '2026-12-12', 'cloture' => '2026-11-30 22:59:00'],
        ]);
        DB::table('inscriptions')->insert([
            ['id' => 1, 'centre_id' => 1, 'concours_id' => 1, 'npi' => '0012345678', 'cree_le' => '2026-11-18 00:00:00'],
            ['id' => 2, 'centre_id' => 1, 'concours_id' => 2, 'npi' => '0022345678', 'cree_le' => '2026-11-18 00:00:00'],
        ]);
        $before = DB::table('inscriptions')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();

        $path = tempnam(sys_get_temp_dir(), 'testasin-same-day-');
        file_put_contents($path, file_get_contents(base_path('tests/Fixtures/production-log.txt')).'2026-11-19T08:05:00.600Z INFO [req:day] 127.0.0.1 POST /api/inscriptions -> 409 (100 ms) concours=IMPOTS centre=L npi=0012345678 erreur="Vous êtes déjà inscrit à un concours dont l\'épreuve a lieu le même jour."');

        try {
            $report = $this->diagnose(['--base' => true, 'journal_path' => $path]);
        } finally {
            unlink($path);
        }

        $this->assertSame([['code' => 'L', 'capacite' => 1, 'inscriptions' => 2, 'candidats_distincts' => 2, 'depassement' => 1]], $report['base']['centres']);
        $this->assertSame('2026-11-30T23:59:00.000+01:00', $report['base']['concours'][0]['cloture_Benin']);
        $this->assertSame('DOUANES', $report['incidents']['INC-208']['preuves'][0]['antecedents_meme_jour'][0]['concours']);
        $this->assertSame($before, DB::table('inscriptions')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all());
    }

    public function test_console_diagnostic_explains_procedure_and_links(): void
    {
        $this->artisan('incidents:diagnostiquer', ['journal_path' => base_path('tests/Fixtures/production-log.txt'), '--incident' => 'INC-201', '--limite' => '1'])
            ->expectsOutputToContain('Regrouper les HTTP 201 par centre et numéro.')
            ->expectsOutputToContain('Cause / explication issue du dossier')
            ->expectsOutputToContain('Liens entre incidents')
            ->assertExitCode(0);
    }

    public function test_unknown_incident_is_rejected(): void
    {
        $this->artisan('incidents:diagnostiquer', ['journal_path' => base_path('tests/Fixtures/production-log.txt'), '--incident' => 'INC-999'])
            ->expectsOutputToContain('Incident inconnu')
            ->assertExitCode(1);
    }

    public function test_invalid_limit_is_rejected(): void
    {
        $this->artisan('incidents:diagnostiquer', ['journal_path' => base_path('tests/Fixtures/production-log.txt'), '--limite' => '-1'])
            ->expectsOutputToContain('La limite doit être un entier')
            ->assertExitCode(1);
    }

    public function test_missing_case_tables_report_database_failure_instead_of_a_partial_diagnostic(): void
    {
        $this->artisan('incidents:diagnostiquer', ['journal_path' => base_path('tests/Fixtures/production-log.txt'), '--base' => true])
            ->expectsOutputToContain('Lecture de la base impossible')
            ->assertExitCode(1);
    }

    private function diagnose(array $options = []): array
    {
        $this->withoutMockingConsoleOutput();

        $exitCode = Artisan::call('incidents:diagnostiquer', array_replace(['journal_path' => base_path('tests/Fixtures/production-log.txt'), '--json' => true], $options));

        $this->assertSame(0, $exitCode);

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }
}
