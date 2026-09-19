<?php

/**
 * Standalone worker process for SalesReversalConcurrencyTest: a genuinely separate OS process with its
 * own PDO connection, so the race is a real PostgreSQL race through the real VoidService/RefundService.
 * Same technique as checkout_worker.php.
 *
 * argv: [1]=operation (refund-approve|void-approve) [2]=terminalId [3]=userId [4]=targetId (refund or
 *       void id) [5]=idempotencyKey [6]=readyFile [7]=goFile [8]=outputFile [9]=databaseName
 */

require __DIR__.'/../../../vendor/autoload.php';

use App\Models\Terminal;
use App\Models\User;
use App\Services\Checkout\InventoryLocationResolver;
use App\Services\Idempotency\CanonicalRequestHasher;
use App\Services\Idempotency\IdempotencyService;
use App\Services\Inventory\StockLedger;
use App\Services\Sales\ExecutionContextResolver;
use App\Services\Sales\RefundCalculator;
use App\Services\Sales\RefundService;
use App\Services\Sales\SaleReturnLocator;
use App\Services\Sales\VoidService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

[, $operation, $terminalId, $userId, $targetId, $idempotencyKey, $readyFile, $goFile, $outputFile, $databaseName] = $argv;

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

$terminal = Terminal::findOrFail($terminalId);
$user = User::findOrFail($userId);
$locator = new SaleReturnLocator(new InventoryLocationResolver);

try {
    $result = match ($operation) {
        'refund-approve' => (new RefundService(new IdempotencyService, new CanonicalRequestHasher, new ExecutionContextResolver, new StockLedger, $locator, new RefundCalculator))
            ->approve($terminal, $user, $targetId, $idempotencyKey),
        'void-approve' => (new VoidService(new IdempotencyService, new CanonicalRequestHasher, new ExecutionContextResolver, new StockLedger, $locator))
            ->approve($terminal, $user, $targetId, $idempotencyKey),
    };

    file_put_contents($outputFile, json_encode(['outcome' => 'success', 'id' => $result->id, 'status' => $result->status]));
} catch (Throwable $e) {
    file_put_contents($outputFile, json_encode([
        'outcome' => 'exception',
        'class' => get_class($e),
        'code' => method_exists($e, 'errorCode') ? $e->errorCode() : null,
        'message' => $e->getMessage(),
    ]));
}
