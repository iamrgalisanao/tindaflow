<?php

/**
 * Standalone worker process for CheckoutServiceConcurrencyTest. Run as a
 * genuinely separate OS process (via proc_open), with its own PDO
 * connection, so the race it participates in is a real PostgreSQL race --
 * not a sequential call simulated inside one connection/transaction. Same
 * technique as idempotency_race_worker.php and
 * invoice_series_allocation_worker.php, driven through the real
 * CheckoutService this time, not a lower-layer component directly.
 *
 * argv: [1]=terminalId [2]=cashierId [3]=idempotencyKey [4]=productId
 *       [5]=readyFile [6]=goFile [7]=outputFile [8]=databaseName
 */

require __DIR__.'/../../../vendor/autoload.php';

use App\Domain\Financial\FinancialCalculator;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\FiscalInstallationResolver;
use App\Services\Checkout\InventoryLocationResolver;
use App\Services\Checkout\TaxRegistrationResolver;
use App\Services\Idempotency\CanonicalRequestHasher;
use App\Services\Idempotency\IdempotencyService;
use App\Services\InvoiceNumbering\InvoiceSeriesAllocator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

[, $terminalId, $cashierId, $idempotencyKey, $productId, $readyFile, $goFile, $outputFile, $databaseName] = $argv;

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

$checkoutService = new CheckoutService(
    new IdempotencyService,
    new CanonicalRequestHasher,
    new FinancialCalculator,
    new FiscalInstallationResolver,
    new InventoryLocationResolver,
    new TaxRegistrationResolver,
    new InvoiceSeriesAllocator,
);

try {
    $sale = $checkoutService->finalize(
        $terminalId,
        $cashierId,
        $idempotencyKey,
        [
            'items' => [['product_id' => $productId, 'quantity' => '1']],
            'payments' => [['method' => 'CASH', 'amount' => '100.00']],
        ]
    );

    file_put_contents($outputFile, json_encode([
        'outcome' => 'success',
        'sale_id' => $sale->id,
        'transaction_number' => $sale->transaction_number,
        'invoice_number' => $sale->invoice->invoice_number,
    ]));
} catch (Throwable $e) {
    file_put_contents($outputFile, json_encode([
        'outcome' => 'exception',
        'class' => get_class($e),
        'message' => $e->getMessage(),
    ]));
}
