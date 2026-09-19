<?php

namespace Tests\Database;

use App\Domain\Financial\FinancialCalculator;
use App\Models\FiscalDay;
use App\Models\FiscalInstallation;
use App\Models\InventoryLocation;
use App\Models\InvoiceSeries;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\SaleVoid;
use App\Models\Shift;
use App\Models\StockBalance;
use App\Models\StockMovement;
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
use App\Services\Sales\RefundCalculator;
use App\Services\Sales\RefundService;
use App\Services\Sales\SaleReturnLocator;
use App\Services\Sales\VoidService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The frozen Global Lock Order and the "re-validate at execution" rule, proven with genuinely separate
 * OS processes: two approvers at two terminals race to execute against the same sale. Whatever the
 * interleaving, the ledger must end up consistent -- never two refunds over the cap, never a void and
 * a refund both taking effect. Like CheckoutServiceConcurrencyTest this does not roll back per test
 * (the workers are other connections), so fixtures are committed and cleared explicitly.
 */
class SalesReversalConcurrencyTest extends TestCase
{
    private const DATABASE = 'tindaflow_concurrency_test';

    private static bool $migrated = false;

    private string $scratchDir;

    private string $storeId;

    private string $t1;

    private string $cashierId;

    private string $productId;

    private string $saleId;

    private string $saleItemId;

    /** @var array{terminal: string, user: string} */
    private array $approverA;

    /** @var array{terminal: string, user: string} */
    private array $approverB;

