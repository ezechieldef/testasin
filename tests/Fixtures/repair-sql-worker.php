<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(fgets(STDIN), true, flags: JSON_THROW_ON_ERROR);
config(['database.default' => 'repair_worker', 'database.connections.repair_worker' => $input['connection']]);
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
if (trim(fgets(STDIN)) !== 'GO') {
    throw new RuntimeException('Barrière non libérée.');
}
try {
    DB::statement($input['sql']);
    $result = 'ok';
} catch (QueryException $exception) {
    $result = $exception->errorInfo[0];
}
fwrite(STDOUT, $result."\n");
