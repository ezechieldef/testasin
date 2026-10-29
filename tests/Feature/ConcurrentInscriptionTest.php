<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConcurrentInscriptionTest extends TestCase
{
    private array $originalDatabaseConfig;

    private ?string $temporaryDatabase = null;

    private ?string $temporarySchema = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDatabaseConfig = config('database');
        $connection = config('database.connections.'.config('database.default'));
        if ($connection['driver'] === 'pgsql') {
            $this->temporarySchema = 'registration_test_'.bin2hex(random_bytes(8));
            DB::statement('CREATE SCHEMA '.$this->temporarySchema);
            $connection['search_path'] = $this->temporarySchema;
        } else {
            $this->temporaryDatabase = tempnam(sys_get_temp_dir(), 'registration_');
            $connection = array_replace(config('database.connections.sqlite'), [
                'url' => null, 'database' => $this->temporaryDatabase, 'busy_timeout' => 10000,
            ]);
        }
        config(['database.default' => 'registration_test', 'database.connections.registration_test' => $connection]);
        $migration = require database_path('migrations/2026_10_29_163450_create_registration_tables.php');
        $migration->up();
    }

    protected function tearDown(): void
    {
        DB::purge('registration_test');
        config(['database' => $this->originalDatabaseConfig]);
        if ($this->temporarySchema !== null) {
            DB::statement('DROP SCHEMA '.$this->temporarySchema.' CASCADE');
        }
        if ($this->temporaryDatabase !== null) {
            @unlink($this->temporaryDatabase);
            @unlink($this->temporaryDatabase.'-wal');
            @unlink($this->temporaryDatabase.'-shm');
        }
        parent::tearDown();
    }

    public function test_simultaneous_requests_never_overbook_the_last_places_or_duplicate_numbers(): void
    {
        $this->prepareCampaign(2);
        $responses = $this->sendSimultaneously([
            $this->payload('3076016910'), $this->payload('3076016911'),
            $this->payload('3076016912', 'IMPOTS'), $this->payload('3076016913', 'IMPOTS'),
        ]);
        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([201, 201, 409, 409], $statuses, json_encode($responses));
        $this->assertDatabaseCount('inscriptions', 2);
        $this->assertDatabaseCount('candidats', 2);
        $this->assertSame(['P-0001', 'P-0002'], DB::table('inscriptions')->orderBy('numero_table')->pluck('numero_table')->all());
    }

    public function test_simultaneous_duplicate_submissions_create_exactly_one_registration(): void
    {
        $this->prepareCampaign(5);
        $responses = $this->sendSimultaneously([$this->payload('3076016910'), $this->payload('3076016910')]);
        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([201, 409], $statuses, json_encode($responses));
        $this->assertDatabaseCount('inscriptions', 1);
        $this->assertDatabaseCount('candidats', 1);
    }

    public function test_same_npi_in_different_centres_cannot_register_for_two_exams_on_the_same_day(): void
    {
        $this->prepareCampaign(5);
        DB::table('centres')->insert(['code' => 'L', 'nom' => 'Lokossa', 'capacite' => 5]);
        $responses = $this->sendSimultaneously([
            $this->payload('3076016910'), $this->payload('3076016910', 'IMPOTS', 'L'),
        ]);
        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([201, 409], $statuses, json_encode($responses));
        $this->assertDatabaseCount('inscriptions', 1);
        $this->assertDatabaseCount('candidats', 1);
    }

    private function prepareCampaign(int $capacity): void
    {
        foreach (['TRESOR', 'IMPOTS'] as $code) {
            DB::table('concours')->insert(['code' => $code, 'libelle' => $code, 'date_epreuve' => '2026-12-01', 'cloture' => '2099-11-20 22:59:00']);
        }
        DB::table('centres')->insert(['code' => 'P', 'nom' => 'Parakou', 'capacite' => $capacity]);
    }

    /** @return array<string, string> */
    private function payload(string $npi, string $contest = 'TRESOR', string $centre = 'P'): array
    {
        return ['npi' => $npi, 'date_naissance' => '2000-06-20', 'concours' => $contest, 'centre' => $centre];
    }

    /**
     * @param  list<array<string, string>>  $payloads
     * @return list<array{status: int, body: array}>
     */
    private function sendSimultaneously(array $payloads): array
    {
        $workers = [];
        try {
            foreach ($payloads as $payload) {
                $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/registration-worker.php')], [
                    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
                ], $pipes, base_path());
                $this->assertIsResource($process);
                stream_set_timeout($pipes[1], 15);
                $workers[] = [$process, $pipes];
                fwrite($pipes[0], json_encode([
                    'connection' => config('database.connections.registration_test'), 'payload' => $payload,
                ], JSON_THROW_ON_ERROR)."\n");
                fflush($pipes[0]);
            }
            foreach ($workers as [$process, $pipes]) {
                $this->assertSame("READY\n", fgets($pipes[1]), 'Le processus n’a pas atteint la barrière.');
            }
            foreach ($workers as [$process, $pipes]) {
                fwrite($pipes[0], "GO\n");
                fflush($pipes[0]);
            }
            $responses = [];
            foreach ($workers as [$process, $pipes]) {
                $output = fgets($pipes[1]);
                $this->assertIsString($output);
                $responses[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            }

            return $responses;
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
}
