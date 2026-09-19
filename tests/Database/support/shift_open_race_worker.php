<?php

/**
 * Standalone worker process for ShiftOpenConcurrencyTest. Real, separate
 * OS process (proc_open via Process::start), its own PDO connection --
 * same technique as checkout_worker.php/idempotency_race_worker.php.
 *
 * argv: [1]=terminalId [2]=cashierId [3]=idempotencyKey [4]=openingCash
 *       [5]=readyFile [6]=goFile [7]=outputFile [8]=databaseName
 */

require __DIR__.'/../../../vendor/autoload.php';

use App\Services\Idempotency\CanonicalRequestHasher;
use App\Services\Idempotency\IdempotencyService;
use App\Services\Shift\ShiftOpenService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Database\PostgresTestConnection;

[, $terminalId, $cashierId, $idempotencyKey, $openingCash, $readyFile, $goFile, $outputFile, $databaseName] = $argv;

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(PostgresTestConnection::settings($databaseName));
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

$service = new ShiftOpenService(new IdempotencyService, new CanonicalRequestHasher);

try {
    $result = $service->open($terminalId, $cashierId, $idempotencyKey, ['opening_cash' => $openingCash]);

    file_put_contents($outputFile, json_encode([
        'outcome' => 'success',
        'shift_id' => $result['shift']->id,
        'fiscal_day_id' => $result['shift']->fiscal_day_id,
        'fiscal_day_was_opened' => $result['fiscal_day_was_opened'],
    ]));
} catch (Throwable $e) {
    file_put_contents($outputFile, json_encode([
        'outcome' => 'exception',
        'class' => get_class($e),
        'error_code' => method_exists($e, 'errorCode') ? $e->errorCode() : null,
        'message' => $e->getMessage(),
    ]));
}
