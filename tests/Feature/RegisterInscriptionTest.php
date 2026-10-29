<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RegisterInscriptionTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.identity.url' => 'https://identity.example']);
        Http::preventStrayRequests();
    }

    public function test_registers_the_35_year_old_candidate_and_returns_a_table_number(): void
    {
        $this->prepareCampaign();
        Http::fake(['identity.example/identite/*' => Http::response(['nom' => 'DOSSOU', 'prenoms' => 'Alice'])]);

        $this->postJson('/api/inscriptions', $this->payload())->assertCreated()
            ->assertJsonPath('data.numero_table', 'P-0001')->assertJsonPath('data.statut', 'validee');

        $this->assertDatabaseHas('candidats', ['npi' => '3076016910', 'date_naissance' => '1991-06-20', 'nom' => 'DOSSOU']);
        $this->assertDatabaseHas('inscriptions', ['npi' => '3076016910', 'numero_table' => 'P-0001', 'statut' => 'validee']);
        Http::assertSentCount(1);
    }

    #[TestWith(['1991-01-01', 201])]
    #[TestWith(['1991-12-31', 201])]
    #[TestWith(['1990-12-31', 422])]
    #[TestWith(['1992-02-29', 201])]
    public function test_age_uses_completed_calendar_years(string $birthDate, int $expectedStatus): void
    {
        $this->prepareCampaign();
        Http::fake(['identity.example/identite/*' => Http::response(['nom' => 'DOSSOU', 'prenoms' => 'Alice'])]);
        $this->postJson('/api/inscriptions', $this->payload(['date_naissance' => $birthDate]))->assertStatus($expectedStatus);
        $this->assertDatabaseCount('inscriptions', $expectedStatus === 201 ? 1 : 0);
    }

    #[TestWith(['2026-11-20 22:59:00', 201])]
    #[TestWith(['2026-11-20 23:58:59', 201])]
    #[TestWith(['2026-11-20 23:59:00', 201])]
    #[TestWith(['2026-11-20 23:59:01', 422])]
    public function test_closing_time_is_inclusive_in_benin_time(string $localTime, int $expectedStatus): void
    {
        $this->prepareCampaign();
        config(['app.timezone' => 'Africa/Porto-Novo']);
        $this->travelTo(CarbonImmutable::parse($localTime, 'Africa/Porto-Novo'));
        Http::fake(['identity.example/identite/*' => Http::response(['nom' => 'DOSSOU', 'prenoms' => 'Alice'])]);
        $this->postJson('/api/inscriptions', $this->payload())->assertStatus($expectedStatus);
        $this->assertDatabaseCount('inscriptions', $expectedStatus === 201 ? 1 : 0);
    }

    public function test_connection_timeout_records_pending_verification_without_leaking_the_transaction(): void
    {
        $this->prepareCampaign();
        Http::fake(['identity.example/identite/*' => Http::failedConnection()]);
        $this->postJson('/api/inscriptions', $this->payload())->assertCreated()
            ->assertJsonPath('data.statut', 'en_attente_verification');
        $this->assertDatabaseHas('inscriptions', ['statut' => 'en_attente_verification']);
        $this->assertDatabaseHas('candidats', ['npi' => '3076016910', 'nom' => '']);
        Http::assertSentCount(1);
    }

    #[TestWith([503, []])]
    #[TestWith([302, []])]
    #[TestWith([200, ['nom' => 'Incomplete']])]
    public function test_unavailable_or_invalid_identity_remains_pending(int $status, array $body): void
    {
        $this->prepareCampaign();
        Http::fake(['identity.example/identite/*' => Http::response($body, $status)]);
        $this->postJson('/api/inscriptions', $this->payload())->assertCreated()
            ->assertJsonPath('data.statut', 'en_attente_verification');
        $this->assertDatabaseCount('inscriptions', 1);
        Http::assertSentCount(1);
    }

    public function test_unknown_identity_is_rejected_without_creating_a_candidate(): void
    {
        $this->prepareCampaign();
        Http::fake(['identity.example/identite/*' => Http::response([], 404)]);
        $this->postJson('/api/inscriptions', $this->payload())->assertUnprocessable()
            ->assertJsonPath('message', 'NPI inconnu du service d’identification.');
        $this->assertDatabaseCount('candidats', 0);
        $this->assertDatabaseCount('inscriptions', 0);
        Http::assertSentCount(1);
    }

    public function test_real_http_transport_stops_a_slow_service_after_five_seconds(): void
    {
        $this->prepareCampaign();
        $process = proc_open([PHP_BINARY, base_path('tests/Fixtures/slow-identity-server.php')], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        $this->assertIsResource($process);
        try {
            stream_set_timeout($pipes[1], 10);
            $address = trim(fgets($pipes[1]));
            $this->assertNotSame('', $address);
            config(['services.identity.url' => 'http://'.$address]);
            Http::allowStrayRequests(['http://'.$address.'/*']);
            $start = hrtime(true);

            $this->postJson('/api/inscriptions', $this->payload())->assertCreated()
                ->assertJsonPath('data.statut', 'en_attente_verification');

            $elapsed = (hrtime(true) - $start) / 1_000_000_000;
            $this->assertGreaterThanOrEqual(4.5, $elapsed);
            $this->assertLessThan(5.8, $elapsed, 'La marge couvre le traitement HTTP et SQL autour du délai réseau de 5 secondes.');
            $this->assertDatabaseHas('inscriptions', ['statut' => 'en_attente_verification']);
        } finally {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }
    }

    public function test_identity_lookup_does_not_hold_registration_locks_and_does_not_change_the_admission_time(): void
    {
        $this->prepareCampaign();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 22:59:00', 'UTC'));
        $transactionLevel = DB::transactionLevel();
        Http::fake(['identity.example/identite/*' => function () use ($transactionLevel) {
            $this->assertSame($transactionLevel, DB::transactionLevel());
            $this->travel(5)->seconds();

            return Http::response(['nom' => 'DOSSOU', 'prenoms' => 'Alice']);
        }]);

        $this->postJson('/api/inscriptions', $this->payload())->assertCreated();

        $this->assertDatabaseHas('inscriptions', ['cree_le' => '2026-11-20 22:59:05']);
        Http::assertSentCount(1);
    }

    public function test_numbers_continue_after_the_largest_existing_number_across_contests(): void
    {
        $this->prepareCampaign();
        Http::fake(['identity.example/identite/*' => Http::response(['nom' => 'DOSSOU', 'prenoms' => 'Alice'])]);
        $this->postJson('/api/inscriptions', $this->payload())->assertCreated();
        DB::table('inscriptions')->update(['numero_table' => 'P-0464']);

        $this->postJson('/api/inscriptions', $this->payload(['npi' => '3076016911']))->assertCreated()
            ->assertJsonPath('data.numero_table', 'P-0465');

        $this->assertDatabaseHas('inscriptions', ['npi' => '3076016911', 'numero_table' => 'P-0465']);
    }

    public function test_failure_to_insert_the_registration_rolls_back_the_new_candidate(): void
    {
        $this->prepareCampaign();
        DB::statement("CREATE TRIGGER refuse_registration BEFORE INSERT ON inscriptions BEGIN SELECT RAISE(ABORT, 'simulated failure'); END");
        Http::fake(['identity.example/identite/*' => Http::response(['nom' => 'DOSSOU', 'prenoms' => 'Alice'])]);

        $this->postJson('/api/inscriptions', $this->payload())->assertInternalServerError();

        $this->assertDatabaseCount('candidats', 0);
        $this->assertDatabaseCount('inscriptions', 0);
        Http::assertSentCount(1);
    }

    public function test_duplicate_submission_is_rejected_with_409(): void
    {
        $this->prepareCampaign();
        Http::fake(['identity.example/identite/*' => Http::response(['nom' => 'DOSSOU', 'prenoms' => 'Alice'])]);
        $this->postJson('/api/inscriptions', $this->payload())->assertCreated();
        $this->postJson('/api/inscriptions', $this->payload())->assertConflict();
        $this->assertDatabaseCount('candidats', 1);
        $this->assertDatabaseCount('inscriptions', 1);
    }

    public function test_missing_identity_configuration_returns_503_without_creating_records(): void
    {
        $this->prepareCampaign();
        config(['services.identity.url' => null]);

        $this->postJson('/api/inscriptions', $this->payload())->assertServiceUnavailable();

        $this->assertDatabaseCount('candidats', 0);
        $this->assertDatabaseCount('inscriptions', 0);
        Http::assertNothingSent();
    }

    public function test_empty_payload_returns_400_with_all_required_fields(): void
    {
        $this->postJson('/api/inscriptions', [])->assertBadRequest()
            ->assertJsonValidationErrors(['npi', 'date_naissance', 'concours', 'centre']);

        Http::assertNothingSent();
        $this->assertDatabaseCount('inscriptions', 0);
    }

    #[TestWith(['2026-12-01', 409])]
    #[TestWith(['2026-12-02', 201])]
    public function test_another_contest_depends_on_the_exam_day_even_in_another_centre(string $examDate, int $status): void
    {
        $this->prepareCampaign();
        DB::table('concours')->insert(['code' => 'IMPOTS', 'libelle' => 'Impôts', 'date_epreuve' => $examDate, 'cloture' => '2026-11-20 22:59:00']);
        DB::table('centres')->insert(['code' => 'L', 'nom' => 'Lokossa', 'capacite' => 1]);
        Http::fake(['identity.example/identite/*' => Http::response(['nom' => 'DOSSOU', 'prenoms' => 'Alice'])]);
        $this->postJson('/api/inscriptions', $this->payload())->assertCreated();
        $this->postJson('/api/inscriptions', $this->payload(['concours' => 'IMPOTS', 'centre' => 'L']))->assertStatus($status);
        $this->assertDatabaseCount('inscriptions', $status === 201 ? 2 : 1);
        $this->assertDatabaseCount('candidats', 1);
    }

    public function test_capacity_counts_all_contests_and_pending_registrations(): void
    {
        $this->prepareCampaign(1);
        DB::table('concours')->insert(['code' => 'IMPOTS', 'libelle' => 'Impôts', 'date_epreuve' => '2026-12-02', 'cloture' => '2026-11-20 22:59:00']);
        Http::fake(['identity.example/identite/*' => Http::failedConnection()]);
        $this->postJson('/api/inscriptions', $this->payload())->assertCreated();
        $this->postJson('/api/inscriptions', $this->payload(['npi' => '3076016911', 'concours' => 'IMPOTS']))
            ->assertConflict()->assertJsonPath('message', 'La capacité du centre est atteinte.');
        $this->assertDatabaseCount('inscriptions', 1);
        $this->assertDatabaseCount('candidats', 1);
    }

    public function test_existing_birth_date_cannot_be_replaced(): void
    {
        $this->prepareCampaign();
        DB::table('candidats')->insert(['npi' => '3076016910', 'nom' => 'DOSSOU', 'prenoms' => 'Alice', 'date_naissance' => '1990-01-01', 'cree_le' => '2025-01-01 00:00:00']);
        Http::fake(['identity.example/identite/*' => Http::response(['nom' => 'DOSSOU', 'prenoms' => 'Alice'])]);
        $this->postJson('/api/inscriptions', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('inscriptions', 0);
        $this->assertDatabaseHas('candidats', ['date_naissance' => '1990-01-01']);
    }

    #[TestWith(['npi', '12'])]
    #[TestWith(['date_naissance', '1991-02-30'])]
    #[TestWith(['date_naissance', '2027-01-01'])]
    #[TestWith(['concours', 'ABSENT'])]
    #[TestWith(['centre', 'X'])]
    public function test_invalid_input_returns_400_before_identity_lookup(string $field, string $value): void
    {
        $this->prepareCampaign();
        $this->postJson('/api/inscriptions', $this->payload([$field => $value]))->assertBadRequest()
            ->assertJsonValidationErrors($field);
        Http::assertNothingSent();
        $this->assertDatabaseCount('inscriptions', 0);
    }

    private function prepareCampaign(int $capacity = 5): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 20:00:00', 'UTC'));
        DB::table('concours')->insert(['code' => 'TRESOR', 'libelle' => 'Trésor', 'date_epreuve' => '2026-12-01', 'cloture' => '2026-11-20 22:59:00']);
        DB::table('centres')->insert(['code' => 'P', 'nom' => 'Parakou', 'capacite' => $capacity]);
    }

    /** @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace(['npi' => '3076016910', 'date_naissance' => '1991-06-20', 'concours' => 'TRESOR', 'centre' => 'P'], $overrides);
    }
}
