<?php

namespace App\Services;

use App\Data\LogAnalysisReport;
use App\Data\LogEntry;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SplFileObject;

final class ProductionLogAnalyzer
{
    public function analyze(string $path, int $competitionYear = 2026, string $treasuryClosing = '2026-11-20 23:59:00'): LogAnalysisReport
    {
        if ($competitionYear < 1900 || $competitionYear > 9999) {
            throw new InvalidArgumentException('L’année du concours doit être comprise entre 1900 et 9999.');
        }
        $closing = $this->date($treasuryClosing, 'Y-m-d H:i:s');
        if ($closing->format('Y') !== (string) $competitionYear) {
            throw new InvalidArgumentException('La clôture doit appartenir à l’année du concours.');
        }
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('Le journal doit être un fichier local lisible.');
        }

        $numbers = $registrations = $slowCalls = $requests = $closures = $ageRefusals = $durations = [];
        $seen = [];
        $events = $lines = $repeatedEvents = 0;
        $first = $last = null;
        $yearEnd = $this->date("{$competitionYear}-12-31", 'Y-m-d');
        $file = new SplFileObject($path, 'r');

        while (! $file->eof()) {
            $text = $file->fgets();
            if ($text === '' && $file->eof()) {
                break;
            }
            $lines++;
            if (trim($text) === '') {
                continue;
            }
            $entry = LogEntry::parse($text, $lines);
            $key = ($entry->identityCall ? 'identite:' : 'http:').$entry->requestId;
            $fingerprint = hash('sha256', rtrim($text));
            if (isset($seen[$key])) {
                if ($seen[$key] !== $fingerprint) {
                    throw new InvalidArgumentException("Ligne {$lines} : événements contradictoires pour la requête {$entry->requestId}.");
                }
                $repeatedEvents++;

                continue;
            }
            $seen[$key] = $fingerprint;
            $events++;
            $first = $first === null || $entry->endedAt < $first ? $entry->endedAt : $first;
            $last = $last === null || $entry->endedAt > $last ? $entry->endedAt : $last;

            if ($entry->identityCall) {
                if ($entry->durationMs >= 5000) {
                    $slowCalls[] = $entry;
                }

                continue;
            }

            $requests[$entry->requestId] = $entry;
            if ($entry->method === 'GET' && preg_match('~^/api/centres/([^/?]+)/inscrits(?:\?|$)~', $entry->uri, $match) && $entry->status === 200) {
                $centre = $match[1];
                $durations[$centre] ??= ['centre' => $centre, 'requetes' => 0, 'total_ms' => 0, 'max_ms' => 0, 'hors_objectif' => 0];
                $durations[$centre]['requetes']++;
                $durations[$centre]['total_ms'] += $entry->durationMs;
                $durations[$centre]['max_ms'] = max($durations[$centre]['max_ms'], $entry->durationMs);
                $durations[$centre]['hors_objectif'] += (int) ($entry->durationMs >= 2000);
            }
            if ($entry->method !== 'POST' || $entry->uri !== '/api/inscriptions') {
                continue;
            }

            $this->requireFields($entry, ['concours', 'centre', 'npi']);
            $context = $entry->context;
            if ($entry->status === 201) {
                $this->requireFields($entry, ['numero']);
                $evidence = $entry->evidence() + array_intersect_key($context, array_flip(['concours', 'centre', 'npi', 'numero']));
                $numbers[$context['centre'].'|'.$context['numero']][] = $evidence;
                $registrations[$context['concours'].'|'.$context['npi']][] = $evidence;
            }
            if ($entry->status < 400 || $entry->status >= 500) {
                continue;
            }
            $error = $context['erreur'] ?? '';
            $started = $entry->startedAt();
            if ($context['concours'] === 'TRESOR' && str_contains($error, 'Inscriptions closes') && $started >= $closing->setTime(0, 0) && $started <= $closing) {
                $closures[] = $entry->evidence() + ['npi' => $context['npi'], 'centre' => $context['centre']];
            }
            if (str_contains($error, 'Âge limite dépassé')) {
                $this->requireFields($entry, ['naissance']);
                $birth = $this->date($context['naissance'], 'Y-m-d', $entry->line);
                $age = $birth->diff($yearEnd)->y;
                if ($birth <= $yearEnd && $age <= 35) {
                    $ageRefusals[] = $entry->evidence() + ['npi' => $context['npi'], 'concours' => $context['concours'], 'naissance' => $context['naissance'], 'age_au_31_decembre' => $age];
                }
            }
        }

