<?php

/**
 * Standalone worker process for StockBlockingConcurrencyTest: a real OS process with its own PostgreSQL connection, released at a file
 * barrier together with its competitors, that rings one CASH sale through the real CheckoutService.
 *
 * argv: [1]=jobFile (JSON) [2]=readyFile [3]=goFile [4]=outputFile [5]=databaseName
 *
 * `pause_ms` (optional) sleeps after the stock check has been passed, as each SALE movement is written, inside the checkout transaction
 * and while the stock row locks are held: without those locks a second till would read the same balance in the meantime and both would pass.
 *
 * job: {"terminal_id", "cashier_id", "key", "lines": [{"product_id", "quantity"}] (or "items", any sale items such as a meal selection), "total", "stock_override_token" (optional), "pause_ms" (optional)}
 */

require __DIR__.'/../../../vendor/autoload.php';

use App\Domain\Exceptions\DomainException;
use App\Domain\Financial\FinancialCalculator;
use App\Models\StockMovement;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\FiscalInstallationResolver;
use App\Services\Checkout\InventoryLocationResolver;
use App\Services\Checkout\TaxRegistrationResolver;
use App\Services\Idempotency\CanonicalRequestHasher;
use App\Services\Idempotency\IdempotencyService;
use App\Services\Inventory\StockLedger;
use App\Services\InvoiceNumbering\InvoiceSeriesAllocator;
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
    $sale = (new CheckoutService(
        new IdempotencyService, new CanonicalRequestHasher, new FinancialCalculator, new FiscalInstallationResolver,
        new InventoryLocationResolver, new TaxRegistrationResolver, new InvoiceSeriesAllocator, new StockLedger,
    ))->finalize($job['terminal_id'], $job['cashier_id'], $job['key'], [
        'items' => $job['items'] ?? $job['lines'],
        'payments' => [['method' => 'CASH', 'amount' => $job['total']]],
    ] + (isset($job['stock_override_token']) ? ['stock_override_token' => $job['stock_override_token']] : []));

    file_put_contents($outputFile, json_encode(['outcome' => 'success', 'sale_id' => $sale->id]));
} catch (Throwable $e) {
    file_put_contents($outputFile, json_encode([
        'outcome' => 'exception',
        'class' => (new ReflectionClass($e))->getShortName(),
        'code' => $e instanceof DomainException ? $e->errorCode() : null,
        'details' => $e instanceof DomainException ? $e->details() : null,
        'message' => $e->getMessage(),
    ]));
}
