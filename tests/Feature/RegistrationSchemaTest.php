<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RegistrationSchemaTest extends TestCase
{
    private array $originalDatabaseConfig;

    private ?string $schema = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDatabaseConfig = config('database');
        $database = getenv('REPAIR_TEST_DATABASE');
        if (! is_string($database) || $database === '') {
            $this->markTestSkipped('Définir REPAIR_TEST_DATABASE pour comparer les protections PostgreSQL.');
        }
        $connection = array_replace(config('database.connections.pgsql'), ['url' => null, 'database' => $database]);
        config(['database.connections.schema_admin' => $connection]);
        $this->schema = 'schema_test_'.bin2hex(random_bytes(8));
        DB::connection('schema_admin')->statement('CREATE SCHEMA '.$this->schema);
        $connection['search_path'] = $this->schema;
        config(['database.default' => 'schema_test', 'database.connections.schema_test' => $connection]);
    }

    protected function tearDown(): void
    {
        if ($this->schema !== null) {
            DB::purge('schema_test');
            DB::connection('schema_admin')->statement('DROP SCHEMA '.$this->schema.' CASCADE');
            DB::purge('schema_admin');
        }
        config(['database' => $this->originalDatabaseConfig]);
        parent::tearDown();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_direct_writes_cannot_duplicate_a_candidate_an_exam_day_or_a_table_number(bool $repairedExport): void
    {
        $this->prepareSchema($repairedExport);
        $this->insertRegistration('0000000001', 1, 1, 'P-0001');

        $this->assertRejected(fn () => $this->insertRegistration('0000000001', 1, 2, 'C-0001'), '23505');
        $this->assertRejected(fn () => $this->insertRegistration('0000000001', 2, 2, 'C-0001'), '23505');
        $this->assertRejected(fn () => $this->insertRegistration('0000000002', 3, 1, 'P-0001'), '23505');
        $this->assertRejected(function (): void {
            DB::table('inscriptions')->update(['date_epreuve' => '2027-01-01', 'concours_id' => 2]);
            $this->insertRegistration('0000000001', 1, 2, 'C-0001');
        }, '23505');

        $this->assertDatabaseCount('inscriptions', 1);
        $this->assertDatabaseHas('inscriptions', ['npi' => '0000000001', 'date_epreuve' => '2026-12-12']);
        $this->assertDatabaseHas('centres', ['id' => 1, 'nb_inscrits' => 1]);
        $this->assertDatabaseHas('centres', ['id' => 2, 'nb_inscrits' => 0]);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_capacity_counters_survive_ignored_duplicates_transfers_and_deletions(bool $repairedExport): void
    {
        $this->prepareSchema($repairedExport);
        $this->insertRegistration('0000000001', 1, 1, 'P-0001');
        DB::table('inscriptions')->insertOrIgnore($this->registration('0000000001', 1, 1, 'P-0002'));
        $this->assertDatabaseHas('centres', ['id' => 1, 'nb_inscrits' => 1]);

        DB::table('inscriptions')->where('npi', '0000000001')->update(['centre_id' => 2, 'numero_table' => 'C-0001']);
        $this->assertDatabaseHas('centres', ['id' => 1, 'nb_inscrits' => 0]);
        $this->assertDatabaseHas('centres', ['id' => 2, 'nb_inscrits' => 1]);
        $this->assertRejected(fn () => $this->insertRegistration('0000000002', 3, 2, 'C-0002'), '23514');
        $this->assertDatabaseCount('inscriptions', 1);

        DB::table('inscriptions')->delete();
        $this->insertRegistration('0000000002', 3, 2, 'C-0002');
        $this->assertDatabaseHas('centres', ['id' => 2, 'nb_inscrits' => 1]);
        $this->assertDatabaseHas('inscriptions', ['npi' => '0000000002', 'date_epreuve' => '2026-12-19']);
    }

    public function test_guard_migration_can_be_rolled_back_and_applied_again(): void
    {
        $this->prepareSchema(false);
        $this->insertRegistration('0000000001', 1, 1, 'P-0001');
        $migration = require database_path('migrations/2026_10_29_172816_strengthen_registration_database_guards.php');

        $migration->down();
        $migration->up();

        $this->assertDatabaseHas('centres', ['id' => 1, 'nb_inscrits' => 1]);
        $this->assertDatabaseHas('inscriptions', ['npi' => '0000000001', 'date_epreuve' => '2026-12-12']);
        $this->assertRejected(fn () => $this->insertRegistration('0000000001', 2, 2, 'C-0001'), '23505');
    }

    private function prepareSchema(bool $repairedExport): void
    {
        if ($repairedExport) {
            DB::unprepared(file_get_contents(base_path('tests/Fixtures/inscriptions-a-reparer.sql')));
            DB::unprepared(file_get_contents(database_path('reparer_inscriptions.sql')));
            DB::table('inscriptions')->delete();
            DB::table('candidats')->delete();
            DB::table('centres')->delete();
            DB::table('concours')->delete();
        } else {
            foreach (['2026_10_29_163450_create_registration_tables.php', '2026_10_29_172816_strengthen_registration_database_guards.php'] as $file) {
                $migration = require database_path('migrations/'.$file);
                $migration->up();
            }
        }

        foreach ([1 => 'DOUANES', 2 => 'IMPOTS', 3 => 'TRESOR'] as $id => $code) {
            DB::table('concours')->insert([
                'id' => $id, 'code' => $code, 'libelle' => $code,
                'date_epreuve' => $id === 3 ? '2026-12-19' : '2026-12-12', 'cloture' => '2026-11-20 22:59:00',
            ]);
        }
        foreach ([1 => 'P', 2 => 'C'] as $id => $code) {
            DB::table('centres')->insert(['id' => $id, 'code' => $code, 'nom' => $code, 'capacite' => 1]);
            DB::table('candidats')->insert([
                'id' => $id, 'npi' => sprintf('%010d', $id), 'nom' => 'Nom', 'prenoms' => 'Prénom',
                'date_naissance' => '2000-01-01', 'cree_le' => '2026-11-18 08:00:00',
            ]);
        }
    }

    private function insertRegistration(string $npi, int $contest, int $centre, string $number): bool
    {
        return DB::table('inscriptions')->insert($this->registration($npi, $contest, $centre, $number));
    }

    /** @return array<string, int|string> */
    private function registration(string $npi, int $contest, int $centre, string $number): array
    {
        return [
            'npi' => $npi, 'concours_id' => $contest, 'centre_id' => $centre,
            'numero_table' => $number, 'statut' => 'en_attente_verification', 'cree_le' => '2026-11-19 08:00:00',
        ];
    }

    private function assertRejected(callable $write, string $sqlState): void
    {
        try {
            DB::transaction($write);
            $this->fail('L’écriture interdite a été acceptée.');
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->errorInfo[0]);
        }
    }
}
