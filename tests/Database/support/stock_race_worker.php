<?php

/**
 * Standalone worker process for StockCountTransferConcurrencyTest. Run as a genuinely separate OS process
 * (via proc_open) with its own PostgreSQL connection, so the race it takes part in is a real database race.
 *
 * argv: [1]=jobFile (JSON) [2]=readyFile [3]=goFile [4]=outputFile [5]=databaseName
 *
 * job: {"type": "post_count"|"cancel_count"|"transfer", "terminal_id", "user_id", "key", ...}
 *   post_count   + stock_count_id
 *   cancel_count + stock_count_id
 *   transfer     + payload {from_location_id, to_location_id, items: [{product_id, quantity}]}
 */

require __DIR__.'/../../../vendor/autoload.php';

use App\Models\Terminal;
use App\Models\User;
use App\Services\Inventory\StockCountService;
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
    $terminal = Terminal::findOrFail($job['terminal_id']);

    match ($job['type']) {
        'post_count' => $app->make(StockCountService::class)->post($terminal, $user, $job['key'], $job['stock_count_id']),
        'cancel_count' => $app->make(StockCountService::class)->cancel($user, $job['stock_count_id']),
        'transfer' => $app->make(StockTransferService::class)->create($terminal, $user, $job['key'], $job['payload']),
    };

    file_put_contents($outputFile, json_encode(['outcome' => 'success']));
} catch (Throwable $e) {
    file_put_contents($outputFile, json_encode([
        'outcome' => 'exception',
        'class' => (new ReflectionClass($e))->getShortName(),
        'message' => $e->getMessage(),
    ]));
}
