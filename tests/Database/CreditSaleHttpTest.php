<?php

namespace Tests\Database;

use App\Models\Employee;
use App\Models\EmployeeCreditEntry;
use App\Models\Product;
use App\Models\Shift;
use App\Models\Store;
use App\Services\Shift\ShiftReadingAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * ADR-016 slice 3.2 (invariants.md CREDIT-001..004): a sale charged to an employee, its limit per cut-off, the ledger, the drawer and the
 * void / refund reversals. Built through the real endpoints. The two-connection race is CreditConcurrencyTest.
 */
class CreditSaleHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tindaflow.login_throttle.max_attempts' => 100000]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function at(string $manila): void
    {
        $instant = CarbonImmutable::parse($manila, 'Asia/Manila');
        CarbonImmutable::setTestNow($instant);
        Carbon::setTestNow(Carbon::instance($instant));
    }

    /** @param  array<string, mixed>  $w */
    private function employee(array $w, string $type = 'direct', array $attributes = []): Employee
    {
        $factory = Employee::factory();

        return ($type === 'sub' ? $factory->subcontractor() : $factory)->create(['store_id' => $w['storeId']] + $attributes);
    }

    private function product(array $w, string $price): Product
    {
        return Product::factory()->create(['store_id' => $w['storeId'], 'selling_price' => $price, 'cost' => '1.00']);
    }

    /**
     * @param  array<string, mixed>  $w
     * @param  array<string, mixed>  $extra
     */
    private function charge(array $w, ?Employee $employee, ?Product $product = null, string $quantity = '1', array $extra = [], ?array $headers = null): TestResponse
    {
        $product ??= $w['product'];
        $total = bcmul((string) $product->selling_price, $quantity, 2);

        return $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => $quantity]],
            'payments' => [['method' => 'CREDIT', 'amount' => $total]],
        ] + ($employee === null ? [] : ['employee_id' => $employee->id]) + $extra, $headers ?? $this->key());
    }

    private function net(Employee $employee, string $periodStart): string
    {
        $row = DB::table('employee_credit_entries')->where('employee_id', $employee->id)->where('period_start', $periodStart)
            ->selectRaw("COALESCE(SUM(CASE WHEN entry_type = 'CHARGE' THEN amount ELSE -amount END), 0) AS net")->value('net');

        return bcadd((string) $row, '0', 2);
    }

    // ------------------------------------------------------------------ the sale

    public function test_a_credit_sale_records_the_employee_a_credit_payment_and_one_charge_and_no_change(): void
    {
        $w = $this->world();
        $employee = $this->employee($w, attributes: ['first_name' => 'Ana', 'last_name' => 'Reyes', 'employee_code' => 'TSC00012']);
        $this->at('2026-10-10 12:00:00');

        $sale = $this->charge($w, $employee, quantity: '2')->assertStatus(201)->json();

        $this->assertSame('200.00', $sale['grand_total']);
        $this->assertSame([['method' => 'CREDIT', 'amount' => '200.00']], array_map(fn ($p) => ['method' => $p['method'], 'amount' => $p['amount']], $sale['payments']));
        $this->assertSame('200.00', $sale['amount_tendered']);
        $this->assertSame('0.00', $sale['change']);
        $this->assertSame(['id' => $employee->id, 'employee_code' => 'TSC00012', 'name' => 'Ana Reyes'], $sale['employee']);
        $this->assertSame($employee->id, DB::table('sales')->where('id', $sale['id'])->value('employee_id'));

        $entry = EmployeeCreditEntry::where('sale_id', $sale['id'])->sole();
        $this->assertSame('CHARGE', $entry->entry_type);
        $this->assertSame('200.00', (string) $entry->amount);
        $this->assertSame(['2026-10-01', '2026-10-15'], [$entry->period_start->toDateString(), $entry->period_end->toDateString()]);
        $this->assertSame(['TSC00012', 'Ana Reyes', 'DIRECT'], [$entry->employee_code, $entry->employee_name, $entry->employee_type]);
        $this->assertSame($sale['id'], DB::table('audit_events')->where('event_type', 'SALE_FINALIZED')->where('entity_id', $sale['id'])->value('entity_id'));
        $audit = json_decode(DB::table('audit_events')->where('event_type', 'SALE_FINALIZED')->where('entity_id', $sale['id'])->value('after_metadata'), true);
        $this->assertSame(['TSC00012', 'CREDIT'], [$audit['employee_code'], $audit['payment_method']]);
    }

    public function test_a_credit_sale_adds_nothing_to_the_drawer_and_shows_as_its_own_non_cash_line(): void
    {
        $w = $this->world();
        $employee = $this->employee($w);
        $shift = $w['cashierShift'];
        $before = app(ShiftReadingAggregator::class)->aggregate($shift);

        $this->charge($w, $employee, quantity: '3')->assertStatus(201);
        $afterCredit = app(ShiftReadingAggregator::class)->aggregate($shift->fresh());

        // CREDIT-002: expected cash, cash sales and the cash breakdown are exactly what they were.
        foreach (['expected_cash', 'cash_sales', 'refunds_total', 'cash_in_total', 'cash_out_total'] as $field) {
            $this->assertSame($before[$field], $afterCredit[$field], $field);
        }
        $this->assertSame('300.00', $afterCredit['non_cash_sales']);
        $this->assertEquals((object) ['CREDIT' => '300.00'], $afterCredit['payment_breakdown']);
        $this->assertSame(1, $afterCredit['transaction_count']);

        // A cash sale with change next to it behaves as it always did, and the credit stays a separate line.
        $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => [['product_id' => $w['product']->id, 'quantity' => '1']],
            'payments' => [['method' => 'CASH', 'amount' => '120.00']],
        ], $this->key())->assertStatus(201);
        $both = app(ShiftReadingAggregator::class)->aggregate($shift->fresh());

        $this->assertSame('100.00', $both['cash_sales'], 'change given is not collected');
        $this->assertSame(bcadd((string) $shift->opening_cash, '100.00', 2), $both['expected_cash']);
        $this->assertEquals((object) ['CASH' => '100.00', 'CREDIT' => '300.00'], $both['payment_breakdown']);
        $this->assertSame('300.00', $both['non_cash_sales']);
    }

    public function test_the_limit_is_met_exactly_but_one_centavo_over_is_refused_with_the_figures_the_till_shows(): void
    {
        $w = $this->world();
        $employee = $this->employee($w, attributes: ['employee_code' => 'TSC00031']);
        $this->at('2026-10-10 12:00:00');

        $this->charge($w, $employee, $this->product($w, '1400.00'))->assertStatus(201);
        $refused = $this->charge($w, $employee, $this->product($w, '100.01'));

        $refused->assertStatus(409)->assertJsonPath('error.code', 'CREDIT_LIMIT_EXCEEDED');
        $this->assertSame(
            ['employee_code' => 'TSC00031', 'limit' => '1500.00', 'used' => '1400.00', 'remaining' => '100.00', 'requested' => '100.01', 'period_start' => '2026-10-01', 'period_end' => '2026-10-15'],
            $refused->json('error.details'),
        );
        $this->assertSame(1, DB::table('sales')->where('employee_id', $employee->id)->count(), 'a refused sale leaves nothing behind');
        $this->assertSame('1400.00', $this->net($employee, '2026-10-01'));

        $this->charge($w, $employee, $this->product($w, '100.00'))->assertStatus(201);
        $this->assertSame('1500.00', $this->net($employee, '2026-10-01'));
        $this->charge($w, $employee, $this->product($w, '0.01'))->assertStatus(409)->assertJsonPath('error.details.remaining', '0.00');
    }

    public function test_subcontractors_have_their_own_lower_limit(): void
    {
        $w = $this->world();
        $direct = $this->employee($w);
        $sub = $this->employee($w, 'sub');
        $this->at('2026-10-10 12:00:00');

        $this->charge($w, $sub, $this->product($w, '1000.01'))->assertStatus(409)->assertJsonPath('error.details.limit', '1000.00');
        $this->charge($w, $sub, $this->product($w, '1000.00'))->assertStatus(201);
        $this->charge($w, $direct, $this->product($w, '1000.01'))->assertStatus(201);

        // The limit is a setting per type (credit_policies), not a constant.
        DB::table('credit_policies')->where('store_id', $w['storeId'])->where('employee_type', 'SUBCONTRACTOR')->update(['limit_per_cutoff' => '1200.00']);
        $other = $this->employee($w, 'sub');
        $this->charge($w, $other, $this->product($w, '1200.00'))->assertStatus(201);
    }

    public function test_the_balance_starts_over_on_the_16th_and_on_the_1st_and_midnight_is_decided_in_manila(): void
    {
        $w = $this->world();
        $employee = $this->employee($w);
        $big = $this->product($w, '1500.00');

        $this->at('2026-10-15 23:30:00');
        $this->charge($w, $employee, $big)->assertStatus(201);
        $this->charge($w, $employee, $this->product($w, '0.01'))->assertStatus(409);

        $this->at('2026-10-16 00:30:00');
        $this->charge($w, $employee, $big)->assertStatus(201, 'the 16th starts a new period (00:30 in Manila is still the 15th in UTC)');
        $this->charge($w, $employee, $this->product($w, '0.01'))->assertStatus(409);

        $this->at('2026-10-31 23:59:00');
        $this->charge($w, $employee, $this->product($w, '0.01'))->assertStatus(409, 'the 31st still belongs to the 16th-end period');

        $this->at('2026-11-01 00:05:00');
        $this->charge($w, $employee, $big)->assertStatus(201);

        $this->assertSame('1500.00', $this->net($employee, '2026-10-01'));
        $this->assertSame('1500.00', $this->net($employee, '2026-10-16'));
        $this->assertSame('1500.00', $this->net($employee, '2026-11-01'));
        $this->assertSame('2026-10-31', EmployeeCreditEntry::where('period_start', '2026-10-16')->value('period_end')->toDateString());
    }

    public function test_credit_must_be_the_only_payment_and_must_name_an_employee(): void
    {
        $w = $this->world();
        $employee = $this->employee($w);
        $items = [['product_id' => $w['product']->id, 'quantity' => '1']];
        $post = fn (array $body) => $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', $body + ['items' => $items], $this->key());

        $post(['payments' => [['method' => 'CREDIT', 'amount' => '60.00'], ['method' => 'CASH', 'amount' => '40.00']], 'employee_id' => $employee->id])
            ->assertStatus(422)->assertJsonPath('error.code', 'CREDIT_MIXED_TENDER');
        $post(['payments' => [['method' => 'CREDIT', 'amount' => '100.00'], ['method' => 'CREDIT', 'amount' => '100.00']], 'employee_id' => $employee->id])
            ->assertStatus(422)->assertJsonPath('error.code', 'CREDIT_MIXED_TENDER');
        $post(['payments' => [['method' => 'CREDIT', 'amount' => '100.00']]])
            ->assertStatus(422)->assertJsonPath('error.code', 'CREDIT_EMPLOYEE_REQUIRED');
        $post(['payments' => [['method' => 'CASH', 'amount' => '100.00']], 'employee_id' => $employee->id])
            ->assertStatus(422)->assertJsonPath('error.code', 'CREDIT_EMPLOYEE_REQUIRED');
        $post(['payments' => [['method' => 'CREDIT', 'amount' => '100.00']], 'employee_id' => 'not-a-uuid'])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertSame(0, DB::table('sales')->count());
        $this->assertSame(0, EmployeeCreditEntry::count());
    }

    public function test_an_inactive_unknown_or_foreign_employee_cannot_be_charged(): void
    {
        $w = $this->world();
        $inactive = $this->employee($w, attributes: ['active' => false]);
        $foreign = Employee::factory()->create(['store_id' => Store::factory()->create()->id]);

        $this->charge($w, $inactive)->assertStatus(409)->assertJsonPath('error.code', 'EMPLOYEE_INACTIVE');
        $this->charge($w, $foreign)->assertStatus(404)->assertJsonPath('error.code', 'EMPLOYEE_NOT_FOUND');
        $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => [['product_id' => $w['product']->id, 'quantity' => '1']],
            'payments' => [['method' => 'CREDIT', 'amount' => '100.00']],
            'employee_id' => '0194b2c0-0000-7000-8000-000000000000',
        ], $this->key())->assertStatus(404)->assertJsonPath('error.code', 'EMPLOYEE_NOT_FOUND');
        $this->assertSame(0, DB::table('sales')->count());
    }

    public function test_the_credit_amount_is_always_the_server_total_even_when_the_till_previewed_a_higher_one(): void
    {
        $w = $this->world();
        $employee = $this->employee($w);

        // A Senior Citizen discount is worked out only by the server, so the till's preview can be higher than the real total.
        $sale = $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => [['product_id' => $w['product']->id, 'quantity' => '1']],
            'payments' => [['method' => 'CREDIT', 'amount' => '500.00']],
            'employee_id' => $employee->id,
        ], $this->key())->assertStatus(201)->json();

        $this->assertSame('100.00', $sale['payments'][0]['amount']);
        $this->assertSame('0.00', $sale['change']);
        $this->assertSame('100.00', $this->net($employee, now('Asia/Manila')->day <= 15 ? now('Asia/Manila')->format('Y-m-01') : now('Asia/Manila')->format('Y-m-16')));

        $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => [['product_id' => $w['product']->id, 'quantity' => '1']],
            'payments' => [['method' => 'CREDIT', 'amount' => '99.99']],
            'employee_id' => $employee->id,
        ], $this->key())->assertStatus(422)->assertJsonPath('error.code', 'INSUFFICIENT_PAYMENT');
    }

    public function test_a_replay_returns_the_same_sale_and_a_different_employee_under_the_same_key_conflicts(): void
    {
        $w = $this->world();
        $employee = $this->employee($w);
        $other = $this->employee($w);
        $headers = $this->key();

        $first = $this->charge($w, $employee, headers: $headers)->assertStatus(201)->json();
        $replay = $this->charge($w, $employee, headers: $headers)->assertStatus(201)->json();
        $this->assertSame($first['id'], $replay['id']);
        $this->assertSame(1, EmployeeCreditEntry::where('employee_id', $employee->id)->count(), 'a replay charges once');

        $this->charge($w, $other, headers: $headers)->assertStatus(409)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
        $this->assertSame(0, EmployeeCreditEntry::where('employee_id', $other->id)->count());
    }

    // ------------------------------------------------------------------ void and refund

    public function test_a_void_reverses_the_whole_charge_in_the_original_period_even_after_the_cut_off(): void
    {
        $w = $this->world();
        $employee = $this->employee($w);
        $this->at('2026-10-14 10:00:00');
        $sale = $this->charge($w, $employee, quantity: '5')->assertStatus(201)->json();
        $this->assertSame('500.00', $this->net($employee, '2026-10-01'));

        // Six days later, in the next period; the fiscal day is still open, so the void is allowed.
        $this->at('2026-10-20 10:00:00');
        $this->charge($w, $employee, quantity: '2')->assertStatus(201);
        $void = $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Wrong order'], $this->key())->assertStatus(201);
        $this->assertSame('VOIDED', $void->json('status'));

        $reversal = EmployeeCreditEntry::where('sale_id', $sale['id'])->where('entry_type', 'REVERSAL')->sole();
        $this->assertSame('500.00', (string) $reversal->amount);
        $this->assertSame('2026-10-01', $reversal->period_start->toDateString());
        $this->assertSame($void->json('id'), $reversal->void_id);
        $this->assertSame('0.00', $this->net($employee, '2026-10-01'), 'the old period owes nothing');
        $this->assertSame('200.00', $this->net($employee, '2026-10-16'), 'the current period is untouched by the void');

        // No second reversal: the sale is voided, so neither a second void nor a refund is possible.
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Again'], $this->key())->assertStatus(409);
        $this->assertSame(1, EmployeeCreditEntry::where('sale_id', $sale['id'])->where('entry_type', 'REVERSAL')->count());
    }

    /** @return array{0: array<string, mixed>, 1: string} */
    private function refundBody(string $itemId, string $quantity, string $method, string $amount): array
    {
        return [[
            'items' => [['sale_item_id' => $itemId, 'quantity' => $quantity, 'disposition' => 'RETURN_TO_STOCK']],
            'settlements' => [['payment_method' => $method, 'amount' => $amount]],
            'reason' => 'Customer returned it',
        ], $amount];
    }

    public function test_a_refund_of_a_credit_sale_settles_to_credit_and_reverses_in_the_original_period_with_no_cash_movement(): void
    {
        $w = $this->world();
        $employee = $this->employee($w);
        $this->at('2026-10-12 10:00:00');
        $sale = $this->charge($w, $employee, quantity: '3')->assertStatus(201)->json();
        $itemId = $sale['items'][0]['id'];

        // After the cut-off has passed (16th onwards) the refund still reduces the OLD period.
        $this->at('2026-10-18 10:00:00');
        [$body] = $this->refundBody($itemId, '1', 'CREDIT', '100.00');
        $refund = $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", $body, $this->key())->assertStatus(201);

        $this->assertSame([['payment_method' => 'CREDIT', 'amount' => '100.00']], collect($refund->json('settlements'))->map(fn ($s) => ['payment_method' => $s['payment_method'], 'amount' => $s['amount']])->all());
        $reversal = EmployeeCreditEntry::where('refund_id', $refund->json('id'))->sole();
        $this->assertSame(['REVERSAL', '100.00', '2026-10-01'], [$reversal->entry_type, (string) $reversal->amount, $reversal->period_start->toDateString()]);
        $this->assertSame('200.00', $this->net($employee, '2026-10-01'));
        $this->assertSame('0.00', $this->net($employee, '2026-10-16'));

        // Nothing leaves the drawer: the manager's shift sees no cash refund.
        $reading = app(ShiftReadingAggregator::class)->aggregate(Shift::findOrFail($refund->json('shift_id')));
        $this->assertSame('100.00', $reading['refunds_total']);
        $this->assertSame($reading['opening_cash'], $reading['expected_cash'], 'a credit refund pays out no cash');

        // The rest of the sale is refunded too: reversals add up to the charge, never more.
        [$rest] = $this->refundBody($itemId, '2', 'CREDIT', '200.00');
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", $rest, $this->key())->assertStatus(201);
        $this->assertSame('0.00', $this->net($employee, '2026-10-01'));
        $this->assertSame(2, EmployeeCreditEntry::where('sale_id', $sale['id'])->where('entry_type', 'REVERSAL')->count());
    }

    public function test_a_credit_sale_cannot_be_refunded_to_cash_and_a_cash_sale_cannot_be_refunded_to_credit(): void
    {
        $w = $this->world();
        $employee = $this->employee($w);
        $credit = $this->charge($w, $employee)->assertStatus(201)->json();
        $cash = $this->ring($w);

        foreach ([['CASH', '100.00'], ['GCASH', '100.00']] as [$method, $amount]) {
            [$body] = $this->refundBody($credit['items'][0]['id'], '1', $method, $amount);
            $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$credit['id']}/refunds", $body, $this->key())
                ->assertStatus(422)->assertJsonPath('error.code', 'CREDIT_REFUND_SETTLEMENT_INVALID');
        }
        [$body] = $this->refundBody($cash['items'][0]['id'], '1', 'CREDIT', '100.00');
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$cash['id']}/refunds", $body, $this->key())
            ->assertStatus(422)->assertJsonPath('error.code', 'CREDIT_REFUND_SETTLEMENT_INVALID');

        $this->assertSame(0, EmployeeCreditEntry::where('entry_type', 'REVERSAL')->count());
        $this->assertSame(0, DB::table('refunds')->count());
    }

    public function test_a_cashier_requested_refund_of_a_credit_sale_is_approved_later_and_reverses_then(): void
    {
        $w = $this->world();
        $employee = $this->employee($w);
        $this->at('2026-10-05 10:00:00');
        $sale = $this->charge($w, $employee, quantity: '2')->assertStatus(201)->json();

        [$cashOut] = $this->refundBody($sale['items'][0]['id'], '1', 'CASH', '100.00');
        $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/sales/{$sale['id']}/refunds", $cashOut, $this->key())
            ->assertStatus(422)->assertJsonPath('error.code', 'CREDIT_REFUND_SETTLEMENT_INVALID');

        [$body] = $this->refundBody($sale['items'][0]['id'], '1', 'CREDIT', '100.00');
        $requested = $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/sales/{$sale['id']}/refunds", $body, $this->key())->assertStatus(201);
        $this->assertSame('REQUESTED', $requested->json('status'));
        $this->assertSame(0, EmployeeCreditEntry::where('entry_type', 'REVERSAL')->count(), 'nothing is reversed until the refund is approved');

        $this->at('2026-10-17 09:00:00');
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/refunds/{$requested->json('id')}/approve", [], $this->key())->assertOk();
        $this->assertSame('2026-10-01', EmployeeCreditEntry::where('entry_type', 'REVERSAL')->sole()->period_start->toDateString());
        $this->assertSame('100.00', $this->net($employee, '2026-10-01'));
    }

    // ------------------------------------------------------------------ the ledger itself

    public function test_the_ledger_refuses_a_second_charge_for_a_sale_and_a_second_reversal_for_a_void(): void
    {
        $w = $this->world();
        $employee = $this->employee($w);
        $sale = $this->charge($w, $employee)->assertStatus(201)->json();
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Test'], $this->key())->assertStatus(201);

        $charge = DB::table('employee_credit_entries')->where('sale_id', $sale['id'])->where('entry_type', 'CHARGE')->first();
        $reversal = DB::table('employee_credit_entries')->where('sale_id', $sale['id'])->where('entry_type', 'REVERSAL')->first();
        $copy = fn (object $row) => collect((array) $row)->except('id')->all() + ['id' => (string) Str::uuid()];

        foreach ([$charge, $reversal] as $row) {
            DB::beginTransaction();
            try {
                DB::table('employee_credit_entries')->insert($copy($row));
                $this->fail('a duplicate ledger row must be refused');
            } catch (QueryException $e) {
                $this->assertSame('23505', $e->getCode());
            } finally {
                DB::rollBack();
            }
        }
        foreach (['amount' => '0.00', 'entry_type' => 'OTHER'] as $column => $value) {
            DB::beginTransaction();
            try {
                DB::table('employee_credit_entries')->insert([$column => $value] + $copy($charge));
                $this->fail("{$column} must be constrained");
            } catch (QueryException $e) {
                $this->assertContains($e->getCode(), ['23514', '23505']);
            } finally {
                DB::rollBack();
            }
        }
    }

    // ------------------------------------------------------------------ lookup, status, history, documents

    public function test_the_lookup_and_the_credit_status_show_the_limit_used_and_remaining_for_the_current_cut_off(): void
    {
        $w = $this->world();
        $employee = $this->employee($w, 'sub', ['employee_code' => 'SUB00044']);
        $this->at('2026-10-20 10:00:00');
        $this->charge($w, $employee, $this->product($w, '250.50'))->assertStatus(201);

        $expected = ['limit' => '1000.00', 'used' => '250.50', 'remaining' => '749.50', 'period_start' => '2026-10-16', 'period_end' => '2026-10-31'];
        $lookup = $this->asUser($w['cashier'], $w['enroll1'])->getJson('/api/v1/employees/lookup?code=sub00044')->assertOk();
        $this->assertSame($expected, $lookup->json('credit'));
        $status = $this->asUser($w['cashier'], $w['enroll1'])->getJson("/api/v1/employees/{$employee->id}/credit-status")->assertOk();
        $this->assertSame($expected, $status->json('credit'));
        $this->assertSame(['SUB00044', 'SUBCONTRACTOR'], [$status->json('employee_code'), $status->json('employee_type')]);

        // The next period starts clean.
        $this->at('2026-11-02 10:00:00');
        $this->assertSame(['limit' => '1000.00', 'used' => '0.00', 'remaining' => '1000.00', 'period_start' => '2026-11-01', 'period_end' => '2026-11-15'],
            $this->asUser($w['cashier'], $w['enroll1'])->getJson("/api/v1/employees/{$employee->id}/credit-status")->json('credit'));
    }

    public function test_the_credit_status_is_store_scoped_and_refuses_an_inactive_employee(): void
    {
        $w = $this->world();
        $foreign = Employee::factory()->create(['store_id' => Store::factory()->create()->id]);
        $inactive = $this->employee($w, attributes: ['active' => false]);

        $this->asUser($w['cashier'])->getJson("/api/v1/employees/{$foreign->id}/credit-status")->assertStatus(404)->assertJsonPath('error.code', 'EMPLOYEE_NOT_FOUND');
        $this->asUser($w['cashier'])->getJson("/api/v1/employees/{$inactive->id}/credit-status")->assertStatus(409)->assertJsonPath('error.code', 'EMPLOYEE_INACTIVE');
        $this->asUser($w['cashier'])->getJson('/api/v1/employees/not-a-uuid/credit-status')->assertStatus(404);
    }

    public function test_the_lookup_and_the_credit_status_are_rate_limited_per_user_with_the_catalogued_code(): void
    {
        config(['tindaflow.employee_lookup_throttle.per_minute' => 3]);
        $w = $this->world();
        $employee = $this->employee($w, attributes: ['employee_code' => 'TSC00500']);
        $client = $this->asUser($w['cashier']);

        $client->getJson('/api/v1/employees/lookup?code=TSC00500')->assertOk();
        $client->getJson("/api/v1/employees/{$employee->id}/credit-status")->assertOk();
        $client->getJson('/api/v1/employees/lookup?code=TSC00501')->assertStatus(404);
        $limited = $client->getJson('/api/v1/employees/lookup?code=TSC00502');

        $limited->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertNotNull($limited->headers->get('Retry-After'));
        $client->getJson("/api/v1/employees/{$employee->id}/credit-status")->assertStatus(429);
        // Another person is not affected, and the rest of the API is not.
        $this->asUser($w['manager'])->getJson('/api/v1/employees/lookup?code=TSC00500')->assertOk();
        $client->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_sales_history_shows_the_employee_and_can_be_filtered_by_code_and_by_the_credit_method(): void
    {
        $w = $this->world();
        $ana = $this->employee($w, attributes: ['employee_code' => 'TSC00071', 'first_name' => 'Ana', 'last_name' => 'Cruz']);
        $ben = $this->employee($w, attributes: ['employee_code' => 'TSC00072']);
        $a = $this->charge($w, $ana)->assertStatus(201)->json();
        $b = $this->charge($w, $ben)->assertStatus(201)->json();
        $cash = $this->ring($w);

        $byCode = $this->asUser($w['manager'])->getJson('/api/v1/sales?employee_code=tsc00071')->assertOk();
        $this->assertSame([$a['id']], $byCode->json('data.*.id'));
        $this->assertSame(['id' => $ana->id, 'employee_code' => 'TSC00071', 'name' => 'Ana Cruz'], $byCode->json('data.0.employee'));
        $this->assertEqualsCanonicalizing([$a['id'], $b['id']], $this->asUser($w['manager'])->getJson('/api/v1/sales?payment_method=CREDIT')->json('data.*.id'));
        $this->assertSame([], $this->asUser($w['manager'])->getJson('/api/v1/sales?employee_code=NOPE')->json('data'));

        // A sale that is not a credit sale carries no employee key at all.
        $all = $this->asUser($w['manager'])->getJson('/api/v1/sales')->json('data');
        $this->assertArrayNotHasKey('employee', collect($all)->firstWhere('id', $cash['id']));
        $detail = $this->asUser($w['manager'])->getJson("/api/v1/sales/{$a['id']}")->assertOk();
        $this->assertSame('TSC00071', $detail->json('employee.employee_code'));
        $this->assertSame('CREDIT', $detail->json('payments.0.method'));
    }

    public function test_in_invoice_mode_a_credit_invoice_names_the_employee_and_every_other_snapshot_is_unchanged(): void
    {
        $w = $this->world();
        $employee = $this->employee($w, attributes: ['employee_code' => 'TSC00081', 'first_name' => 'Cora', 'last_name' => 'Lim']);

        $cash = $this->ring($w);
        $credit = $this->charge($w, $employee)->assertStatus(201)->json();

        $cashSnapshot = json_decode(DB::table('invoices')->where('sale_id', $cash['id'])->value('invoice_snapshot_json'), true);
        $creditSnapshot = json_decode(DB::table('invoices')->where('sale_id', $credit['id'])->value('invoice_snapshot_json'), true);
        $this->assertArrayNotHasKey('credit_employee', $cashSnapshot, 'a cash invoice snapshot has no new key');
        $this->assertSame(['code' => 'TSC00081', 'name' => 'Cora Lim'], $creditSnapshot['credit_employee']);
        $this->assertEquals([['method' => 'CREDIT', 'amount' => '100.00']], $creditSnapshot['payments']);
        $this->assertSame('0.00', $creditSnapshot['change']);

        $html = $this->asUser($w['cashier'])->getJson("/api/v1/invoices/{$credit['invoice']['id']}")->assertOk()->json('render_html');
        $this->assertStringContainsString('CREDIT', $html);
        $this->assertStringContainsString('Charged to: Cora Lim (TSC00081)', $html);
        $cashHtml = $this->asUser($w['cashier'])->getJson("/api/v1/invoices/{$cash['invoice']['id']}")->assertOk()->json('render_html');
        $this->assertStringNotContainsString('Charged to', $cashHtml);
    }

    public function test_in_slip_mode_the_slip_prints_the_employee_name_and_code_and_credit_as_the_payment(): void
    {
        $w = $this->world();
        $w['t1']->update(['terminal_code' => 'PARK-1']);
        $w['t2']->update(['terminal_code' => 'PARK-2']);
        $w['t1']->update(['terminal_code' => 'T-01']);
        DB::table('store_settings')->updateOrInsert(['store_id' => $w['storeId']], [
            'business_name' => 'Canteen One', 'registered_name' => '', 'tin' => '', 'sale_document_mode' => 'SLIP', 'slip_footer' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $employee = $this->employee($w, attributes: ['employee_code' => 'TSC00091', 'first_name' => 'Dina', 'middle_name' => 'S', 'last_name' => 'Ramos']);

        $sale = $this->charge($w, $employee, quantity: '2')->assertStatus(201)->json();
        $this->assertSame('SLIP', $sale['document_mode']);

        $snapshot = json_decode(DB::table('sale_slips')->where('sale_id', $sale['id'])->value('slip_snapshot_json'), true);
        $this->assertSame(['code' => 'TSC00091', 'name' => 'Dina S Ramos'], $snapshot['employee']);
        $this->assertSame('CREDIT', $snapshot['payments'][0]['method']);
        $this->assertSame('0.00', $snapshot['change']);

        $html = $this->asUser($w['cashier'])->getJson("/api/v1/sale-slips/{$sale['slip']['id']}")->assertOk()->json('render_html');
        $this->assertStringContainsString('Dina S Ramos', $html);
        $this->assertStringContainsString('TSC00091', $html);
        $this->assertMatchesRegularExpression('/<span>CREDIT<\/span><span>200\.00<\/span>/', $html);
        $this->assertStringNotContainsString('Change', $html);

        // A cash slip has neither the key nor the lines.
        $cashSale = $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => [['product_id' => $w['product']->id, 'quantity' => '1']], 'payments' => [['method' => 'CASH', 'amount' => '100.00']],
        ], $this->key())->assertStatus(201)->json();
        $cashSnapshot = json_decode(DB::table('sale_slips')->where('sale_id', $cashSale['id'])->value('slip_snapshot_json'), true);
        $this->assertArrayNotHasKey('employee', $cashSnapshot);

        // A reprint reads the snapshot, so the employee is on the copy too, even after a rename.
        $employee->update(['first_name' => 'Renamed']);
        $copy = $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/sale-slips/{$sale['slip']['id']}/reprints", [], $this->key())->assertStatus(201);
        $this->assertStringContainsString('Dina S Ramos', $copy->json('slip.render_html'));
    }
}
