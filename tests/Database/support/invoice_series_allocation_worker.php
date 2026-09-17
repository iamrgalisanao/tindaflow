<?php

/**
 * Standalone worker process for InvoiceSeriesAllocatorConcurrencyTest.
 * Run as a genuinely separate OS process (via Process::start()), with
 * its own PDO connection, so the race it participates in is a real
 * PostgreSQL row-lock race on `invoice_series` -- not a sequential call
 * simulated inside one connection/transaction. Mirrors the pattern
 * established by tests/Database/support/idempotency_race_worker.php.
 *
 * argv: [1]=storeId [2]=fiscalInstallationId [3]=readyFile [4]=goFile
 *       [5]=outputFile [6]=databaseName [7]=behavior ('commit'|'rollback')
 *       [8]=sleepMsAfterAllocate (optional, default 0 -- used to force
 *           a genuine lock-wait window for the rollback-under-contention
 *           scenario)
 */

require __DIR__.'/../../../vendor/autoload.php';

use App\Domain\Exceptions\InvoiceSeriesExhaustedException;
use App\Domain\Exceptions\InvoiceSeriesResolutionException;
use App\Services\InvoiceNumbering\InvoiceSeriesAllocator;
use App\Support\GlobalLockOrder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

[, $storeId, $fiscalInstallationId, $readyFile, $goFile, $outputFile, $databaseName, $behavior, $sleepMsAfterAllocate] = array_pad($argv, 9, null);
$sleepMsAfterAllocate = (int) ($sleepMsAfterAllocate ?? 0);

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
        file_put_contents($outputFile, json_encode(['outcome' => 'timeout']));
        exit(1);
    }
    usleep(500);
}

$allocator = new InvoiceSeriesAllocator;

try {
    $allocated = DB::transaction(function () use ($storeId, $fiscalInstallationId, $allocator, $behavior, $sleepMsAfterAllocate) {
        $result = $allocator->allocateForFiscalInstallation($storeId, $fiscalInstallationId, new GlobalLockOrder);

        if ($sleepMsAfterAllocate > 0) {
            usleep($sleepMsAfterAllocate * 1000);
        }

        if ($behavior === 'rollback') {
            throw new RuntimeException('Intentional rollback for InvoiceSeriesAllocatorConcurrencyTest.');
        }

        return $result;
    });

    file_put_contents($outputFile, json_encode([
        'outcome' => 'committed',
        'invoice_series_id' => $allocated->invoiceSeriesId,
        'serial' => $allocated->serial,
        'formatted_number' => $allocated->formattedNumber,
    ]));
} catch (Throwable $e) {
    if ($behavior === 'rollback' && str_contains($e->getMessage(), 'Intentional rollback')) {
        file_put_contents($outputFile, json_encode(['outcome' => 'rolled_back']));

        exit(0);
    }

    file_put_contents($outputFile, json_encode([
        'outcome' => 'exception',
        'class' => match (true) {
            $e instanceof InvoiceSeriesExhaustedException => InvoiceSeriesExhaustedException::class,
            $e instanceof InvoiceSeriesResolutionException => InvoiceSeriesResolutionException::class,
            default => get_class($e),
        },
        'message' => $e->getMessage(),
    ]));
}
