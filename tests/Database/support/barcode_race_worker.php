<?php

/**
 * Standalone worker process for ProductBarcodeConcurrencyTest: a real OS process with its own PostgreSQL
 * connection, released at a file barrier together with its competitor.
 *
 * argv: [1]=jobFile (JSON) [2]=readyFile [3]=goFile [4]=outputFile [5]=databaseName
 *
 * job: {"type": "add_alternate"|"set_main", "user_id", "product_id", "barcode"}
 */

require __DIR__.'/../../../vendor/autoload.php';

use App\Models\User;
use App\Services\Catalog\ProductBarcodeService;
use App\Services\Catalog\ProductService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Database\PostgresTestConnection;

[, $jobFile, $readyFile, $goFile, $outputFile, $databaseName] = $argv;

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(PostgresTestConnection::settings($databaseName));
DB::purge('pgsql');
DB::setDefaultConnection('pgsql');

$job = json_decode(file_get_contents($jobFile), true);

file_put_contents($readyFile, '1');

$deadline = microtime(true) + 15;
while (! file_exists($goFile)) {
    if (microtime(true) > $deadline) {
        file_put_contents($outputFile, json_encode(['outcome' => 'exception', 'class' => 'BarrierTimeout', 'message' => 'timed out waiting for barrier']));
        exit(1);
    }
    usleep(500);
}

try {
    $user = User::findOrFail($job['user_id']);

    match ($job['type']) {
        'add_alternate' => $app->make(ProductBarcodeService::class)->add($user, $job['product_id'], ['barcode' => $job['barcode']]),
        'set_main' => $app->make(ProductService::class)->update($user, $job['product_id'], ['barcode' => $job['barcode']]),
    };

    file_put_contents($outputFile, json_encode(['outcome' => 'success']));
} catch (Throwable $e) {
    file_put_contents($outputFile, json_encode([
        'outcome' => 'exception',
        'class' => (new ReflectionClass($e))->getShortName(),
        'message' => $e->getMessage(),
    ]));
}