    protected function setUp(): void
    {
        parent::setUp();

        config(PostgresTestConnection::settings(self::DATABASE));
        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');

        if (! self::$migrated) {
            Artisan::call('migrate:fresh', ['--force' => true, '--database' => 'pgsql']);
            self::$migrated = true;
        } else {
            foreach ([
                'electronic_journal_entries', 'audit_events', 'refund_settlements', 'refund_items', 'refunds', 'voids',
                'stock_movements', 'stock_balances', 'invoices', 'payments', 'sale_items', 'sales', 'idempotency_records',
                'invoice_series', 'terminal_fiscal_installations', 'tax_registrations', 'inventory_locations',
                'fiscal_installations', 'products', 'shifts', 'fiscal_days', 'terminals', 'users', 'stores',
            ] as $table) {
                DB::table($table)->delete();
            }
        }

        $shift = Shift::factory()->create();
        $this->storeId = $shift->fiscalDay->store_id;
        $this->t1 = $shift->terminal_id;
        $this->cashierId = $shift->cashier_id;

        $fiscalInstallation = FiscalInstallation::factory()->create(['store_id' => $this->storeId]);
        InvoiceSeries::factory()->create(['store_id' => $this->storeId, 'fiscal_installation_id' => $fiscalInstallation->id]);
        DB::table('terminal_fiscal_installations')->insert([
            'id' => (string) Str::uuid(), 'store_id' => $this->storeId, 'terminal_id' => $this->t1,
            'fiscal_installation_id' => $fiscalInstallation->id, 'effective_from' => now()->subYear(),
            'effective_to' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        InventoryLocation::factory()->create(['store_id' => $this->storeId, 'is_default' => true]);
        DB::table('tax_registrations')->insert([
            'id' => (string) Str::uuid(), 'store_id' => $this->storeId, 'registration_type' => 'VAT',
            'effective_from' => now()->subYear()->toDateString(), 'effective_to' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->productId = Product::factory()->create(['store_id' => $this->storeId, 'selling_price' => '100.00'])->id;

        // Two managers, each at their own terminal with their own fiscal day and shift.
        $this->approverA = $this->manager();
        $this->approverB = $this->manager();

        $sale = (new CheckoutService(
            new IdempotencyService, new CanonicalRequestHasher, new FinancialCalculator, new FiscalInstallationResolver,
            new InventoryLocationResolver, new TaxRegistrationResolver, new InvoiceSeriesAllocator, new StockLedger,
        ))->finalize($this->t1, $this->cashierId, (string) Str::uuid(), [
            'items' => [['product_id' => $this->productId, 'quantity' => '2']],
            'payments' => [['method' => 'CASH', 'amount' => '200.00']],
        ]);
        $this->saleId = $sale->id;
        $this->saleItemId = $sale->items()->firstOrFail()->id;

        $this->scratchDir = sys_get_temp_dir().'/tindaflow_reversal_race_'.Str::random(8);
        mkdir($this->scratchDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scratchDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->scratchDir);

        parent::tearDown();
    }

    /** @return array{terminal: string, user: string} */
    private function manager(): array
    {
        $terminal = Terminal::factory()->create(['store_id' => $this->storeId]);
        $fiscalDay = FiscalDay::factory()->create(['store_id' => $this->storeId, 'terminal_id' => $terminal->id]);
        $manager = User::factory()->manager()->create(['store_id' => $this->storeId]);
        Shift::factory()->create(['terminal_id' => $terminal->id, 'fiscal_day_id' => $fiscalDay->id, 'cashier_id' => $manager->id]);

        return ['terminal' => $terminal->id, 'user' => $manager->id];
    }

    private function refundService(): RefundService
    {
        return new RefundService(
            new IdempotencyService, new CanonicalRequestHasher, new ExecutionContextResolver, new StockLedger,
            new SaleReturnLocator(new InventoryLocationResolver), new RefundCalculator,
        );
    }

    private function voidService(): VoidService
    {
        return new VoidService(
            new IdempotencyService, new CanonicalRequestHasher, new ExecutionContextResolver, new StockLedger,
            new SaleReturnLocator(new InventoryLocationResolver),
        );
    }

    /** A cashier's pending refund request for the whole line, recorded through the real service. */
    private function pendingRefund(): string
    {
        $cashier = User::findOrFail($this->cashierId);
        $terminal = Terminal::findOrFail($this->t1);

        return $this->refundService()->request($terminal, $cashier, $this->saleId, (string) Str::uuid(), [
            'items' => [['sale_item_id' => $this->saleItemId, 'quantity' => '2', 'disposition' => 'RETURN_TO_STOCK']],
            'settlements' => [['payment_method' => 'CASH', 'amount' => '200.00']],
            'reason' => 'Return',
        ])->id;
    }

    private function pendingVoid(): string
    {
        return $this->voidService()->request(Terminal::findOrFail($this->t1), User::findOrFail($this->cashierId), $this->saleId, (string) Str::uuid(), 'Wrong sale')->id;
    }

    public function test_two_approvals_against_the_same_line_never_refund_more_than_was_sold(): void
    {
        $one = $this->pendingRefund();
        $two = $this->pendingRefund();
        $this->assertSame('REQUESTED', Refund::find($one)->status);

        [$a, $b] = $this->race(['refund-approve', $this->approverA, $one], ['refund-approve', $this->approverB, $two]);

        $outcomes = collect([$a, $b]);
        $this->assertCount(1, $outcomes->where('outcome', 'success'), json_encode([$a, $b]));
        $rejected = $outcomes->firstWhere('outcome', 'exception');
        $this->assertSame('REFUND_EXCEEDS_REMAINING_QUANTITY', $rejected['code'], json_encode($rejected));

        $this->assertSame(1, Refund::where('status', 'COMPLETED')->count());
        $this->assertSame(1, Refund::where('status', 'REQUESTED')->count());
        $this->assertSame('2.000', (string) DB::table('refund_items')->sum('quantity_returned'));
        $this->assertSame(1, StockMovement::where('movement_type', 'SALE_RETURN')->count());
        $this->assertSame('0.000', StockBalance::where('product_id', $this->productId)->value('quantity_on_hand'));
        $this->assertSame('REFUNDED', Sale::find($this->saleId)->status);
    }

    public function test_a_void_and_a_refund_racing_for_the_same_sale_never_both_take_effect(): void
    {
        $void = $this->pendingVoid();
        $refund = $this->pendingRefund();

        [$a, $b] = $this->race(['void-approve', $this->approverA, $void], ['refund-approve', $this->approverB, $refund]);

        $outcomes = collect([$a, $b]);
        $this->assertCount(1, $outcomes->where('outcome', 'success'), json_encode([$a, $b]));
        $failed = $outcomes->firstWhere('outcome', 'exception');
        $this->assertContains($failed['code'], ['SALE_NOT_VOIDABLE', 'REFUND_NOT_ALLOWED'], json_encode($failed));

        // Exactly one reversal reached the ledger, and the sale says which.
        $this->assertSame(1, StockMovement::where('movement_type', 'SALE_RETURN')->count());
        $this->assertSame('0.000', StockBalance::where('product_id', $this->productId)->value('quantity_on_hand'));
        $voided = SaleVoid::where('status', 'VOIDED')->count();
        $refunded = Refund::where('status', 'COMPLETED')->count();
        $this->assertSame(1, $voided + $refunded);
        $this->assertSame($voided === 1 ? 'VOIDED' : 'REFUNDED', Sale::find($this->saleId)->status);
    }

    /**
     * @param  array{0: string, 1: array{terminal: string, user: string}, 2: string}  $first  [operation, approver, target id]
     * @param  array{0: string, 1: array{terminal: string, user: string}, 2: string}  $second
     * @return array{0: array, 1: array}
     */
    private function race(array $first, array $second): array
    {
        $script = base_path('tests/Database/support/sales_reversal_worker.php');
        $go = $this->scratchDir.'/go';
        $processes = [];
        $ready = [];
        $out = [];

        foreach ([$first, $second] as $i => [$operation, $approver, $target]) {
            $ready[$i] = $this->scratchDir."/ready_{$i}";
            $out[$i] = $this->scratchDir."/out_{$i}.json";
            $processes[$i] = Process::start([PHP_BINARY, $script, $operation, $approver['terminal'], $approver['user'], $target, (string) Str::uuid(), $ready[$i], $go, $out[$i], self::DATABASE]);
        }

        $deadline = microtime(true) + 15;
        while (! (file_exists($ready[0]) && file_exists($ready[1]))) {
            if (microtime(true) > $deadline) {
                $this->fail('worker processes did not become ready within the timeout');
            }
            usleep(1000);
        }
        file_put_contents($go, '1');

        $results = [];
        foreach ($processes as $i => $process) {
            $result = $process->wait();
            $this->assertTrue($result->successful(), "worker {$i} process failed: {$result->errorOutput()}");
            $this->assertFileExists($out[$i]);
            $results[] = json_decode(file_get_contents($out[$i]), true);
        }

        return $results;
    }
}
