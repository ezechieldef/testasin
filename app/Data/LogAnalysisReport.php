<?php

namespace App\Data;

final readonly class LogAnalysisReport
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<array<string, mixed>>  $duplicateNumbers
     * @param  list<array<string, mixed>>  $duplicateRegistrations
     * @param  array<string, mixed>  $slowIdentity
     * @param  array<string, mixed>  $wrongfulClosures
     * @param  array<string, mixed>  $wrongfulAge
     * @param  list<array<string, mixed>>  $listDurations
     */
    public function __construct(
        public array $metadata,
        public array $duplicateNumbers,
        public array $duplicateRegistrations,
        public array $slowIdentity,
        public array $wrongfulClosures,
        public array $wrongfulAge,
        public array $listDurations,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'meta' => $this->metadata,
            'numeros_multiples' => $this->duplicateNumbers,
            'inscriptions_multiples' => $this->duplicateRegistrations,
            'identite_lente' => $this->slowIdentity,
            'refus_cloture_injustifies' => $this->wrongfulClosures,
            'refus_age_injustifies' => $this->wrongfulAge,
            'durees_listes_par_centre' => $this->listDurations,
        ];
    }
}
