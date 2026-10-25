<?php

namespace Tests\Database;

use App\Domain\Financial\FinancialCalculator;
use App\Models\Employee;
use App\Models\FiscalDay;
use App\Models\InventoryLocation;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Terminal;
use App\Models\User;
use App\Services\Checkout\CheckoutService;
use App\Services\Checkout\FiscalInstallationResolver;
use App\Services\Checkout\InventoryLocationResolver;
use App\Services\Checkout\TaxRegistrationResolver;
use App\Services\Employees\CreditPolicyService;
use App\Services\Idempotency\CanonicalRequestHasher;
use App\Services\Idempotency\IdempotencyService;
use App\Services\Inventory\StockLedger;
use App\Services\InvoiceNumbering\InvoiceSeriesAllocator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-016 slice 3.2, CREDIT-003: the limit holds when tills race. Real OS processes with their own connections, released together,
 * charge the same employee through the real CheckoutService; the employee row lock (Global Lock Order position 2) makes the second
 * one see the first one's charge. A database constraint cannot express a time-windowed sum, so this test is the proof, not a nicety.
 * Slip mode keeps the fixtures small (no fiscal installation or invoice series). Like the other concurrency tests this does not roll
 * back per test: the workers are other connections.
 */
class CreditConcurrencyTest extends TestCase
{
    private const DATABASE = 'tindaflow_concurrency_test';

    private static bool $migrated = false;

    private string $scratchDir;

    private string $storeId;

    /** @var list<array{terminal: string, cashier: string}> */
    private array $tills = [];

    private string $managerTerminal;

    private string $managerId;

    private string $employeeId;

