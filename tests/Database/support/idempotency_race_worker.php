<?php

/**
 * Standalone worker process for IdempotencyConcurrencyTest. Run as a
 * genuinely separate OS process (via proc_open), with its own PDO
 * connection, so the race it participates in is a real PostgreSQL race
 * -- not a sequential call simulated inside one connection/transaction.
 *
 * argv: [1]=terminalId [2]=idempotencyKey [3]=requestHash [4]=readyFile
 *       [5]=goFile [6]=outputFile [7]=databaseName [8]=mutationMarker
 */

require __DIR__.'/../../../vendor/autoload.php';

use App\Services\Idempotency\IdempotencyOperationType;
use App\Services\Idempotency\IdempotencyService;
use App\Services\Idempotency\OperationOutcome;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Database\PostgresTestConnection;

[, $terminalId, $idempotencyKey, $requestHash, $readyFile, $goFile, $outputFile, $databaseName, $mutationMarker] = $argv;

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(PostgresTestConnection::settings($databaseName));
DB::purge('pgsql');
DB::setDefaultConnection('pgsql');

// Signal readiness, then busy-wait for the barrier file. Both workers
// are spawned before either is released, so the actual execute() calls
// below happen as close to simultaneously as two OS processes racing
// against a shared filesystem flag can get.
file_put_contents($readyFile, '1');

$deadline = microtime(true) + 10;
while (! file_exists($goFile)) {
    if (microtime(true) > $deadline) {
        file_put_contents($outputFile, json_encode(['error' => 'timed out waiting for barrier']));
        exit(1);
    }
    usleep(500);
}

$service = new IdempotencyService;

try {
    $result = $service->execute(
        $terminalId,
        $idempotencyKey,
        IdempotencyOperationType::Checkout,
        $requestHash,
        function () use ($mutationMarker) {
            // The observable "business mutation" -- a distinct row per
            // actual execution, so the test can count executions
            // directly from the database rather than trusting in-memory
            // state that only this one process could see.
            $mutationId = (string) Str::uuid();
            DB::table('idempotency_race_mutations')->insert([
                'id' => $mutationId,
                'marker' => $mutationMarker,
                'created_at' => now(),
            ]);

            return new OperationOutcome('sale', $mutationId);
        }
    );

    file_put_contents($outputFile, json_encode([
        'outcome' => 'success',
        'replayed' => $result->replayed,
        'result_type' => $result->resultType,
        'result_resource_id' => $result->resultResourceId,
    ]));
} catch (Throwable $e) {
    file_put_contents($outputFile, json_encode([
        'outcome' => 'exception',
        'class' => get_class($e),
        'message' => $e->getMessage(),
    ]));
}
