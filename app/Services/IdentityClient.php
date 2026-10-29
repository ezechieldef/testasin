<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class IdentityClient
{
    /** @return array{nom: string, prenoms: string}|null */
    public function verify(string $npi): ?array
    {
        $url = config('services.identity.url');
        abort_unless(is_string($url) && $url !== '', 503, 'Le service d’identification doit être configuré.');
        try {
            $response = Http::baseUrl($url)->acceptJson()
                ->connectTimeout(5)->timeout(5)->withoutRedirecting()
                ->get('/identite/'.$npi);
        } catch (ConnectionException) {
            return null;
        }
        abort_if($response->notFound(), 422, 'NPI inconnu du service d’identification.');
        if (! $response->successful()) {
            return null;
        }
        $identity = $response->json();
        if (! is_array($identity)
            || ! is_string($identity['nom'] ?? null) || trim($identity['nom']) === ''
            || ! is_string($identity['prenoms'] ?? null) || trim($identity['prenoms']) === ''
            || mb_strlen($identity['nom']) > 60 || mb_strlen($identity['prenoms']) > 80) {
            return null;
        }

        return ['nom' => $identity['nom'], 'prenoms' => $identity['prenoms']];
    }
}