    protected function setUp(): void
    {
        parent::setUp();

        config(PostgresTestConnection::settings(self::DATABASE));
        DB::purge('pgsql');
        DB::setDefaultConnection('pgsql');

        // A fresh schema for every test: it is cheap and leaves nothing for the next one to trip on.
        Artisan::call('migrate:fresh', ['--force' => true, '--database' => 'pgsql']);

        $first = Shift::factory()->create();
        $this->storeId = $first->fiscalDay->store_id;
        $this->tills = [['terminal' => $first->terminal_id, 'cashier' => $first->cashier_id]];
        $second = Terminal::factory()->create(['store_id' => $this->storeId]);
        $cashier = User::factory()->create(['store_id' => $this->storeId]);
        Shift::factory()->create(['terminal_id' => $second->id, 'fiscal_day_id' => FiscalDay::factory()->create(['store_id' => $this->storeId, 'terminal_id' => $second->id])->id, 'cashier_id' => $cashier->id]);
        $this->tills[] = ['terminal' => $second->id, 'cashier' => $cashier->id];

        $third = Terminal::factory()->create(['store_id' => $this->storeId]);
        $manager = User::factory()->manager()->create(['store_id' => $this->storeId]);
        Shift::factory()->create(['terminal_id' => $third->id, 'fiscal_day_id' => FiscalDay::factory()->create(['store_id' => $this->storeId, 'terminal_id' => $third->id])->id, 'cashier_id' => $manager->id]);
        $this->managerTerminal = $third->id;
        $this->managerId = $manager->id;

        InventoryLocation::factory()->create(['store_id' => $this->storeId, 'is_default' => true]);
        DB::table('store_settings')->insert([
            'store_id' => $this->storeId, 'business_name' => 'Canteen', 'registered_name' => '', 'tin' => '', 'sale_document_mode' => 'SLIP',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // The store's limits exist before the race, as they do in production (the migration and the first employee create them). Without
        // them both workers would INSERT the same policy row and the second would queue behind the first on that unique index, which
        // would serialize the race for the wrong reason.
        app(CreditPolicyService::class)->ensureDefaults($this->storeId);
        $this->employeeId = Employee::factory()->create(['store_id' => $this->storeId])->id;

        $this->scratchDir = sys_get_temp_dir().'/tindaflow_credit_race_'.Str::random(8);
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

    private function product(string $price): string
    {
        return Product::factory()->create(['store_id' => $this->storeId, 'selling_price' => $price])->id;
    }

    /** @return array<string, mixed> */
    private function chargeJob(int $till, string $productId, string $amount): array
    {
        return [
            'action' => 'charge', 'terminal_id' => $this->tills[$till % 2]['terminal'], 'cashier_id' => $this->tills[$till % 2]['cashier'],
            'key' => (string) Str::uuid(), 'product_id' => $productId, 'employee_id' => $this->employeeId, 'amount' => $amount,
            'pause_ms' => 300,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $jobs
     * @return list<array<string, mixed>>
     */
    private function race(array $jobs): array
    {
        $script = base_path('tests/Database/support/credit_checkout_worker.php');
        $go = $this->scratchDir.'/go';
        $processes = [];

        foreach ($jobs as $index => $job) {
            file_put_contents($this->scratchDir."/job_{$index}.json", json_encode($job));
            $processes[$index] = Process::start([
                PHP_BINARY, $script, $this->scratchDir."/job_{$index}.json", $this->scratchDir."/ready_{$index}",
                $go, $this->scratchDir."/out_{$index}.json", self::DATABASE,
            ]);
        }

        $deadline = microtime(true) + 30;
        while (count(glob($this->scratchDir.'/ready_*') ?: []) < count($jobs)) {
            if (microtime(true) > $deadline) {
                $this->fail('worker processes did not become ready within the timeout');
            }
            usleep(1000);
        }
        file_put_contents($go, '1');

        $outcomes = [];
        foreach ($processes as $index => $process) {
            $result = $process->wait();
            $this->assertTrue($result->successful(), "worker {$index} failed: {$result->errorOutput()}");
            $outcomes[] = json_decode(file_get_contents($this->scratchDir."/out_{$index}.json"), true);
        }

        return $outcomes;
    }

    private function net(): string
    {
        return bcadd((string) DB::table('employee_credit_entries')->where('employee_id', $this->employeeId)
            ->selectRaw("COALESCE(SUM(CASE WHEN entry_type = 'CHARGE' THEN amount ELSE -amount END), 0) AS net")->value('net'), '0', 2);
    }

    public function test_two_tills_charging_the_same_employee_near_the_limit_exactly_one_succeeds(): void
    {
        $product = $this->product('800.00');

        // 800 + 800 would be 1600 on a limit of 1500: whichever till gets the employee lock first wins, the other must see its charge.
        $outcomes = $this->race([$this->chargeJob(0, $product, '800.00'), $this->chargeJob(1, $product, '800.00')]);

        $this->assertCount(1, array_filter($outcomes, fn ($o) => $o['outcome'] === 'success'), json_encode($outcomes));
        $refused = array_values(array_filter($outcomes, fn ($o) => $o['outcome'] === 'exception'));
        $this->assertCount(1, $refused, json_encode($outcomes));
        $this->assertSame('CREDIT_LIMIT_EXCEEDED', $refused[0]['code'], json_encode($refused));
        $this->assertSame(['limit' => '1500.00', 'used' => '800.00', 'remaining' => '700.00', 'requested' => '800.00'], array_intersect_key($refused[0]['details'], array_flip(['limit', 'used', 'remaining', 'requested'])));

        $this->assertSame(1, DB::table('sales')->where('employee_id', $this->employeeId)->count());
        $this->assertSame(1, DB::table('employee_credit_entries')->where('employee_id', $this->employeeId)->count());
        $this->assertSame('800.00', $this->net());
    }

    public function test_four_racing_charges_never_take_the_period_past_the_limit(): void
    {
        $product = $this->product('500.00');

        $outcomes = $this->race(array_map(fn (int $till) => $this->chargeJob($till, $product, '500.00'), [0, 1, 0, 1]));

        $this->assertCount(3, array_filter($outcomes, fn ($o) => $o['outcome'] === 'success'), json_encode($outcomes));
        foreach (array_filter($outcomes, fn ($o) => $o['outcome'] !== 'success') as $refused) {
            $this->assertSame('CREDIT_LIMIT_EXCEEDED', $refused['code'], json_encode($refused));
        }
        $this->assertSame('1500.00', $this->net(), 'the limit is met exactly and never passed');
        $this->assertSame(3, DB::table('employee_credit_entries')->count());
    }

    public function test_a_charge_racing_a_void_of_an_earlier_credit_sale_neither_deadlocks_nor_corrupts_the_balance(): void
    {
        // 1,400 is already charged. A void gives it back; a new charge of 600 only fits if the void got there first.
        $sale = $this->checkout(0, $this->product('1400.00'), '1400.00');
        $this->assertSame('1400.00', $this->net());

        $outcomes = $this->race([
            $this->chargeJob(1, $this->product('600.00'), '600.00'),
            ['action' => 'void', 'terminal_id' => $this->managerTerminal, 'user_id' => $this->managerId, 'sale_id' => $sale, 'key' => (string) Str::uuid()],
        ]);

        [$charge, $void] = $outcomes;
        $this->assertSame('success', $void['outcome'], json_encode($void));
        $this->assertSame('VOIDED', $void['status']);
        if ($charge['outcome'] === 'success') {
            $this->assertSame('600.00', $this->net(), 'the void was applied first, then the charge fit');
        } else {
            $this->assertSame('CREDIT_LIMIT_EXCEEDED', $charge['code'], json_encode($charge));
            $this->assertSame('0.00', $this->net(), 'the charge went first and was refused; the void then took the old charge back');
        }
        $this->assertSame(1, DB::table('employee_credit_entries')->where('entry_type', 'REVERSAL')->count());
    }

    private function checkout(int $till, string $productId, string $amount): string
    {
        $sale = (new CheckoutService(
            new IdempotencyService, new CanonicalRequestHasher, new FinancialCalculator, new FiscalInstallationResolver,
            new InventoryLocationResolver, new TaxRegistrationResolver, new InvoiceSeriesAllocator, new StockLedger,
        ))->finalize($this->tills[$till]['terminal'], $this->tills[$till]['cashier'], (string) Str::uuid(), [
            'items' => [['product_id' => $productId, 'quantity' => '1']],
            'payments' => [['method' => 'CREDIT', 'amount' => $amount]],
            'employee_id' => $this->employeeId,
        ]);

        return $sale->id;
    }
}
