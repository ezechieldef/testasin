<?php

namespace App\Services;

use App\Data\LogEntry;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SplFileObject;

final class JournalInvestigator
{
    /** @return list<LogEntry> */
    public function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('Le journal doit être un fichier local lisible.');
        }
        $file = new SplFileObject($path);
        $entries = $seen = [];
        $line = 0;
        while (! $file->eof()) {
            $text = $file->fgets();
            $line++;
            if (trim($text) === '') {
                continue;
            }
            $entry = LogEntry::parse($text, $line);
            $key = ($entry->identityCall ? 'identite:' : 'http:').$entry->requestId;
            $hash = hash('sha256', rtrim($text));
            if (isset($seen[$key])) {
                if ($seen[$key] !== $hash) {
                    throw new InvalidArgumentException("Ligne {$line} : événements contradictoires pour la requête {$entry->requestId}.");
                }

                continue;
            }
            $seen[$key] = $hash;
            $entries[] = $entry;
        }
        if ($entries === []) {
            throw new InvalidArgumentException('Le journal ne contient aucun événement exploitable.');
        }
        usort($entries, fn (LogEntry $a, LogEntry $b): int => [$a->startedAt(), $a->requestId, $a->line] <=> [$b->startedAt(), $b->requestId, $b->line]);

        return $entries;
    }

    /**
     * @param  array<string, string>  $filters
     * @return array<string, mixed>
     */
    public function trace(string $path, array $filters): array
    {
        $filters = array_filter($filters, fn (string $value): bool => $value !== '');
        $allowed = ['requete', 'npi', 'numero', 'centre', 'concours', 'debut', 'fin'];
        if ($filters === [] || array_diff(array_keys($filters), $allowed) !== []) {
            throw new InvalidArgumentException('Fournir au moins un filtre reconnu : requete, npi, numero, centre, concours, debut ou fin.');
        }
        $from = isset($filters['debut']) ? $this->localDate($filters['debut']) : null;
        $to = isset($filters['fin']) ? $this->localDate($filters['fin']) : null;
        if ($from !== null && $to !== null && $from > $to) {
            throw new InvalidArgumentException('Le début doit précéder la fin.');
        }
        $requests = [];
        foreach ($this->read($path) as $entry) {
            $requests[$entry->requestId][] = $entry;
        }
        $evidence = [];
        $matched = 0;
        foreach ($requests as $requestId => $entries) {
            $context = ['requete' => (string) $requestId];
            foreach ($entries as $entry) {
                if ($entry->identityCall && preg_match('~^/identite/([^/?]+)$~', $entry->uri, $match)) {
                    $context['npi'] = $match[1];
                }
                if (preg_match('~^/api/centres/([^/?]+)/inscrits(?:\?|$)~', $entry->uri, $match)) {
                    $context['centre'] = $match[1];
                    parse_str(parse_url($entry->uri, PHP_URL_QUERY) ?? '', $query);
                    if (isset($query['concours']) && is_string($query['concours'])) {
                        $context['concours'] = $query['concours'];
                    }
                }
                $context = array_replace($context, array_intersect_key($entry->context, array_flip(['npi', 'centre', 'concours', 'numero'])));
            }
            $matches = true;
            foreach (array_diff_key($filters, array_flip(['debut', 'fin'])) as $field => $value) {
                if (($context[$field] ?? null) !== $value) {
                    $matches = false;
                }
            }
            $start = min(array_map(fn (LogEntry $entry): DateTimeImmutable => $entry->startedAt(), $entries));
            $end = max(array_map(fn (LogEntry $entry): DateTimeImmutable => $entry->endedAt, $entries));
            if (! $matches || ($from !== null && $end < $from) || ($to !== null && $start > $to)) {
                continue;
            }
            $matched++;
            foreach ($entries as $entry) {
                $evidence[] = $this->evidence($entry);
            }
        }
        usort($evidence, fn (array $a, array $b): int => [$a['debut'], $a['requete'], $a['ligne']] <=> [$b['debut'], $b['requete'], $b['ligne']]);

        return ['fuseau' => 'Africa/Porto-Novo', 'sha256' => hash_file('sha256', $path), 'filtres' => $filters, 'requetes' => $matched, 'evenements' => $evidence];
    }

    /** @return array<string, mixed> */
    public function evidence(LogEntry $entry): array
    {
        return $entry->evidence() + ['type' => $entry->identityCall ? 'identite' : 'http', 'methode' => $entry->method, 'uri' => $entry->uri, 'statut' => $entry->status, 'contexte' => $entry->context];
    }

    private function localDate(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('Africa/Porto-Novo'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
            throw new InvalidArgumentException('Date invalide ; format attendu : Y-m-d H:i:s, en heure du Bénin.');
        }

        return $date;
    }
}
