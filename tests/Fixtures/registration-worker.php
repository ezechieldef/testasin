<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$input = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
config([
    'database.default' => 'registration_worker',
    'database.connections.registration_worker' => $input['connection'],
    'services.identity.url' => 'https://identity.example',
]);
Http::preventStrayRequests();
Http::fake(['identity.example/identite/*' => function () {
    /** Toutes les requêtes atteignent l’appel externe avant de concourir pour les verrous. */
    fwrite(STDOUT, "READY\n");
    fflush(STDOUT);
    if (trim(fgets(STDIN)) !== 'GO') {
        throw new RuntimeException('La barrière de concurrence n’a pas été libérée.');
    }

    return Http::response(['nom' => 'DOSSOU', 'prenoms' => 'Alice']);
}]);
$request = Request::create('/api/inscriptions', 'POST', server: [
    'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
], content: json_encode($input['payload'], JSON_THROW_ON_ERROR));
$response = $kernel->handle($request);
fwrite(STDOUT, json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR)."\n");
$kernel->terminate($request, $response);
