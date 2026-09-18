<?php

/**
 * Standalone worker process for TerminalEnrollmentConcurrencyTest. Run as
 * a genuinely separate OS process (via proc_open/Process::start), with
 * its own PDO connection, so the race it participates in is a real
 * PostgreSQL race -- not a sequential call simulated inside one
 * connection/transaction. Mirrors idempotency_race_worker.php's pattern.
 *
 * argv: [1]=plaintextToken [2]=actorStoreId [3]=readyFile [4]=goFile [5]=outputFile [6]=databaseName
 */

require __DIR__.'/../../../vendor/autoload.php';

use App\Services\Terminal\TerminalEnrollmentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

[, $plaintextToken, $actorStoreId, $readyFile, $goFile, $outputFile, $databaseName] = $argv;

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'database.default' => 'pgsql',
    'database.connections.pgsql.host' => '127.0.0.1',
    'database.connections.pgsql.port' => '5432',
    'database.connections.pgsql.database' => $databaseName,
    'database.connections.pgsql.username' => 'postgres',
    'database.connections.pgsql.password' => '',
]);
DB::purge('pgsql');
DB::setDefaultConnection('pgsql');

file_put_contents($readyFile, '1');

$deadline = microtime(true) + 10;
while (! file_exists($goFile)) {
    if (microtime(true) > $deadline) {
        file_put_contents($outputFile, json_encode(['error' => 'timed out waiting for barrier']));
        exit(1);
    }
    usleep(500);
}

try {
    $result = (new TerminalEnrollmentService)->enroll($plaintextToken, $actorStoreId);

    file_put_contents($outputFile, json_encode([
        'outcome' => 'success',
        'terminal_id' => $result['terminal']->id,
        'credential' => $result['credential'],
    ]));
} catch (Throwable $e) {
    file_put_contents($outputFile, json_encode([
        'outcome' => 'exception',
        'class' => get_class($e),
        'message' => $e->getMessage(),
    ]));
}
