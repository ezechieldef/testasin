<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;
use stdClass;

class RegisterInscription
{
    public function __construct(private IdentityClient $identityClient) {}

    /** @return array{id: int, numero_table: string, statut: string} */
    public function register(string $npi, string $birthDate, string $contestCode, string $centreCode): array
    {
        $receivedAt = CarbonImmutable::now('UTC');
        $contest = DB::table('concours')->where('code', $contestCode)->first();
        abort_if($contest === null, 400, 'Concours inconnu.');
        $this->checkEligibility($contest, $birthDate, $receivedAt);
        $identity = $this->identityClient->verify($npi);

        return DB::transaction(function () use ($npi, $birthDate, $contestCode, $centreCode, $receivedAt, $identity): array {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'pgsql') {
                /** Sérialise le NPI même si le candidat n’existe pas encore, dans tous les centres. */
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$npi]);
            } elseif ($driver === 'sqlite') {
                /** Première opération d’écriture : SQLite sérialise les transactions concurrentes. */
                DB::table('centres')->where('code', $centreCode)->update(['capacite' => DB::raw('capacite')]);
            } else {
                throw new LogicException('L’inscription nécessite PostgreSQL ou SQLite.');
            }
            $contest = DB::table('concours')->where('code', $contestCode)->sharedLock()->first();
            abort_if($contest === null, 400, 'Concours inconnu.');
            $this->checkEligibility($contest, $birthDate, $receivedAt);
            $centre = DB::table('centres')->where('code', $centreCode)->lockForUpdate()->first();
            abort_if($centre === null, 400, 'Centre inconnu.');

            $alreadyRegistered = DB::table('inscriptions')
                ->join('concours', 'concours.id', '=', 'inscriptions.concours_id')
                ->where('inscriptions.npi', $npi)
                ->where(function (Builder $query) use ($contest): void {
                    $query->where('concours.id', $contest->id)->orWhere('concours.date_epreuve', $contest->date_epreuve);
                })->exists();
            abort_if($alreadyRegistered, 409, 'Une inscription existe déjà pour ce concours ou ce jour d’épreuve.');
            abort_if(DB::table('inscriptions')->where('centre_id', $centre->id)->count() >= $centre->capacite,
                409, 'La capacité du centre est atteinte.');
            $candidate = DB::table('candidats')->where('npi', $npi)->first();
            abort_if($candidate !== null && $candidate->date_naissance !== $birthDate,
                422, 'La date de naissance ne correspond pas au candidat enregistré.');

            $maximum = DB::table('inscriptions')->where('centre_id', $centre->id)
                ->selectRaw('MAX(CAST(SUBSTR(numero_table, 3) AS INTEGER)) AS maximum')->value('maximum');
            $tableNumber = sprintf('%s-%04d', $centre->code, ((int) $maximum) + 1);
            $createdAt = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
            if ($candidate === null) {
                DB::table('candidats')->insert([
                    'npi' => $npi, 'nom' => $identity['nom'] ?? '', 'prenoms' => $identity['prenoms'] ?? '',
                    'date_naissance' => $birthDate, 'cree_le' => $createdAt,
                ]);
            } elseif ($identity !== null) {
                DB::table('candidats')->where('id', $candidate->id)->update($identity);
            }
            $status = $identity === null ? 'en_attente_verification' : 'validee';
            $id = DB::table('inscriptions')->insertGetId([
                'concours_id' => $contest->id, 'centre_id' => $centre->id, 'npi' => $npi,
                'numero_table' => $tableNumber, 'statut' => $status, 'cree_le' => $createdAt,
            ]);

            return ['id' => $id, 'numero_table' => $tableNumber, 'statut' => $status];
        }, attempts: 5);
    }

    private function checkEligibility(stdClass $contest, string $birthDate, CarbonImmutable $receivedAt): void
    {
        abort_if($receivedAt->greaterThan(CarbonImmutable::parse($contest->cloture, 'UTC')),
            422, 'Inscriptions closes.');
        $year = CarbonImmutable::parse($contest->date_epreuve)->year;
        $yearEnd = CarbonImmutable::create($year, 12, 31, 0, 0, 0, 'Africa/Porto-Novo');
        $birth = CarbonImmutable::parse($birthDate, 'Africa/Porto-Novo');
        abort_if($birth->year > $year || $birth->diff($yearEnd)->y > 35,
            422, 'Le candidat dépasse 35 ans au 31 décembre de l’année du concours.');
    }
}
