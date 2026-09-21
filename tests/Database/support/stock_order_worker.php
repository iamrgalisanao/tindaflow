<?php

/**
 * Standalone worker for StockWriteOrderConcurrencyTest: a real OS process with its own PostgreSQL connection that runs
 * one stock-writing operation through the real service, released at a file barrier together with its competitors.
 *
 * argv: [1]=jobFile (JSON) [2]=readyFile [3]=goFile [4]=outputFile [5]=databaseName
 *
 * job: {"type": "checkout"|"transfer"|"refund_all"?, ...}
 *   checkout: terminal_id, cashier_id, key, items: [{product_id, quantity}], amount
 *   transfer: terminal_id, user_id, key, payload {from_location_id, to_location_id, items}
 */

require __DIR__.'/../../../vendor/autoload.php';

use App\Models\Terminal;
use App\Models\User;
use App\Services\Checkout\CheckoutService;
use App\Services\Database\ConcurrencyFailure;
use App\Services\Inventory\StockTransferService;
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

$deadline = microtime(true) + 20;
while (! file_exists($goFile)) {
    if (microtime(true) > $deadline) {
        file_put_contents($outputFile, json_encode(['outcome' => 'exception', 'class' => 'BarrierTimeout', 'message' => 'timed out waiting for barrier']));
        exit(1);
    }
    usleep(500);
}

try {
    match ($job['type']) {
        'checkout' => $app->make(CheckoutService::class)->finalize(
            $job['terminal_id'], $job['cashier_id'], $job['key'],
            ['items' => $job['items'], 'payments' => [['method' => 'CASH', 'amount' => $job['amount']]]],
        ),
        'transfer' => $app->make(StockTransferService::class)->create(
            Terminal::findOrFail($job['terminal_id']), User::findOrFail($job['user_id']), $job['key'], $job['payload'],
        ),
    };

    file_put_contents($outputFile, json_encode(['outcome' => 'success']));
} catch (Throwable $e) {
    file_put_contents($outputFile, json_encode([
        'outcome' => 'exception',
        'class' => (new ReflectionClass($e))->getShortName(),
        'message' => substr($e->getMessage(), 0, 300),
        'sql_state' => ConcurrencyFailure::sqlState($e),
    ]));
}
