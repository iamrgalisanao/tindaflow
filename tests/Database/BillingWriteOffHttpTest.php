<?php

namespace Tests\Database;

use App\Models\Employee;
use App\Services\Shift\ShiftReadingAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * ADR-016 slice 4.3 (invariants.md BILL-008, BILL-009): writing off an unpaid amount of a cut-off period and cancelling a write-off,
 * through the real endpoints on a real credit ledger. The race with a cheque is BillingWriteOffConcurrencyTest; the hardened-role proof is
 * in AppendOnlyPrivilegesTest.
 */
class BillingWriteOffHttpTest extends PostgresSchemaTestCase
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
    private function employee(array $w): Employee
    {
        return Employee::factory()->create(['store_id' => $w['storeId']]);
    }

    /** One credit sale of $quantity of the P100 product. @param  array<string, mixed>  $w @return array<string, mixed> */
    private function creditSale(array $w, Employee $employee, string $quantity): array
    {
        return $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => [['product_id' => $w['product']->id, 'quantity' => $quantity]],
            'payments' => [['method' => 'CREDIT', 'amount' => bcmul('100.00', $quantity, 2)]],
            'employee_id' => $employee->id,
        ], $this->key())->assertStatus(201)->json();
    }

    /**
     * A world with P500.00 billed for Oct 1-15 and P300.00 for Oct 16-31, 2026.
     *
     * @return array<string, mixed>
     */
    private function billed(): array
    {
        $w = $this->world();
        $ana = $this->employee($w);
        $this->at('2026-10-05 12:00:00');
        $first = $this->creditSale($w, $ana, '5');
        $this->at('2026-10-20 12:00:00');
        $second = $this->creditSale($w, $ana, '3');
        $this->at('2026-11-02 09:00:00');

        return $w + ['ana' => $ana, 'firstSale' => $first, 'secondSale' => $second];
    }

    /**
     * @param  array<string, mixed>  $w
     * @param  array<string, mixed>  $overrides
     */
    private function cheque(array $w, array $overrides = [], string $as = 'manager'): TestResponse
    {
        return $this->asUser($w[$as])->postJson('/api/v1/billing-cheques', $overrides + [
            'cheque_number' => '0012345',
            'bank' => 'BDO',
            'cheque_date' => '2026-11-02',
            'amount' => '600.00',
            'received_on' => '2026-11-02',
            'allocations' => [['period_start' => '2026-10-01', 'amount' => '400.00'], ['period_start' => '2026-10-16', 'amount' => '200.00']],
        ]);
    }

    /** @param  array<string, mixed>  $w @return array<string, mixed> */
    private function balance(array $w, string $query = '', string $as = 'manager'): array
    {
        return $this->asUser($w[$as])->getJson('/api/v1/billing/balance'.$query)->assertOk()->json('data');
    }

    /** @param  array<string, mixed>  $balance @return array<string, mixed> */
    private function period(array $balance, string $start): array
    {
        foreach ($balance['periods'] as $period) {
            if ($period['period_start'] === $start) {
                return $period;
            }
        }
        $this->fail("No period {$start} in the balance");
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  array<string, mixed>  $w
     * @param  array<string, mixed>  $overrides
     */
    private function writeOff(array $w, array $overrides = [], string $as = 'manager'): TestResponse
    {
        return $this->asUser($w[$as])->postJson('/api/v1/billing-write-offs', $overrides + [
            'period_start' => '2026-10-01', 'amount' => '200.00', 'reason' => 'TSC will not pay the bounced cheque',
        ]);
    }

    /** @param  array<string, mixed>  $w */
    private function reverse(array $w, string $id, string $reason = 'Written off by mistake', string $as = 'manager'): TestResponse
    {
        return $this->asUser($w[$as])->postJson("/api/v1/billing-write-offs/{$id}/reverse", ['reason' => $reason]);
    }

    /** A P500.00 cheque for Oct 1-15, returned, so the whole P500.00 is owed again. @param  array<string, mixed>  $w @return array<string, mixed> */
    private function bouncedCheque(array $w, string $number = '0012345'): array
    {
        $cheque = $this->cheque($w, ['cheque_number' => $number, 'amount' => '500.00', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '500.00']]])->assertStatus(201)->json('data');
        $this->asUser($w['manager'])->postJson("/api/v1/billing-cheques/{$cheque['id']}/return", ['returned_on' => '2026-11-02', 'reason' => 'Insufficient funds'])->assertOk();

        return $cheque;
    }

    /** @return list<int> counts that a write-off must never change */
    private function untouched(): array
    {
        return [DB::table('cash_movements')->count(), DB::table('sales')->count(), DB::table('payments')->count(), DB::table('refunds')->count(), DB::table('employee_credit_entries')->count(), DB::table('electronic_journal_entries')->count(), DB::table('x_readings')->count(), DB::table('z_readings')->count(), DB::table('billing_cheques')->count(), DB::table('billing_cheque_allocations')->count(), DB::table('billing_runs')->count()];
    }

    // ------------------------------------------------------------------ writing off

    public function test_writing_off_part_of_a_period_lowers_what_it_owes(): void
    {
        $w = $this->billed();

        $response = $this->writeOff($w)->assertStatus(201);

        $data = $response->json('data');
        $this->assertSame(['WRITE_OFF', '2026-10-01', '2026-10-15', '200.00', 'ACTIVE', 'Oct 1-15, 2026'], [$data['kind'], $data['period_start'], $data['period_end'], $data['amount'], $data['status'], $data['period_label']]);
        $this->assertSame([$w['manager']->id, 'TSC will not pay the bounced cheque', null, null], [$data['written_by']['id'], $data['reason'], $data['cheque'], $data['reversal']]);

        $balance = $this->balance($w);
        $first = $this->period($balance, '2026-10-01');
        $this->assertSame(['500.00', '0.00', '200.00', '300.00', 'OPEN', '0.00'], [$first['billed_amount'], $first['received_amount'], $first['written_off_amount'], $first['owed_amount'], $first['status'], $first['write_off_excess_amount']]);
        $this->assertSame(['800.00', '0.00', '200.00', '600.00', '0.00'], [$balance['totals']['billed_amount'], $balance['totals']['received_amount'], $balance['totals']['written_off_amount'], $balance['totals']['owed_amount'], $balance['totals']['credit_amount']]);
        $this->assertSame('0.00', $this->period($balance, '2026-10-16')['written_off_amount'], 'another period is untouched');
    }

    public function test_writing_off_everything_that_is_owed_marks_the_period_written_off_and_a_cheque_can_no_longer_pay_it(): void
    {
        $w = $this->billed();
        $this->cheque($w, ['amount' => '100.00', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '100.00']]])->assertStatus(201);

        $this->writeOff($w, ['amount' => '400.00'])->assertStatus(201);

        $period = $this->period($this->balance($w), '2026-10-01');
        $this->assertSame(['500.00', '100.00', '400.00', '0.00', 'WRITTEN_OFF'], [$period['billed_amount'], $period['received_amount'], $period['written_off_amount'], $period['owed_amount'], $period['status']]);
        $this->cheque($w, ['cheque_number' => '77', 'amount' => '1.00', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '1.00']]])->assertStatus(409)->assertJsonPath('error.code', 'CHEQUE_ALLOCATION_EXCEEDS_OWED');
        $this->writeOff($w, ['amount' => '0.01'])->assertStatus(409)->assertJsonPath('error.code', 'WRITE_OFF_EXCEEDS_OWED');
    }

    public function test_a_write_off_cannot_exceed_what_is_owed_and_nothing_is_written(): void
    {
        $w = $this->billed();
        $before = $this->untouched();

        $response = $this->writeOff($w, ['amount' => '500.01'])->assertStatus(409)->assertJsonPath('error.code', 'WRITE_OFF_EXCEEDS_OWED');
        $this->assertSame(['500.00', '500.01'], [$response->json('error.details.owed'), $response->json('error.details.requested')]);
        $this->writeOff($w, ['amount' => '500.00'])->assertStatus(201);
        $this->writeOff($w, ['amount' => '0.01'])->assertStatus(409)->assertJsonPath('error.code', 'WRITE_OFF_EXCEEDS_OWED');

        $this->assertSame(1, DB::table('billing_write_offs')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'BILLING_WRITE_OFF_RECORDED')->count(), 'refused attempts leave no audit event');
        $this->assertSame($before, $this->untouched());
    }

    public function test_nothing_can_be_written_off_where_nothing_is_owed_or_the_period_is_overpaid(): void
    {
        $w = $this->billed();
        // A period with no charges at all, and one paid in full.
        $this->writeOff($w, ['period_start' => '2026-09-16', 'amount' => '1.00'])->assertStatus(409)->assertJsonPath('error.code', 'WRITE_OFF_EXCEEDS_OWED');
        $this->at('2026-11-02 09:00:00');
        $this->cheque($w, ['amount' => '500.00', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '500.00']]])->assertStatus(201);
        $this->writeOff($w, ['amount' => '1.00'])->assertStatus(409)->assertJsonPath('error.code', 'WRITE_OFF_EXCEEDS_OWED');

        // A refund after the cheque makes the period OVERPAID (owed -100.00): there is nothing to write off, and the credit is untouched.
        $this->at('2026-11-02 12:00:00');
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$w['firstSale']['id']}/refunds", [
            'items' => [['sale_item_id' => $w['firstSale']['items'][0]['id'], 'quantity' => '1', 'disposition' => 'RETURN_TO_STOCK']],
            'settlements' => [['payment_method' => 'CREDIT', 'amount' => '100.00']],
            'reason' => 'Customer returned it',
        ], $this->key())->assertStatus(201);
        $this->writeOff($w, ['amount' => '1.00'])->assertStatus(409);
        $period = $this->period($this->balance($w), '2026-10-01');
        $this->assertSame(['-100.00', 'OVERPAID', '0.00'], [$period['owed_amount'], $period['status'], $period['written_off_amount']]);
        $this->assertSame(0, DB::table('billing_write_offs')->count());
    }

    public function test_the_amount_period_and_reason_are_validated(): void
    {
        $w = $this->billed();

        $this->writeOff($w, ['reason' => ''])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['reason']]]);
        $this->writeOff($w, ['reason' => '  '])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['reason']]]);
        $this->writeOff($w, ['reason' => 'ab'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['reason']]]);
        $this->writeOff($w, ['reason' => str_repeat('x', 256)])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['reason']]]);
        $this->asUser($w['manager'])->postJson('/api/v1/billing-write-offs', ['period_start' => '2026-10-01', 'amount' => '10.00'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['reason']]]);
        $this->writeOff($w, ['amount' => '0.00'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['amount']]]);
        $this->writeOff($w, ['amount' => '-5.00'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['amount']]]);
        $this->writeOff($w, ['amount' => '12.5'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['amount']]]);
        $this->writeOff($w, ['period_start' => '2026-10-02'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['period_start']]]);
        $this->writeOff($w, ['period_start' => '2026-13-01'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['period_start']]]);
        $this->writeOff($w, ['cheque_id' => 'not-a-uuid'])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['cheque_id']]]);
        $this->assertSame(0, DB::table('billing_write_offs')->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'BILLING_WRITE_OFF_RECORDED')->count());
    }

    // ------------------------------------------------------------------ the cheque link

    public function test_a_write_off_can_be_linked_to_the_returned_cheque_it_relates_to(): void
    {
        $w = $this->billed();
        $cheque = $this->bouncedCheque($w);
        $this->assertSame('500.00', $this->period($this->balance($w), '2026-10-01')['owed_amount'], 'the returned cheque put the amount back');

        $data = $this->writeOff($w, ['amount' => '500.00', 'cheque_id' => $cheque['id']])->assertStatus(201)->json('data');

        $this->assertSame(['id' => $cheque['id'], 'cheque_number' => '0012345', 'bank' => 'BDO'], $data['cheque']);
        $period = $this->period($this->balance($w), '2026-10-01');
        $this->assertSame(['0.00', '500.00', 'WRITTEN_OFF', '0.00'], [$period['owed_amount'], $period['written_off_amount'], $period['status'], $period['received_amount']]);
        $this->assertSame('500.00', $this->balance($w)['totals']['returned_cheques_amount'] ?? '500.00');
        $list = $this->asUser($w['manager'])->getJson('/api/v1/billing-write-offs')->assertOk()->json('data');
        $this->assertSame('0012345', $list[0]['cheque']['cheque_number']);
    }

    public function test_the_linked_cheque_must_be_returned_belong_to_the_store_and_have_paid_that_period(): void
    {
        $w = $this->billed();
        $returned = $this->bouncedCheque($w, '555');
        $received = $this->cheque($w, ['amount' => '100.00', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '100.00']]])->assertStatus(201)->json('data');
        $this->writeOff($w, ['cheque_id' => $received['id']])->assertStatus(409)->assertJsonPath('error.code', 'WRITE_OFF_CHEQUE_INVALID')->assertJsonPath('error.details.problem', 'not_returned');

        $this->writeOff($w, ['period_start' => '2026-10-16', 'amount' => '50.00', 'cheque_id' => $returned['id']])->assertStatus(409)->assertJsonPath('error.details.problem', 'wrong_period');
        $this->writeOff($w, ['cheque_id' => fake()->uuid()])->assertStatus(409)->assertJsonPath('error.details.problem', 'not_found');

        $theirs = $this->billed();
        $theirCheque = $this->cheque($theirs, ['amount' => '500.00', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '500.00']]])->assertStatus(201)->json('data');
        $this->asUser($theirs['manager'])->postJson("/api/v1/billing-cheques/{$theirCheque['id']}/return", ['returned_on' => '2026-11-02', 'reason' => 'Closed'])->assertOk();
        $this->writeOff($w, ['cheque_id' => $theirCheque['id']])->assertStatus(409)->assertJsonPath('error.details.problem', 'not_found');

        $this->assertSame(0, DB::table('billing_write_offs')->count());
    }

    // ------------------------------------------------------------------ reversal

    public function test_reversing_a_write_off_restores_what_is_owed_and_it_cannot_be_reversed_twice(): void
    {
        $w = $this->billed();
        $row = $this->writeOff($w)->assertStatus(201)->json('data');
        $this->assertSame('300.00', $this->period($this->balance($w), '2026-10-01')['owed_amount']);

        $this->at('2026-11-03 09:00:00');
        $reversal = $this->reverse($w, $row['id'], 'Written off by mistake', 'admin')->assertStatus(201)->json('data');

        $this->assertSame(['REVERSAL', '200.00', '2026-10-01', $row['id'], null, $w['admin']->id, 'Written off by mistake'], [$reversal['kind'], $reversal['amount'], $reversal['period_start'], $reversal['reverses_write_off_id'], $reversal['status'], $reversal['written_by']['id'], $reversal['reason']]);
        $period = $this->period($this->balance($w), '2026-10-01');
        $this->assertSame(['500.00', '0.00', 'OPEN'], [$period['owed_amount'], $period['written_off_amount'], $period['status']]);

        $this->reverse($w, $row['id'])->assertStatus(409)->assertJsonPath('error.code', 'WRITE_OFF_ALREADY_REVERSED');
        $this->reverse($w, $reversal['id'])->assertStatus(409)->assertJsonPath('error.code', 'WRITE_OFF_NOT_REVERSIBLE');
        $this->assertSame(2, DB::table('billing_write_offs')->count());

        // The list shows both rows, the write-off now REVERSED with who cancelled it and why.
        $list = collect($this->asUser($w['manager'])->getJson('/api/v1/billing-write-offs')->assertOk()->json('data'));
        $original = $list->firstWhere('id', $row['id']);
        $this->assertSame(['REVERSED', 'Written off by mistake', $w['admin']->id], [$original['status'], $original['reversal']['reason'], $original['reversal']['reversed_by']['id']]);
        $this->assertSame($reversal['id'], $list->first()['id'], 'newest first');

        // After a reversal the amount can be written off again, as a new write-off.
        $this->writeOff($w)->assertStatus(201);
        $this->assertSame('300.00', $this->period($this->balance($w), '2026-10-01')['owed_amount']);
    }

    public function test_a_reversal_needs_a_reason_and_a_real_write_off_of_this_store(): void
    {
        $w = $this->billed();
        $row = $this->writeOff($w)->assertStatus(201)->json('data');

        $this->asUser($w['manager'])->postJson("/api/v1/billing-write-offs/{$row['id']}/reverse", [])->assertStatus(422)->assertJsonStructure(['error' => ['details' => ['reason']]]);
        $this->reverse($w, $row['id'], 'x')->assertStatus(422);
        $this->reverse($w, fake()->uuid())->assertStatus(404)->assertJsonPath('error.code', 'WRITE_OFF_NOT_FOUND');
        $theirs = $this->billed();
        $this->reverse($theirs, $row['id'])->assertStatus(404)->assertJsonPath('error.code', 'WRITE_OFF_NOT_FOUND');
        $this->assertSame(1, DB::table('billing_write_offs')->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'BILLING_WRITE_OFF_REVERSED')->count());
    }

    /** The statement must fail with a database error; it runs in a savepoint so the test's transaction survives. */
    private function refusedByTheDatabase(callable $statement): void
    {
        try {
            DB::transaction($statement);
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('The database accepted a row it should have refused.');
    }

    public function test_the_database_itself_refuses_a_reversal_of_a_different_amount_or_a_second_reversal(): void
    {
        $w = $this->billed();
        $row = $this->writeOff($w)->assertStatus(201)->json('data');
        $base = ['id' => (string) Str::uuid(), 'store_id' => $w['storeId'], 'billing_party_id' => DB::table('billing_write_offs')->value('billing_party_id'), 'kind' => 'REVERSAL',
            'period_start' => '2026-10-01', 'period_end' => '2026-10-15', 'amount' => '200.00', 'reason' => 'x y z', 'reverses_write_off_id' => $row['id'], 'written_by' => $w['manager']->id];

        $this->refusedByTheDatabase(fn () => DB::table('billing_write_offs')->insert(['amount' => '150.00'] + $base));
        $this->refusedByTheDatabase(fn () => DB::table('billing_write_offs')->insert(['period_start' => '2026-10-16', 'period_end' => '2026-10-31'] + $base));
        $this->refusedByTheDatabase(fn () => DB::table('billing_write_offs')->insert(['kind' => 'WRITE_OFF'] + $base));
        $this->refusedByTheDatabase(fn () => DB::table('billing_write_offs')->insert(['reason' => '  '] + $base));
        $this->refusedByTheDatabase(fn () => DB::table('billing_write_offs')->insert(['amount' => '0.00'] + $base));
        DB::table('billing_write_offs')->insert($base);
        $this->refusedByTheDatabase(fn () => DB::table('billing_write_offs')->insert(['id' => (string) Str::uuid()] + $base));
    }

    // ------------------------------------------------------------------ interplay with cheques, refunds and as-of

    public function test_a_cheque_after_a_write_off_cannot_allocate_more_than_what_is_left_owed(): void
    {
        $w = $this->billed();
        $this->writeOff($w, ['amount' => '300.00'])->assertStatus(201);

        $this->cheque($w, ['amount' => '200.01', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '200.01']]])->assertStatus(409)->assertJsonPath('error.code', 'CHEQUE_ALLOCATION_EXCEEDS_OWED')->assertJsonPath('error.details.owed', '200.00');
        $this->cheque($w, ['amount' => '200.00', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '200.00']]])->assertStatus(201);

        $period = $this->period($this->balance($w), '2026-10-01');
        $this->assertSame(['500.00', '200.00', '300.00', '0.00', 'WRITTEN_OFF'], [$period['billed_amount'], $period['received_amount'], $period['written_off_amount'], $period['owed_amount'], $period['status']]);
    }

    public function test_a_refund_after_a_write_off_keeps_the_figures_coherent_and_flags_the_excess_instead_of_inventing_a_credit(): void
    {
        $w = $this->billed();
        $row = $this->writeOff($w, ['amount' => '400.00'])->assertStatus(201)->json('data');
        $this->assertSame('100.00', $this->period($this->balance($w), '2026-10-01')['owed_amount']);

        // A refund of P200 on an Oct 5 sale lowers billed to P300: owed would be 300 - 400 = -100, but TSC is owed nothing, so it is NOT a credit.
        $this->at('2026-11-02 12:00:00');
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$w['firstSale']['id']}/refunds", [
            'items' => [['sale_item_id' => $w['firstSale']['items'][0]['id'], 'quantity' => '2', 'disposition' => 'RETURN_TO_STOCK']],
            'settlements' => [['payment_method' => 'CREDIT', 'amount' => '200.00']],
            'reason' => 'Customer returned it',
        ], $this->key())->assertStatus(201);

        $balance = $this->balance($w);
        $period = $this->period($balance, '2026-10-01');
        $this->assertSame(['300.00', '400.00', '-100.00', '100.00', '0.00', 'OVER_WRITTEN_OFF'], [$period['billed_amount'], $period['written_off_amount'], $period['owed_amount'], $period['write_off_excess_amount'], $period['credit_amount'], $period['status']]);
        $this->assertSame(['300.00', '0.00', '100.00', '400.00'], [$balance['totals']['owed_amount'], $balance['totals']['credit_amount'], $balance['totals']['write_off_excess_amount'], $balance['totals']['written_off_amount']], 'only the other period still owes; no credit to TSC');
        $this->writeOff($w, ['amount' => '1.00'])->assertStatus(409);

        // Reversing the write-off puts it right.
        $this->reverse($w, $row['id'])->assertStatus(201);
        $period = $this->period($this->balance($w), '2026-10-01');
        $this->assertSame(['300.00', '0.00', '0.00', 'OPEN'], [$period['owed_amount'], $period['write_off_excess_amount'], $period['written_off_amount'], $period['status']]);
    }

    public function test_a_real_overpayment_next_to_a_write_off_is_still_a_credit(): void
    {
        $w = $this->billed();
        $this->writeOff($w, ['amount' => '100.00'])->assertStatus(201);   // owed 400
        $this->cheque($w, ['amount' => '400.00', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '400.00']]])->assertStatus(201); // owed 0
        $this->at('2026-11-02 12:00:00');
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$w['firstSale']['id']}/refunds", [
            'items' => [['sale_item_id' => $w['firstSale']['items'][0]['id'], 'quantity' => '5', 'disposition' => 'RETURN_TO_STOCK']],
            'settlements' => [['payment_method' => 'CREDIT', 'amount' => '500.00']],
            'reason' => 'Whole order returned',
        ], $this->key())->assertStatus(201);

        // billed 0, received 400, written off 100: owed -500; the write-off is wholly excess (100) and the real credit is the 400 TSC paid.
        $period = $this->period($this->balance($w), '2026-10-01');
        $this->assertSame(['0.00', '-500.00', '100.00', '400.00', 'OVERPAID'], [$period['billed_amount'], $period['owed_amount'], $period['write_off_excess_amount'], $period['credit_amount'], $period['status']]);
    }

    public function test_an_as_of_instant_ignores_write_offs_and_reversals_made_later(): void
    {
        $w = $this->billed();
        $this->at('2026-11-03 10:00:00');
        $row = $this->writeOff($w)->assertStatus(201)->json('data');
        $this->at('2026-11-06 10:00:00');
        $this->reverse($w, $row['id'])->assertStatus(201);

        $before = $this->balance($w, '?as_of='.urlencode('2026-11-03T09:00:00+08:00'));
        $while = $this->balance($w, '?as_of='.urlencode('2026-11-04T09:00:00+08:00'));
        $after = $this->balance($w);

        $this->assertSame(['800.00', '0.00'], [$before['totals']['owed_amount'], $before['totals']['written_off_amount']]);
        $this->assertSame(['600.00', '200.00'], [$while['totals']['owed_amount'], $while['totals']['written_off_amount']]);
        $this->assertSame(['800.00', '0.00'], [$after['totals']['owed_amount'], $after['totals']['written_off_amount']]);
    }

    // ------------------------------------------------------------------ what it must not touch

    public function test_a_write_off_never_changes_a_billing_statement_a_shift_cash_or_a_reading(): void
    {
        $w = $this->billed();
        $statementBefore = $this->asUser($w['manager'])->getJson('/api/v1/billing/statement?cutoff_month=2026-10&cutoff_half=1')->assertOk()->json('data.totals');
        $shifts = [$w['cashierShift'], $w['managerShift']];
        $readings = array_map(fn ($shift) => app(ShiftReadingAggregator::class)->aggregate($shift->fresh()), $shifts);
        $countsBefore = array_slice($this->untouched(), 0, 11);

        $row = $this->writeOff($w)->assertStatus(201)->json('data');
        $this->reverse($w, $row['id'])->assertStatus(201);
        $this->writeOff($w, ['amount' => '120.00'])->assertStatus(201);

        $this->assertSame($countsBefore, array_slice($this->untouched(), 0, 11), 'no cash movement, sale, payment, refund, ledger entry, journal entry, reading, cheque or run was written');
        $this->assertEquals($readings, array_map(fn ($shift) => app(ShiftReadingAggregator::class)->aggregate($shift->fresh()), $shifts), 'expected cash and every reading are unchanged');
        $this->assertEquals($statementBefore, $this->asUser($w['manager'])->getJson('/api/v1/billing/statement?cutoff_month=2026-10&cutoff_half=1')->assertOk()->json('data.totals'), 'a statement is of charges and ignores write-offs');
        // Billed in the balance is still the statement's figure.
        $this->assertSame($statementBefore['net_amount'], $this->period($this->balance($w), '2026-10-01')['billed_amount']);
    }

    // ------------------------------------------------------------------ access, scope, audit

    public function test_only_billing_managers_can_write_off_and_a_cashier_gets_403(): void
    {
        $w = $this->billed();
        $row = $this->writeOff($w)->assertStatus(201)->json('data');

        $this->writeOff($w, [], 'cashier')->assertStatus(403);
        $this->reverse($w, $row['id'], 'Written off by mistake', 'cashier')->assertStatus(403);
        $this->asUser($w['cashier'])->getJson('/api/v1/billing-write-offs')->assertStatus(403);
        $this->assertSame(1, DB::table('billing_write_offs')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'BILLING_WRITE_OFF_RECORDED')->count());

        $this->writeOff($w, ['amount' => '10.00'], 'admin')->assertStatus(201);
        $this->asUser($w['admin'])->getJson('/api/v1/billing-write-offs')->assertOk();
    }

    public function test_another_stores_write_offs_and_balances_never_appear(): void
    {
        $mine = $this->world();
        $theirs = $this->billed();
        $ana = $this->employee($mine);
        $this->at('2026-10-05 12:00:00');
        $this->creditSale($mine, $ana, '2');
        $this->at('2026-11-02 09:00:00');
        $this->writeOff($theirs)->assertStatus(201);

        $this->assertSame([], $this->asUser($mine['manager'])->getJson('/api/v1/billing-write-offs')->json('data'));
        $this->assertSame(['200.00', '0.00'], [$this->period($this->balance($mine), '2026-10-01')['billed_amount'], $this->period($this->balance($mine), '2026-10-01')['written_off_amount']]);
        // My period owes P200.00 only: their write-off does not count and a bigger one of mine is refused.
        $this->writeOff($mine, ['amount' => '250.00'])->assertStatus(409);
        $this->writeOff($mine, ['amount' => '200.00'])->assertStatus(201);
    }

    public function test_writing_off_and_reversing_write_audit_events_with_the_actor_and_a_refusal_writes_none(): void
    {
        $w = $this->billed();
        $cheque = $this->bouncedCheque($w);
        $this->writeOff($w, ['amount' => '900.00'])->assertStatus(409);
        $this->writeOff($w, ['reason' => ''])->assertStatus(422);
        $this->reverse($w, fake()->uuid())->assertStatus(404);
        $this->assertSame(0, DB::table('audit_events')->whereIn('event_type', ['BILLING_WRITE_OFF_RECORDED', 'BILLING_WRITE_OFF_REVERSED'])->count());

        $row = $this->writeOff($w, ['amount' => '350.00', 'cheque_id' => $cheque['id']])->assertStatus(201)->json('data');
        $reversal = $this->reverse($w, $row['id'], 'Entered the wrong period', 'admin')->assertStatus(201)->json('data');
        $this->reverse($w, $row['id'])->assertStatus(409);

        $recorded = DB::table('audit_events')->where('event_type', 'BILLING_WRITE_OFF_RECORDED')->get();
        $reversed = DB::table('audit_events')->where('event_type', 'BILLING_WRITE_OFF_REVERSED')->get();
        $this->assertCount(1, $recorded);
        $this->assertCount(1, $reversed);
        $this->assertSame([$w['manager']->id, 'billing_write_off', $row['id'], 'TSC will not pay the bounced cheque'], [$recorded[0]->actor_user_id, $recorded[0]->entity_type, $recorded[0]->entity_id, $recorded[0]->reason]);
        $after = json_decode($recorded[0]->after_metadata, true);
        $this->assertSame(['2026-10-01', '350.00', $cheque['id'], '0012345', '500.00', '150.00'], [$after['period_start'], $after['amount'], $after['cheque_id'], $after['cheque_number'], $after['owed_before'], $after['owed_after']]);
        $this->assertSame([$w['admin']->id, $reversal['id'], 'Entered the wrong period'], [$reversed[0]->actor_user_id, $reversed[0]->entity_id, $reversed[0]->reason]);
        $this->assertSame($row['id'], json_decode($reversed[0]->before_metadata, true)['write_off_id']);
        $this->assertSame(['150.00', '500.00', '350.00'], [json_decode($reversed[0]->after_metadata, true)['owed_before'], json_decode($reversed[0]->after_metadata, true)['owed_after'], json_decode($reversed[0]->after_metadata, true)['amount']]);
    }

    // ------------------------------------------------------------------ idempotency

    public function test_a_retried_request_id_returns_the_same_write_off_and_writes_one_row(): void
    {
        $w = $this->billed();
        $id = (string) Str::uuid();

        $first = $this->writeOff($w, ['amount' => '100.00', 'request_id' => $id])->assertStatus(201)->json('data');
        $again = $this->writeOff($w, ['amount' => '100.00', 'request_id' => $id])->assertStatus(200)->json('data');

        $this->assertSame($first['id'], $again['id']);
        $this->assertSame(1, DB::table('billing_write_offs')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'BILLING_WRITE_OFF_RECORDED')->count());
        $this->assertSame('400.00', $this->period($this->balance($w), '2026-10-01')['owed_amount'], 'written off once, not twice');

        $this->writeOff($w, ['amount' => '150.00', 'request_id' => $id])->assertStatus(409)->assertJsonPath('error.code', 'WRITE_OFF_REQUEST_REUSED');
        $this->writeOff($w, ['amount' => '100.00', 'request_id' => (string) Str::uuid()])->assertStatus(201);
        $this->assertSame(2, DB::table('billing_write_offs')->count());
    }

    // ------------------------------------------------------------------ list and export

    public function test_the_list_is_paginated_newest_first_and_the_balance_csv_has_the_written_off_columns(): void
    {
        $w = $this->billed();
        $this->at('2026-11-03 09:00:00');
        $this->writeOff($w, ['amount' => '100.00'])->assertStatus(201);
        $this->at('2026-11-03 10:00:00');
        $this->writeOff($w, ['amount' => '50.00', 'period_start' => '2026-10-16', 'reason' => '=cmd|calc'])->assertStatus(201);

        $page = $this->asUser($w['manager'])->getJson('/api/v1/billing-write-offs?per_page=1&page=1')->assertOk();
        $this->assertSame(['50.00', 2, 2], [$page->json('data.0.amount'), $page->json('meta.total'), $page->json('meta.last_page')]);

        $csv = $this->asUser($w['manager'])->get('/api/v1/billing/balance', ['Accept' => 'text/csv'])->assertOk()->getContent();
        $lines = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
        $this->assertSame(['row_type', 'period_start', 'period_end', 'billed_amount', 'received_amount', 'owed_amount', 'status', 'written_off_amount', 'write_off_excess_amount'], $lines[0], 'earlier columns keep their order; new ones are appended');
        $rows = array_column($lines, null, 0);
        $this->assertSame(['PERIOD', '2026-10-01', '2026-10-15', '500.00', '0.00', '400.00', 'OPEN', '100.00', '0.00'], $lines[2]);
        $this->assertSame(['TOTAL', '', '', '800.00', '0.00', '650.00', '', '150.00', '0.00'], $rows['TOTAL']);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'BILLING_EXPORTED')->count());
        $this->assertSame('150.00', json_decode(DB::table('audit_events')->where('event_type', 'BILLING_EXPORTED')->value('after_metadata'), true)['written_off_amount']);
    }
}
