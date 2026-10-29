<?php

namespace App\Data;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class LogEntry
{
    /** @param array<string, string> $context */
    public function __construct(
        public int $line,
        public string $requestId,
        public DateTimeImmutable $endedAt,
        public int $durationMs,
        public bool $identityCall,
        public string $method,
        public string $uri,
        public int $status,
        public array $context,
    ) {}

    public static function parse(string $line, int $number): self
    {
        $pattern = '~^(?<time>\S+)\s+(?:INFO|WARN|ERROR|DEBUG)\s+\[req:(?<id>[^\]]+)\]\s+(?<source>appel identite|\S+)\s+(?<method>[A-Z]+)\s+(?<uri>\S+)\s+->\s+(?<status>\d{3})\s+\((?<ms>\d+) ms\)(?<context>.*)$~u';

        if (! preg_match($pattern, rtrim($line), $matches)) {
            throw new InvalidArgumentException("Ligne {$number} : format de journal non reconnu.");
        }

        $format = str_contains($matches['time'], '.') ? '!Y-m-d\TH:i:s.v\Z' : '!Y-m-d\TH:i:s\Z';
        $endedAt = DateTimeImmutable::createFromFormat($format, $matches['time'], new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if ($endedAt === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidArgumentException("Ligne {$number} : horodatage UTC invalide.");
        }

        preg_match_all('/(?:^|\s)([a-z_]+)=("[^"]*"|\S+)/u', $matches['context'], $fields, PREG_SET_ORDER);
        $context = [];
        foreach ($fields as $field) {
            $context[$field[1]] = trim($field[2], '"');
        }
        if (preg_match('/\berreur="(.*)"$/u', $matches['context'], $error)) {
            $context['erreur'] = $error[1];
        }

        return new self($number, $matches['id'], $endedAt, (int) $matches['ms'], $matches['source'] === 'appel identite', $matches['method'], $matches['uri'], (int) $matches['status'], $context);
    }

    public function startedAt(): DateTimeImmutable
    {
        return $this->endedAt->modify("-{$this->durationMs} milliseconds");
    }

    /** @return array{ligne: int, requete: string, debut: string, fin: string, duree_ms: int} */
    public function evidence(): array
    {
        return [
            'ligne' => $this->line,
            'requete' => $this->requestId,
            'debut' => self::localTime($this->startedAt()),
            'fin' => self::localTime($this->endedAt),
            'duree_ms' => $this->durationMs,
        ];
    }

    public static function localTime(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('Africa/Porto-Novo'))->format('Y-m-d\TH:i:s.vP');
    }
}