        if ($events === 0) {
            throw new InvalidArgumentException('Le journal ne contient aucun événement exploitable.');
        }
        ksort($durations);
        foreach ($durations as &$duration) {
            $duration['moyenne_ms'] = round($duration['total_ms'] / $duration['requetes'], 2);
            unset($duration['total_ms']);
        }
        unset($duration);
        $sortEvidence = fn (array $a, array $b): int => [$a['debut'], $a['requete']] <=> [$b['debut'], $b['requete']];
        usort($closures, $sortEvidence);
        usort($ageRefusals, $sortEvidence);

        return new LogAnalysisReport(
            ['fuseau' => 'Africa/Porto-Novo', 'annee_concours' => $competitionYear, 'cloture_tresor' => LogEntry::localTime($closing), 'lignes_lues' => $lines, 'evenements_uniques' => $events, 'evenements_repetes' => $repeatedEvents, 'requetes_http' => count($requests), 'premiere_fin' => LogEntry::localTime($first), 'derniere_fin' => LogEntry::localTime($last)],
            $this->duplicates($numbers, ['centre', 'numero']),
            $this->duplicates($registrations, ['concours', 'npi']),
            $this->slowIdentity($slowCalls, $requests),
            ['nombre_refus' => count($closures), 'candidats_distincts' => count(array_unique(array_column($closures, 'npi'))), 'details' => $closures],
            ['nombre_refus' => count($ageRefusals), 'candidats_distincts' => count(array_unique(array_column($ageRefusals, 'npi'))), 'details' => $ageRefusals],
            array_values($durations),
        );
    }

    private function date(string $value, string $format, ?int $line = null): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!'.$format, $value, new DateTimeZone('Africa/Porto-Novo'));
        if ($date === false || $date->format($format) !== $value) {
            $prefix = $line === null ? '' : "Ligne {$line} : ";
            throw new InvalidArgumentException($prefix."date invalide ; format attendu : {$format}.");
        }

        return $date;
    }

    /** @param list<string> $fields */
    private function requireFields(LogEntry $entry, array $fields): void
    {
        foreach ($fields as $field) {
            if (! isset($entry->context[$field]) || $entry->context[$field] === '') {
                throw new InvalidArgumentException("Ligne {$entry->line} : champ {$field} manquant.");
            }
        }
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $groups
     * @param  list<string>  $fields
     * @return list<array<string, mixed>>
     */
    private function duplicates(array $groups, array $fields): array
    {
        ksort($groups);
        $duplicates = [];
        foreach ($groups as $entries) {
            if (count($entries) < 2) {
                continue;
            }
            usort($entries, fn (array $a, array $b): int => [$a['debut'], $a['requete']] <=> [$b['debut'], $b['requete']]);
            $duplicates[] = array_intersect_key($entries[0], array_flip($fields)) + ['attributions' => count($entries), 'excedent' => count($entries) - 1, 'details' => $entries];
        }

        return $duplicates;
    }

    /**
     * @param  list<LogEntry>  $calls
     * @param  array<string, LogEntry>  $requests
     * @return array<string, mixed>
     */
    private function slowIdentity(array $calls, array $requests): array
    {
        usort($calls, fn (LogEntry $a, LogEntry $b): int => [$a->startedAt(), $a->requestId] <=> [$b->startedAt(), $b->requestId]);
        $windows = $details = [];
        $byEndpoint = [];
        $first = $last = null;
        foreach ($calls as $call) {
            $start = $call->startedAt();
            $first ??= $start;
            $last = $last === null || $call->endedAt > $last ? $call->endedAt : $last;
            $index = count($windows) - 1;
            if ($index >= 0 && $start <= $windows[$index]['fin']) {
                $windows[$index]['fin'] = max($windows[$index]['fin'], $call->endedAt);
                $windows[$index]['appels']++;
            } else {
                $windows[] = ['debut' => $start, 'fin' => $call->endedAt, 'appels' => 1];
            }
            $request = $requests[$call->requestId] ?? null;
            $endpoint = $request === null ? 'requete_absente_du_journal' : $request->method.' '.(preg_match('~^/api/inscriptions/[^/]+/convocation$~', $request->uri) ? '/api/inscriptions/{id}/convocation' : $request->uri);
            $byEndpoint[$endpoint] = ($byEndpoint[$endpoint] ?? 0) + 1;
            $details[] = $call->evidence() + ['requete_http' => $request?->evidence(), 'endpoint' => $endpoint];
        }
        ksort($byEndpoint);

        return [
            'seuil_ms' => 5000,
            'requetes_touchees' => count($calls),
            'debut_observe' => $first === null ? null : LogEntry::localTime($first),
            'fin_observee' => $last === null ? null : LogEntry::localTime($last),
            'par_endpoint' => $byEndpoint,
            'intervalles_appels_lents' => array_map(fn (array $window): array => ['debut' => LogEntry::localTime($window['debut']), 'fin' => LogEntry::localTime($window['fin']), 'appels' => $window['appels']], $windows),
            'details' => $details,
        ];
    }
}
