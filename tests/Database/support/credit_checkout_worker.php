<?php

/**
 * Standalone worker process for CreditConcurrencyTest: a real OS process with its own PostgreSQL connection, released at a file
 * barrier together with its competitors, that rings one CREDIT sale through the real CheckoutService (or voids / refunds one through
 * the real services).
 *
 * argv: [1]=jobFile (JSON) [2]=readyFile [3]=goFile [4]=outputFile [5]=databaseName
 *
 * `pause_ms` (optional) sleeps between the limit check and the ledger insert, inside the checkout transaction, so the two-till race
 * window is wide enough to be hit every time rather than occasionally: without the employee lock both workers would pass the check.
 *
 * job: {"action": "charge", "terminal_id", "cashier_id", "key", "product_id", "employee_id", "amount"}
 *      {"action": "void", "terminal_id", "user_id", "sale_id", "key"}
 */

require __DIR__.'/../../../vendor/autoload.php';

use App\Domain\Exceptions\DomainException;
use App\Domain\Financial\FinancialCalculator;
use App\Models\EmployeeCreditEntry;
use App\Models\Terminal;
use App\Models\User;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\FiscalInstallationResolver;
use App\Services\Checkout\InventoryLocationResolver;
use App\Services\Checkout\TaxRegistrationResolver;
use App\Services\Idempotency\CanonicalRequestHasher;
use App\Services\Idempotency\IdempotencyService;
use App\Services\Inventory\StockLedger;
use App\Services\InvoiceNumbering\InvoiceSeriesAllocator;
use App\Services\Sales\ExecutionContextResolver;
use App\Services\Sales\SaleReturnLocator;
use App\Services\Sales\VoidService;
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
    EmployeeCreditEntry::creating(fn () => usleep($job['pause_ms'] * 1000));
}

try {
    if ($job['action'] === 'void') {
        $void = (new VoidService(new IdempotencyService, new CanonicalRequestHasher, new ExecutionContextResolver, new StockLedger, new SaleReturnLocator(new InventoryLocationResolver)))
            ->request(Terminal::findOrFail($job['terminal_id']), User::findOrFail($job['user_id']), $job['sale_id'], $job['key'], 'race');
        file_put_contents($outputFile, json_encode(['outcome' => 'success', 'void_id' => $void->id, 'status' => $void->status]));
        exit(0);
    }

    $sale = (new CheckoutService(
        new IdempotencyService, new CanonicalRequestHasher, new FinancialCalculator, new FiscalInstallationResolver,
        new InventoryLocationResolver, new TaxRegistrationResolver, new InvoiceSeriesAllocator, new StockLedger,
    ))->finalize($job['terminal_id'], $job['cashier_id'], $job['key'], [
        'items' => [['product_id' => $job['product_id'], 'quantity' => '1']],
        'payments' => [['method' => 'CREDIT', 'amount' => $job['amount']]],
        'employee_id' => $job['employee_id'],
    ]);

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
