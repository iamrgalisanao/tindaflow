<?php

/**
 * Standalone worker for RealDeadlockClassificationTest: takes the lock on probe row 1, then (on the go signal) reaches
 * for row 2, which the test process holds while it reaches for row 1. PostgreSQL detects the cycle and aborts one of the
 * two; whichever this process is told about, it reports the SQLSTATE and whether the application recognises it.
 *
 * argv: [1]=readyFile [2]=goFile [3]=outputFile [4]=databaseName
 */

require __DIR__.'/../../../vendor/autoload.php';

use App\Services\Database\ConcurrencyFailure;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Database\PostgresTestConnection;

[, $readyFile, $goFile, $outputFile, $databaseName] = $argv;

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(PostgresTestConnection::settings($databaseName));
DB::purge('pgsql');
DB::setDefaultConnection('pgsql');

DB::beginTransaction();
DB::table('deadlock_probe')->where('id', 1)->update(['n' => DB::raw('n + 1')]);
file_put_contents($readyFile, '1');

$deadline = microtime(true) + 15;
while (! file_exists($goFile)) {
    if (microtime(true) > $deadline) {
        DB::rollBack();
        file_put_contents($outputFile, json_encode(['outcome' => 'timeout']));
        exit(1);
    }
    usleep(500);
}

try {
    DB::table('deadlock_probe')->where('id', 2)->update(['n' => DB::raw('n + 1')]);
    DB::commit();
    file_put_contents($outputFile, json_encode(['outcome' => 'success']));
} catch (Throwable $e) {
    DB::rollBack();
    file_put_contents($outputFile, json_encode([
        'outcome' => 'aborted',
        'class' => (new ReflectionClass($e))->getShortName(),
        'sql_state' => ConcurrencyFailure::sqlState($e),
        'recognised' => ConcurrencyFailure::caused($e),
    ]));
}
