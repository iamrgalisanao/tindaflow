<?php

/**
 * Standalone worker process for DailyStockConcurrencyTest: a real OS process with its own PostgreSQL connection, released at a file barrier together with
 * its competitors, that saves the daily stock grid through the real DailyStockService.
 *
 * argv: [1]=jobFile (JSON) [2]=readyFile [3]=goFile [4]=outputFile [5]=databaseName
 *
 * job: {"terminal_id", "user_id", "key", "lines": [{"dish_id"|"product_id", "cooked"|"add"}], "reason" (optional), "pause_ms" (optional: sleeps as each movement is
 * written, inside the entry's transaction and while its stock row locks are held)}
 */

require __DIR__.'/../../../vendor/autoload.php';

use App\Domain\Exceptions\DomainException;
use App\Models\StockMovement;
use App\Models\Terminal;
use App\Models\User;
use App\Services\Inventory\DailyStockService;
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

if (($job['pause_ms'] ?? 0) > 0) {
    StockMovement::creating(fn () => usleep($job['pause_ms'] * 1000));
}

try {
    $entry = app(DailyStockService::class)->enter(
        Terminal::findOrFail($job['terminal_id']),
        User::findOrFail($job['user_id']),
        $job['key'],
        ['lines' => $job['lines']] + (isset($job['reason']) ? ['reason' => $job['reason']] : []),
    );

    file_put_contents($outputFile, json_encode(['outcome' => 'success', 'lines' => $entry['lines']]));
} catch (Throwable $e) {
    file_put_contents($outputFile, json_encode([
        'outcome' => 'exception',
        'class' => (new ReflectionClass($e))->getShortName(),
        'code' => $e instanceof DomainException ? $e->errorCode() : null,
        'message' => $e->getMessage(),
    ]));
}
