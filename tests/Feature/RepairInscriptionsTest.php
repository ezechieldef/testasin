<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RepairInscriptionsTest extends TestCase
{
    private array $originalDatabaseConfig;

    private ?string $schema = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDatabaseConfig = config('database');
        $database = getenv('REPAIR_TEST_DATABASE');
        if (! is_string($database) || $database === '') {
            $this->markTestSkipped('Définir REPAIR_TEST_DATABASE pour les tests SQL PostgreSQL en schéma isolé.');
        }
        $connection = array_replace(config('database.connections.pgsql'), ['url' => null, 'database' => $database]);
        config(['database.connections.repair_admin' => $connection]);
        $this->schema = 'repair_test_'.bin2hex(random_bytes(8));
        DB::connection('repair_admin')->statement('CREATE SCHEMA '.$this->schema);
        $connection['search_path'] = $this->schema;
        config(['database.default' => 'repair_test', 'database.connections.repair_test' => $connection]);
        DB::unprepared(file_get_contents(base_path('tests/Fixtures/inscriptions-a-reparer.sql')));
    }

    protected function tearDown(): void
    {
        if ($this->schema !== null) {
            DB::unprepared('ROLLBACK');
            DB::purge('repair_test');
            DB::connection('repair_admin')->statement('DROP SCHEMA '.$this->schema.' CASCADE');
            DB::purge('repair_admin');
        }
        config(['database' => $this->originalDatabaseConfig]);
        parent::tearDown();
    }

    public function test_repairs_only_the_duplicates_preserves_lokossa_and_is_safe_to_replay(): void
    {
        $this->repair();

        $this->assertDatabaseCount('inscriptions', 5);
        $this->assertDatabaseHas('reparation_doublons_inscriptions', ['inscription_id' => 11, 'inscription_conservee_id' => 10]);
        $this->assertDatabaseCount('reparation_numeros_table', 2);
        $this->assertDatabaseHas('inscriptions', ['id' => 10, 'numero_table' => 'P-0001']);
        $this->assertDatabaseHas('inscriptions', ['id' => 12, 'numero_table' => 'P-0002']);
        $this->assertDatabaseHas('inscriptions', ['id' => 21, 'numero_table' => 'L-0003']);
        $this->assertDatabaseHas('centres', ['code' => 'L', 'capacite' => 2, 'nb_inscrits' => 3]);
        $this->assertDatabaseCount('reparation_lokossa_arbitrage', 3);
        $this->assertDatabaseHas('reparation_lokossa_arbitrage', ['inscription_id' => 22, 'places_a_arbitrer' => 1, 'inscription_heure_benin' => '2026-11-19 09:00:02']);
        $before = $this->snapshot();

        $this->repair();

        $this->assertSame($before, $this->snapshot());
    }

    public function test_original_export_is_repaired_without_changing_candidates_or_lokossa_membership(): void
    {
        $export = getenv('REPAIR_TEST_EXPORT');
        if (! is_string($export) || $export === '') {
            $this->markTestSkipped('Définir REPAIR_TEST_EXPORT avec le chemin de l’export original du sujet.');
        }
        $this->assertFileIsReadable($export);
        $this->assertSame('f4e5771ff0dda8d7d0091d97c6456f2a1f43e4ce74c713c480c5e9a317290a37', hash_file('sha256', $export));
        DB::unprepared(file_get_contents($export));
        $this->assertDatabaseCount('inscriptions', 6400);
        $candidates = hash('sha256', DB::table('candidats')->orderBy('id')->get()->toJson());
        $lokossaId = DB::table('centres')->where('code', 'L')->value('id');
        $lokossaRegistrations = DB::table('inscriptions')->where('centre_id', $lokossaId)->orderBy('id')->pluck('id')->all();

        $this->repair();

        $this->assertDatabaseCount('inscriptions', 6393);
        $this->assertDatabaseCount('reparation_doublons_inscriptions', 7);
        $this->assertDatabaseCount('reparation_numeros_table', 15);
        $this->assertDatabaseCount('reparation_lokossa_arbitrage', 307);
        $this->assertDatabaseHas('centres', ['code' => 'L', 'capacite' => 300, 'nb_inscrits' => 307]);
        $this->assertSame($lokossaRegistrations, DB::table('inscriptions')->where('centre_id', $lokossaId)->orderBy('id')->pluck('id')->all());
        $this->assertSame($candidates, hash('sha256', DB::table('candidats')->orderBy('id')->get()->toJson()));
        $beforeReplay = array_map(fn (string $value): string => hash('sha256', $value), $this->snapshot());

        $this->repair();

        $this->assertSame($beforeReplay, array_map(fn (string $value): string => hash('sha256', $value), $this->snapshot()));
    }

    public function test_duplicate_registration_is_rejected_and_the_capacity_counter_is_rolled_back(): void
    {
        $this->repair();
        $this->assertRejected("INSERT INTO inscriptions (concours_id,centre_id,npi,numero_table,statut,cree_le) VALUES (1,1,'0000000001','P-0003','validee',now())", '23505', 'inscriptions_npi_concours_unique');
        $this->assertDatabaseHas('centres', ['id' => 1, 'nb_inscrits' => 2]);
        $this->assertDatabaseCount('inscriptions', 5);
    }

    public function test_duplicate_number_is_rejected_across_contests(): void
    {
        $this->repair();
        $this->assertRejected("INSERT INTO inscriptions (concours_id,centre_id,npi,numero_table,statut,cree_le) VALUES (3,1,'0000000001','P-0001','validee',now())", '23505', 'inscriptions_centre_numero_unique');
        $this->assertDatabaseHas('centres', ['id' => 1, 'nb_inscrits' => 2]);
    }

    public function test_ignored_or_updated_duplicates_do_not_consume_another_place(): void
    {
        $this->repair();
        DB::statement("INSERT INTO inscriptions (concours_id,centre_id,npi,numero_table,statut,cree_le) VALUES (1,1,'0000000001','P-0003','validee',now()) ON CONFLICT (npi,concours_id) DO NOTHING");
        DB::statement("INSERT INTO inscriptions (concours_id,centre_id,npi,numero_table,statut,cree_le) VALUES (1,1,'0000000001','P-0003','en_attente_verification',now()) ON CONFLICT (npi,concours_id) DO UPDATE SET statut=excluded.statut");

        $this->assertDatabaseCount('inscriptions', 5);
        $this->assertDatabaseHas('centres', ['id' => 1, 'nb_inscrits' => 2]);
        $this->assertDatabaseHas('inscriptions', ['id' => 10, 'statut' => 'en_attente_verification']);
    }

    public function test_same_exam_day_is_rejected_in_another_centre_even_with_a_forged_date(): void
    {
        $this->repair();
        $this->assertRejected("INSERT INTO inscriptions (concours_id,centre_id,npi,numero_table,statut,cree_le,date_epreuve) VALUES (2,3,'0000000001','C-0001','validee',now(),'2027-01-01')", '23505', 'inscriptions_npi_date_unique');
        $this->assertDatabaseHas('centres', ['id' => 3, 'nb_inscrits' => 0]);
    }

    public function test_lokossa_cannot_accept_another_registration_until_a_decision_is_applied(): void
    {
        $this->repair();
        $this->assertRejected("INSERT INTO inscriptions (concours_id,centre_id,npi,numero_table,statut,cree_le) VALUES (3,2,'0000000001','L-0004','en_attente_verification',now())", '23514');
        $this->assertDatabaseHas('centres', ['id' => 2, 'capacite' => 2, 'nb_inscrits' => 3]);
        $this->assertDatabaseCount('reparation_lokossa_arbitrage', 3);
    }

    public function test_different_exam_day_is_accepted_with_the_generated_id_and_date(): void
    {
        $this->repair();
        $id = DB::table('inscriptions')->insertGetId([
            'concours_id' => 3, 'centre_id' => 1, 'npi' => '0000000001',
            'numero_table' => 'P-0003', 'statut' => 'validee', 'cree_le' => '2026-11-20 12:00:00',
        ]);
        $this->assertSame(23, $id);
        $this->assertDatabaseHas('inscriptions', ['id' => 23, 'date_epreuve' => '2026-12-19']);
        $this->assertDatabaseHas('centres', ['id' => 1, 'nb_inscrits' => 3]);
        $candidateId = DB::table('candidats')->insertGetId([
            'npi' => '0000000006', 'nom' => 'Nom6', 'prenoms' => 'Prenom6',
            'date_naissance' => '2000-01-01', 'cree_le' => '2026-11-20 12:00:00',
        ]);
        $this->assertSame(6, $candidateId);
        $this->repair();
        $this->assertSame(24, DB::selectOne("SELECT nextval(pg_get_serial_sequence('inscriptions','id')) AS id")->id);
    }

    public function test_transfers_and_deletions_keep_counters_consistent_and_a_failed_transfer_changes_nothing(): void
    {
        $this->repair();
        $this->assertRejected("UPDATE inscriptions SET centre_id=2,numero_table='L-0004' WHERE id=12", '23514');
        $this->assertDatabaseHas('centres', ['id' => 1, 'nb_inscrits' => 2]);
        DB::statement("UPDATE inscriptions SET centre_id=3,numero_table='C-0001' WHERE id=12");
        $this->assertDatabaseHas('centres', ['id' => 1, 'nb_inscrits' => 1]);
        $this->assertDatabaseHas('centres', ['id' => 3, 'nb_inscrits' => 1]);
        DB::table('inscriptions')->where('id', 12)->delete();
        $this->assertDatabaseHas('centres', ['id' => 3, 'nb_inscrits' => 0]);
    }

    public function test_generated_ids_never_reuse_the_id_of_an_archived_duplicate(): void
    {
        DB::table('inscriptions')->where('id', 11)->update(['id' => 100]);
        $this->repair();

        $id = DB::table('inscriptions')->insertGetId([
            'concours_id' => 3, 'centre_id' => 1, 'npi' => '0000000001',
            'numero_table' => 'P-0003', 'statut' => 'validee', 'cree_le' => '2026-11-20 12:00:00',
        ]);

        $this->assertSame(101, $id);
        $this->repair();
        $this->assertDatabaseHas('inscriptions', ['id' => 101]);
        $this->assertDatabaseHas('reparation_doublons_inscriptions', ['inscription_id' => 100]);
    }

    public function test_the_registration_api_remains_compatible_with_the_repaired_export(): void
    {
        $this->repair();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 20:00:00', 'UTC'));
        config(['services.identity.url' => 'https://identity.example']);
        Http::preventStrayRequests();
        Http::fake(['identity.example/identite/*' => Http::response(['nom' => 'Nom6', 'prenoms' => 'Prenom6'])]);

        $this->postJson('/api/inscriptions', [
            'npi' => '0000000006', 'date_naissance' => '2000-01-01', 'concours' => 'TRESOR', 'centre' => 'C',
        ])->assertCreated()->assertJsonPath('data.numero_table', 'C-0001');

        $this->assertDatabaseHas('inscriptions', ['id' => 23, 'npi' => '0000000006', 'date_epreuve' => '2026-12-19']);
        $this->assertDatabaseHas('centres', ['id' => 3, 'nb_inscrits' => 1]);
        Http::assertSentCount(1);
    }

    public function test_ambiguous_lokossa_duplicate_aborts_without_removing_any_registration(): void
    {
        DB::table('inscriptions')->insert(['id' => 30, 'concours_id' => 1, 'centre_id' => 2, 'npi' => '0000000003', 'numero_table' => 'L-0003', 'statut' => 'validee', 'cree_le' => '2026-11-20 12:00:00']);
        $this->assertRepairAborts('Double candidature impliquant Lokossa');
        $this->assertDatabaseCount('inscriptions', 7);
        $this->assertDatabaseHas('inscriptions', ['id' => 11]);
    }

    public function test_a_late_foreign_key_failure_rolls_back_deletions_and_renumbering(): void
    {
        DB::table('inscriptions')->where('id', 12)->update(['npi' => '9999999999']);
        $this->assertRepairAborts('inscriptions_npi_foreign');
        $this->assertDatabaseCount('inscriptions', 6);
        $this->assertDatabaseHas('inscriptions', ['id' => 11]);
        $this->assertDatabaseHas('inscriptions', ['id' => 21, 'numero_table' => 'L-0001']);
        $this->assertNull(DB::selectOne("SELECT to_regclass('reparation_doublons_inscriptions') AS table_name")->table_name);
    }

    #[TestWith(["UPDATE inscriptions SET numero_table='INVAL' WHERE id=12", 'Numéro de table mal formé'])]
    #[TestWith(["INSERT INTO candidats VALUES (6,'0000000001','Autre','Identité','1991-06-20',now())", 'NPI candidat dupliqué'])]
    #[TestWith(["INSERT INTO inscriptions VALUES (30,2,3,'0000000001','C-0001','validee',now())", 'Concours différents le même jour'])]
    public function test_ambiguous_input_aborts_before_any_repair(string $mutation, string $message): void
    {
        DB::statement($mutation);

        $this->assertRepairAborts($message);

        $this->assertDatabaseHas('inscriptions', ['id' => 11]);
        $this->assertNull(DB::selectOne("SELECT to_regclass('reparation_doublons_inscriptions') AS table_name")->table_name);
    }

    public function test_concurrent_sql_writes_cannot_exceed_the_last_place(): void
    {
        $this->repair();
        DB::table('centres')->where('id', 3)->update(['capacite' => 1]);
        $workers = [];
        try {
            foreach (['0000000001', '0000000002'] as $index => $npi) {
                $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/repair-sql-worker.php')], [
                    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
                ], $pipes, base_path());
                $this->assertIsResource($process);
                stream_set_timeout($pipes[1], 15);
                $workers[] = [$process, $pipes];
                fwrite($pipes[0], json_encode([
                    'connection' => config('database.connections.repair_test'),
                    'sql' => "INSERT INTO inscriptions (concours_id,centre_id,npi,numero_table,statut,cree_le) VALUES (3,3,'{$npi}','C-000".($index + 1)."','validee',now())",
                ], JSON_THROW_ON_ERROR)."\n");
                fflush($pipes[0]);
            }
            foreach ($workers as [$process, $pipes]) {
                $this->assertSame("READY\n", fgets($pipes[1]));
            }
            foreach ($workers as [$process, $pipes]) {
                fwrite($pipes[0], "GO\n");
                fflush($pipes[0]);
            }
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $results[] = trim(fgets($pipes[1]));
            }
            sort($results);
            $this->assertSame(['23514', 'ok'], $results);
            $this->assertSame(1, DB::table('inscriptions')->where('centre_id', 3)->count());
            $this->assertDatabaseHas('centres', ['id' => 3, 'nb_inscrits' => 1]);
        } finally {
            foreach ($workers as [$process, $pipes]) {
                proc_terminate($process);
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                proc_close($process);
            }
        }
    }

    private function repair(): void
    {
        DB::unprepared(file_get_contents(database_path('reparer_inscriptions.sql')));
    }

    private function assertRejected(string $sql, string $sqlState, ?string $constraint = null): void
    {
        try {
            DB::statement($sql);
            $this->fail('L’écriture interdite a été acceptée.');
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0]);
            if ($constraint !== null) {
                $this->assertStringContainsString($constraint, $exception->getMessage());
            }
        }
    }

    private function assertRepairAborts(string $message): void
    {
        try {
            $this->repair();
            $this->fail('La réparation ambiguë aurait dû être annulée.');
        } catch (QueryException $exception) {
            DB::unprepared('ROLLBACK');
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    /** @return array<string, string> */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['inscriptions', 'centres', 'candidats', 'reparation_doublons_inscriptions', 'reparation_numeros_table', 'reparation_lokossa_initiale'] as $table) {
            $key = str_starts_with($table, 'reparation_') ? 'inscription_id' : 'id';
            $snapshot[$table] = json_encode(DB::table($table)->orderBy($key)->get(), JSON_THROW_ON_ERROR);
        }

        return $snapshot;
    }
}
