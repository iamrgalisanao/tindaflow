<?php

/**
 * Standalone worker process for BillingChequeConcurrencyTest: a real OS process with its own PostgreSQL connection, released at a file
 * barrier together with its competitors, that records or returns a cheque through the real service.
 *
 * argv: [1]=jobFile (JSON) [2]=readyFile [3]=goFile [4]=outputFile [5]=databaseName
 *
 * job: {"action": "record"|"return"|"write_off"|"reverse_write_off" (slice 4.3: BillingWriteOffService, "write_off" = input, "write_off_id", "reason"), "user_id", "cheque": {...input of BillingChequeService::record}, "cheque_id", "returned_on", "reason"}
 */

require __DIR__.'/../../../vendor/autoload.php';

use App\Models\User;
use App\Services\Billing\BillingChequeService;
use App\Services\Billing\BillingWriteOffService;
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
    if ($job['action'] === 'write_off') {
        [$row] = $app->make(BillingWriteOffService::class)->record($user, $job['write_off']);
        file_put_contents($outputFile, json_encode(['outcome' => 'success', 'write_off_id' => $row->id]));
        exit(0);
    }
    if ($job['action'] === 'reverse_write_off') {
        $row = $app->make(BillingWriteOffService::class)->reverse($user, $job['write_off_id'], $job['reason']);
        file_put_contents($outputFile, json_encode(['outcome' => 'success', 'write_off_id' => $row->id]));
        exit(0);
    }
    $service = $app->make(BillingChequeService::class);
    $cheque = $job['action'] === 'record'
        ? $service->record($user, $job['cheque'])
        : $service->markReturned($user, $job['cheque_id'], ['returned_on' => $job['returned_on'], 'reason' => $job['reason']]);

    file_put_contents($outputFile, json_encode(['outcome' => 'success', 'cheque_id' => $cheque->id]));
} catch (Throwable $e) {
    file_put_contents($outputFile, json_encode([
        'outcome' => 'exception',
        'class' => (new ReflectionClass($e))->getShortName(),
        'message' => $e->getMessage(),
    ]));
}
