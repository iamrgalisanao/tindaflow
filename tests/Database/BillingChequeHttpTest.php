<?php

namespace Tests\Database;

use App\Models\Employee;
use App\Services\Shift\ShiftReadingAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * ADR-016 slice 4.2 (invariants.md BILL-002, BILL-006, BILL-007): cheques recorded against what is owed per cut-off period, through the
 * real endpoints on a real credit ledger. The race for the same period is BillingChequeConcurrencyTest; the hardened-role proof is in
 * AppendOnlyPrivilegesTest.
 */
class BillingChequeHttpTest extends PostgresSchemaTestCase
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

    // ------------------------------------------------------------------ owed and recording

    public function test_before_any_cheque_each_period_owes_its_billed_amount_and_equals_the_statement(): void
    {
        $w = $this->billed();

        $balance = $this->balance($w);

        $this->assertSame(['2026-10-16', '2026-10-01'], array_column($balance['periods'], 'period_start'), 'newest period first');
        $first = $this->period($balance, '2026-10-01');
        $this->assertSame(['500.00', '0.00', '500.00', 'OPEN', '2026-10-15', 'Oct 1-15, 2026'], [$first['billed_amount'], $first['received_amount'], $first['owed_amount'], $first['status'], $first['period_end'], $first['period_label']]);
        $this->assertSame(['800.00', '0.00', '800.00', '0.00'], [$balance['totals']['billed_amount'], $balance['totals']['received_amount'], $balance['totals']['owed_amount'], $balance['totals']['credit_amount']]);

        // BILL-001 / BILL-006: billed is the figure the billing statement of the same cut-off shows.
        $statement = $this->asUser($w['manager'])->getJson('/api/v1/billing/statement?cutoff_month=2026-10&cutoff_half=1')->assertOk()->json('data');
        $this->assertSame($statement['totals']['net_amount'], $first['billed_amount']);
        $second = $this->asUser($w['manager'])->getJson('/api/v1/billing/statement?cutoff_month=2026-10&cutoff_half=2')->assertOk()->json('data');
        $this->assertSame($second['totals']['net_amount'], $this->period($balance, '2026-10-16')['billed_amount']);
    }

    public function test_a_cheque_allocated_to_two_periods_lowers_what_each_owes(): void
    {
        $w = $this->billed();

        $response = $this->cheque($w)->assertStatus(201);

        $data = $response->json('data');
        $this->assertSame(['RECEIVED', '600.00', '0012345', 'BDO'], [$data['status'], $data['amount'], $data['cheque_number'], $data['bank']]);
        $this->assertSame([['period_start' => '2026-10-01', 'period_end' => '2026-10-15', 'amount' => '400.00'], ['period_start' => '2026-10-16', 'period_end' => '2026-10-31', 'amount' => '200.00']], $data['allocations']);
        $this->assertSame($w['manager']->id, $data['received_by']['id']);

        $balance = $this->balance($w);
        $first = $this->period($balance, '2026-10-01');
        $second = $this->period($balance, '2026-10-16');
        $this->assertSame(['400.00', '100.00', 'PARTLY_PAID'], [$first['received_amount'], $first['owed_amount'], $first['status']]);
        $this->assertSame(['200.00', '100.00', 'PARTLY_PAID'], [$second['received_amount'], $second['owed_amount'], $second['status']]);
        $this->assertSame([$data['id']], array_column($first['cheques'], 'cheque_id'));
        $this->assertSame(['800.00', '600.00', '200.00'], [$balance['totals']['billed_amount'], $balance['totals']['received_amount'], $balance['totals']['owed_amount']]);

        // The rest of both periods, with a second cheque, settles them.
        $this->cheque($w, ['cheque_number' => '0012346', 'amount' => '200.00', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '100.00'], ['period_start' => '2026-10-16', 'amount' => '100.00']]])->assertStatus(201);
        $balance = $this->balance($w);
        $this->assertSame(['PAID', 'PAID', '0.00'], [$this->period($balance, '2026-10-01')['status'], $this->period($balance, '2026-10-16')['status'], $balance['totals']['owed_amount']]);
        $this->assertSame(0, $balance['totals']['open_period_count']);
    }

    public function test_a_cheque_cannot_allocate_more_than_a_period_owes_and_nothing_is_written(): void
    {
        $w = $this->billed();

        $response = $this->cheque($w, ['amount' => '700.00', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '600.00'], ['period_start' => '2026-10-16', 'amount' => '100.00']]]);

        $response->assertStatus(409)->assertJsonPath('error.code', 'CHEQUE_ALLOCATION_EXCEEDS_OWED')
            ->assertJsonPath('error.details.period_start', '2026-10-01')->assertJsonPath('error.details.owed', '500.00')->assertJsonPath('error.details.requested', '600.00');
        $this->assertSame(0, DB::table('billing_cheques')->count());
        $this->assertSame(0, DB::table('billing_cheque_allocations')->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'BILLING_CHEQUE_RECORDED')->count(), 'a refused cheque leaves no audit event');

        // What an earlier cheque already paid is not owed any more.
        $this->cheque($w, ['amount' => '450.00', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '450.00']]])->assertStatus(201);
        $this->cheque($w, ['cheque_number' => '9', 'amount' => '60.00', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '60.00']]])
            ->assertStatus(409)->assertJsonPath('error.details.owed', '50.00');
        $this->assertSame(1, DB::table('billing_cheques')->count());
    }

    public function test_a_cheque_for_a_period_with_no_charges_is_refused(): void
    {
        $w = $this->billed();

        $this->cheque($w, ['amount' => '50.00', 'allocations' => [['period_start' => '2026-09-16', 'amount' => '50.00']]])
            ->assertStatus(409)->assertJsonPath('error.code', 'CHEQUE_ALLOCATION_EXCEEDS_OWED')->assertJsonPath('error.details.owed', '0.00');
        $this->assertSame(0, DB::table('billing_cheques')->count());
    }

    public function test_allocations_must_add_up_to_the_cheque_and_be_real_cut_off_periods(): void
    {
        $w = $this->billed();

        $short = $this->cheque($w, ['amount' => '600.00', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '400.00']]]);
        $short->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertStringContainsString('P400.00', $short->json('error.details.allocations.0'));

        $this->cheque($w, ['amount' => '100.00', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '400.00']]])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->cheque($w, ['amount' => '400.00', 'allocations' => [['period_start' => '2026-10-02', 'amount' => '400.00']]])->assertStatus(422);
        $this->cheque($w, ['amount' => '400.00', 'allocations' => [['period_start' => '2026-10-15', 'amount' => '400.00']]])->assertStatus(422);
        $this->cheque($w, ['amount' => '400.00', 'allocations' => [['period_start' => '2026-02-30', 'amount' => '400.00']]])->assertStatus(422);
        $this->cheque($w, ['amount' => '400.00', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '200.00'], ['period_start' => '2026-10-01', 'amount' => '200.00']]])->assertStatus(422);
        $this->cheque($w, ['amount' => '400.00', 'allocations' => []])->assertStatus(422);
        $this->cheque($w, ['amount' => '400.00', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '500.00'], ['period_start' => '2026-10-16', 'amount' => '-100.00']]])->assertStatus(422);
        $this->assertSame(0, DB::table('billing_cheques')->count());
    }

    public function test_amounts_and_dates_are_validated(): void
    {
        $w = $this->billed(); // "today" is 2026-11-02

        foreach ([['amount' => '0.00'], ['amount' => '-5.00'], ['amount' => '12.5'], ['amount' => 'abc'], ['amount' => '1000000000000.00']] as $bad) {
            $this->cheque($w, $bad + ['allocations' => [['period_start' => '2026-10-01', 'amount' => '100.00']]])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }
        $ok = ['amount' => '100.00', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '100.00']]];
        $this->cheque($w, ['received_on' => '2026-11-03'] + $ok)->assertStatus(422); // received in the future
        $this->cheque($w, ['received_on' => '2026-02-30'] + $ok)->assertStatus(422);
        $this->cheque($w, ['cheque_date' => '2024-01-01'] + $ok)->assertStatus(422); // a typo in the year
        $this->cheque($w, ['cheque_date' => 'tomorrow'] + $ok)->assertStatus(422);
        $this->cheque($w, ['cheque_number' => ''] + $ok)->assertStatus(422);
        $this->cheque($w, ['cheque_number' => '=1+1'] + $ok)->assertStatus(422);
        $this->cheque($w, ['bank' => ''] + $ok)->assertStatus(422);
        $this->assertSame(0, DB::table('billing_cheques')->count());

        // A post-dated cheque (up to a year ahead) and one received a few days ago are fine.
        $this->cheque($w, ['cheque_date' => '2026-12-15', 'cheque_number' => 'PDC-1'] + $ok)->assertStatus(201)->assertJsonPath('data.cheque_date', '2026-12-15');
        $this->cheque($w, ['received_on' => '2026-10-30', 'cheque_number' => 'OLD-1', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '100.00']]] + $ok)->assertStatus(201);
    }

    public function test_the_same_bank_and_number_is_refused_while_the_first_is_not_returned_but_can_be_represented_after_a_return(): void
    {
        $w = $this->billed();
        $first = $this->cheque($w, ['amount' => '100.00', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '100.00']]])->assertStatus(201)->json('data');

        // The same cheque entered twice (a double click), or with other spacing and letter case.
        $this->cheque($w, ['amount' => '100.00', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '100.00']]])
            ->assertStatus(409)->assertJsonPath('error.code', 'CHEQUE_DUPLICATE')->assertJsonPath('error.details.existing_cheque_id', $first['id']);
        $this->cheque($w, ['bank' => '  bdo ', 'amount' => '100.00', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '100.00']]])->assertStatus(409)->assertJsonPath('error.code', 'CHEQUE_DUPLICATE');
        // Another bank with the same number is another cheque.
        $this->cheque($w, ['bank' => 'BPI', 'amount' => '100.00', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '100.00']]])->assertStatus(201);
        $this->assertSame(2, DB::table('billing_cheques')->count());

        $this->asUser($w['manager'])->postJson("/api/v1/billing-cheques/{$first['id']}/return", ['returned_on' => '2026-11-02', 'reason' => 'Insufficient funds'])->assertOk();
        $again = $this->cheque($w, ['amount' => '100.00', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '100.00']]])->assertStatus(201)->json('data');

        $this->assertNotSame($first['id'], $again['id']);
        $this->assertSame(['RETURNED', 'RECEIVED'], DB::table('billing_cheques')->where('cheque_number', '0012345')->where('bank', 'BDO')->orderBy('created_at')->orderBy('status', 'desc')->pluck('status')->sort()->reverse()->values()->all());
    }

    // ------------------------------------------------------------------ return

    public function test_returning_a_cheque_reinstates_what_it_paid_and_it_cannot_be_returned_twice(): void
    {
        $w = $this->billed();
        $cheque = $this->cheque($w)->assertStatus(201)->json('data');
        $this->assertSame('200.00', $this->balance($w)['totals']['owed_amount']);

        $returned = $this->asUser($w['manager'])->postJson("/api/v1/billing-cheques/{$cheque['id']}/return", ['returned_on' => '2026-11-02', 'reason' => 'Insufficient funds'])->assertOk()->json('data');

        $this->assertSame(['RETURNED', '2026-11-02', 'Insufficient funds', $w['manager']->id], [$returned['status'], $returned['returned_on'], $returned['return_reason'], $returned['returned_by']['id']]);
        $this->assertCount(2, $returned['allocations'], 'the allocations stay on the record');
        $balance = $this->balance($w);
        $this->assertSame(['500.00', '300.00', '800.00', '0.00', '600.00'], [
            $this->period($balance, '2026-10-01')['owed_amount'], $this->period($balance, '2026-10-16')['owed_amount'],
            $balance['totals']['owed_amount'], $balance['totals']['received_amount'], $balance['totals']['returned_cheques_amount'],
        ]);
        $this->assertSame(['OPEN', 'OPEN'], [$this->period($balance, '2026-10-01')['status'], $this->period($balance, '2026-10-16')['status']]);
        $this->assertSame('RETURNED', $this->period($balance, '2026-10-01')['cheques'][0]['status']);
        $this->assertSame(2, DB::table('billing_cheque_allocations')->count());

        $this->asUser($w['manager'])->postJson("/api/v1/billing-cheques/{$cheque['id']}/return", ['returned_on' => '2026-11-02', 'reason' => 'Again'])
            ->assertStatus(409)->assertJsonPath('error.code', 'CHEQUE_ALREADY_RETURNED');
        $this->assertSame('Insufficient funds', DB::table('billing_cheques')->value('return_reason'), 'the first return is untouched');
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'BILLING_CHEQUE_RETURNED')->count());
    }

    public function test_a_return_needs_a_reason_and_a_real_date_that_is_not_in_the_future(): void
    {
        $w = $this->billed();
        $cheque = $this->cheque($w)->assertStatus(201)->json('data');
        $url = "/api/v1/billing-cheques/{$cheque['id']}/return";

        $this->asUser($w['manager'])->postJson($url, ['returned_on' => '2026-11-02'])->assertStatus(422);
        $this->asUser($w['manager'])->postJson($url, ['returned_on' => '2026-11-02', 'reason' => ' '])->assertStatus(422);
        $this->asUser($w['manager'])->postJson($url, ['returned_on' => '2026-11-09', 'reason' => 'Closed account'])->assertStatus(422);
        $this->asUser($w['manager'])->postJson($url, ['returned_on' => '2026-13-09', 'reason' => 'Closed account'])->assertStatus(422);
        $this->asUser($w['manager'])->postJson('/api/v1/billing-cheques/'.fake()->uuid().'/return', ['returned_on' => '2026-11-02', 'reason' => 'Closed account'])
            ->assertStatus(404)->assertJsonPath('error.code', 'CHEQUE_NOT_FOUND');
        $this->assertSame('RECEIVED', DB::table('billing_cheques')->value('status'));
    }

    public function test_a_refund_after_a_cheque_makes_the_period_overpaid_and_the_credit_is_shown(): void
    {
        $w = $this->billed();
        $this->at('2026-11-02 09:00:00');
        $this->cheque($w, ['amount' => '500.00', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '500.00']]])->assertStatus(201);
        $this->at('2026-11-02 12:00:00');
        $this->assertSame('PAID', $this->period($this->balance($w), '2026-10-01')['status']);

        // A refund of P100 against an October 5 sale, recorded in November, reverses it in OCTOBER (CREDIT-004).
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$w['firstSale']['id']}/refunds", [
            'items' => [['sale_item_id' => $w['firstSale']['items'][0]['id'], 'quantity' => '1', 'disposition' => 'RETURN_TO_STOCK']],
            'settlements' => [['payment_method' => 'CREDIT', 'amount' => '100.00']],
            'reason' => 'Customer returned it',
        ], $this->key())->assertStatus(201);

        $balance = $this->balance($w);
        $period = $this->period($balance, '2026-10-01');
        $this->assertSame(['400.00', '500.00', '-100.00', 'OVERPAID'], [$period['billed_amount'], $period['received_amount'], $period['owed_amount'], $period['status']]);
        $this->assertSame(['300.00', '100.00'], [$balance['totals']['owed_amount'], $balance['totals']['credit_amount']], 'the credit is not netted against what other periods owe');

        // As of a moment before the refund, the period was paid in full.
        $before = $this->balance($w, '?as_of='.urlencode('2026-11-02T10:00:00+08:00'));
        $this->assertSame(['500.00', '0.00', 'PAID'], [$this->period($before, '2026-10-01')['billed_amount'], $this->period($before, '2026-10-01')['owed_amount'], $this->period($before, '2026-10-01')['status']]);
        $this->assertSame('PAID', $this->period($before, '2026-10-01')['status']);
    }

    public function test_an_as_of_instant_ignores_cheques_recorded_and_returns_made_later(): void
    {
        $w = $this->billed();
        $this->at('2026-11-02 10:00:00');
        $cheque = $this->cheque($w)->assertStatus(201)->json('data');
        $this->at('2026-11-05 10:00:00');
        $this->asUser($w['manager'])->postJson("/api/v1/billing-cheques/{$cheque['id']}/return", ['returned_on' => '2026-11-05', 'reason' => 'Stop payment'])->assertOk();

        $beforeCheque = $this->balance($w, '?as_of='.urlencode('2026-11-02T09:00:00+08:00'));
        $whileHeld = $this->balance($w, '?as_of='.urlencode('2026-11-03T09:00:00+08:00'));
        $afterReturn = $this->balance($w);

        $this->assertSame('800.00', $beforeCheque['totals']['owed_amount']);
        $this->assertSame(['200.00', '600.00', '0.00'], [$whileHeld['totals']['owed_amount'], $whileHeld['totals']['received_amount'], $whileHeld['totals']['returned_cheques_amount']]);
        $this->assertSame(['800.00', '0.00', '600.00'], [$afterReturn['totals']['owed_amount'], $afterReturn['totals']['received_amount'], $afterReturn['totals']['returned_cheques_amount']]);
        $this->asUser($w['manager'])->getJson('/api/v1/billing/balance?as_of=nonsense')->assertStatus(422);
    }

    // ------------------------------------------------------------------ cash

    public function test_a_cheque_never_touches_a_shift_a_sale_cash_or_a_reading(): void
    {
        $w = $this->billed();
        $shifts = [$w['cashierShift'], $w['managerShift']];
        $before = array_map(fn ($shift) => app(ShiftReadingAggregator::class)->aggregate($shift->fresh()), $shifts);
        $counts = fn () => [DB::table('cash_movements')->count(), DB::table('sales')->count(), DB::table('payments')->count(), DB::table('refunds')->count(), DB::table('employee_credit_entries')->count(), DB::table('electronic_journal_entries')->count(), DB::table('x_readings')->count(), DB::table('z_readings')->count()];
        $countsBefore = $counts();

        $cheque = $this->cheque($w)->assertStatus(201)->json('data');
        $this->asUser($w['manager'])->postJson("/api/v1/billing-cheques/{$cheque['id']}/return", ['returned_on' => '2026-11-02', 'reason' => 'Insufficient funds'])->assertOk();
        $this->cheque($w, ['cheque_number' => '77'])->assertStatus(201);

        $this->assertSame($countsBefore, $counts(), 'no cash movement, sale, payment, refund, ledger entry, journal entry or reading was written');
        $this->assertEquals($before, array_map(fn ($shift) => app(ShiftReadingAggregator::class)->aggregate($shift->fresh()), $shifts), 'expected cash and every reading figure are unchanged');
        $this->assertSame(1, DB::table('billing_cheques')->where('status', 'RECEIVED')->count());
    }

    // ------------------------------------------------------------------ access, scope, audit

    public function test_only_billing_managers_can_use_cheques_and_a_cashier_gets_403(): void
    {
        $w = $this->billed();
        $cheque = $this->cheque($w)->assertStatus(201)->json('data');
        $responses = [
            $this->asUser($w['cashier'])->getJson('/api/v1/billing/balance'),
            $this->asUser($w['cashier'])->get('/api/v1/billing/balance', ['Accept' => 'text/csv']),
            $this->asUser($w['cashier'])->getJson('/api/v1/billing-cheques'),
            $this->asUser($w['cashier'])->getJson("/api/v1/billing-cheques/{$cheque['id']}"),
            $this->cheque($w, ['cheque_number' => '55'], 'cashier'),
            $this->asUser($w['cashier'])->postJson("/api/v1/billing-cheques/{$cheque['id']}/return", ['returned_on' => '2026-11-02', 'reason' => 'x y z']),
        ];
        foreach ($responses as $response) {
            $response->assertStatus(403);
        }
        $this->assertSame('RECEIVED', DB::table('billing_cheques')->value('status'));
        $this->assertSame(1, DB::table('billing_cheques')->count());

        $this->cheque($w, ['cheque_number' => '56', 'amount' => '10.00', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '10.00']]], 'admin')->assertStatus(201);
        $this->asUser($w['admin'])->getJson('/api/v1/billing-cheques')->assertOk();
    }

    public function test_another_stores_cheques_and_balances_never_appear(): void
    {
        $mine = $this->world();
        $theirs = $this->billed();
        $ana = $this->employee($mine);
        $this->at('2026-10-05 12:00:00');
        $this->creditSale($mine, $ana, '2');
        $this->at('2026-11-02 09:00:00');
        $theirCheque = $this->cheque($theirs)->assertStatus(201)->json('data');

        $this->assertSame('200.00', $this->balance($mine)['totals']['billed_amount']);
        $this->assertSame([], $this->asUser($mine['manager'])->getJson('/api/v1/billing-cheques')->json('data'));
        $this->asUser($mine['manager'])->getJson("/api/v1/billing-cheques/{$theirCheque['id']}")->assertStatus(404)->assertJsonPath('error.code', 'CHEQUE_NOT_FOUND');
        $this->asUser($mine['manager'])->postJson("/api/v1/billing-cheques/{$theirCheque['id']}/return", ['returned_on' => '2026-11-02', 'reason' => 'Not mine'])->assertStatus(404);
        $this->assertSame('RECEIVED', DB::table('billing_cheques')->where('id', $theirCheque['id'])->value('status'));

        // The same bank and number in another store is not a duplicate; and my period's owed does not include their cheque.
        $this->cheque($mine, ['amount' => '100.00', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '100.00']]])->assertStatus(201);
        $this->assertSame('100.00', $this->period($this->balance($mine), '2026-10-01')['owed_amount']);
    }

    public function test_recording_and_returning_write_audit_events_and_a_failed_attempt_writes_none(): void
    {
        $w = $this->billed();
        $this->cheque($w, ['amount' => '900.00', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '900.00']]])->assertStatus(409);
        $this->cheque($w, ['amount' => '900.00'])->assertStatus(422);
        $this->assertSame(0, DB::table('audit_events')->whereIn('event_type', ['BILLING_CHEQUE_RECORDED', 'BILLING_CHEQUE_RETURNED'])->count());

        $cheque = $this->cheque($w)->assertStatus(201)->json('data');
        $this->asUser($w['manager'])->postJson("/api/v1/billing-cheques/{$cheque['id']}/return", ['returned_on' => '2026-11-02', 'reason' => 'Account closed'])->assertOk();
        $this->asUser($w['manager'])->postJson("/api/v1/billing-cheques/{$cheque['id']}/return", ['returned_on' => '2026-11-02', 'reason' => 'Account closed'])->assertStatus(409);

        $recorded = DB::table('audit_events')->where('event_type', 'BILLING_CHEQUE_RECORDED')->get();
        $returned = DB::table('audit_events')->where('event_type', 'BILLING_CHEQUE_RETURNED')->get();
        $this->assertCount(1, $recorded);
        $this->assertCount(1, $returned);
        $this->assertSame([$w['manager']->id, 'billing_cheque', $cheque['id']], [$recorded[0]->actor_user_id, $recorded[0]->entity_type, $recorded[0]->entity_id]);
        $after = json_decode($recorded[0]->after_metadata, true);
        $this->assertSame(['0012345', 'BDO', '600.00', 'RECEIVED'], [$after['cheque_number'], $after['bank'], $after['amount'], $after['status']]);
        $this->assertEquals([['period_start' => '2026-10-01', 'amount' => '400.00'], ['period_start' => '2026-10-16', 'amount' => '200.00']], $after['allocations']);
        $this->assertSame('Account closed', $returned[0]->reason);
        $this->assertSame(['status' => 'RECEIVED'], json_decode($returned[0]->before_metadata, true));
        $this->assertSame('RETURNED', json_decode($returned[0]->after_metadata, true)['status']);
    }

    // ------------------------------------------------------------------ list, get, export

    public function test_the_list_filters_by_status_and_the_csv_exports_are_audited_and_formula_safe(): void
    {
        $w = $this->billed();
        $a = $this->cheque($w, ['bank' => '=cmd|calc', 'notes' => '+risky', 'amount' => '100.00', 'allocations' => [['period_start' => '2026-10-01', 'amount' => '100.00']]])->assertStatus(201)->json('data');
        $this->cheque($w, ['cheque_number' => '2', 'amount' => '50.00', 'allocations' => [['period_start' => '2026-10-16', 'amount' => '50.00']]])->assertStatus(201);
        $this->asUser($w['manager'])->postJson("/api/v1/billing-cheques/{$a['id']}/return", ['returned_on' => '2026-11-02', 'reason' => 'Insufficient funds'])->assertOk();

        $this->assertCount(2, $this->asUser($w['manager'])->getJson('/api/v1/billing-cheques')->json('data'));
        $this->assertSame([$a['id']], array_column($this->asUser($w['manager'])->getJson('/api/v1/billing-cheques?status=RETURNED')->json('data'), 'id'));
        $this->asUser($w['manager'])->getJson('/api/v1/billing-cheques?status=BOGUS')->assertStatus(422);
        $this->asUser($w['manager'])->getJson("/api/v1/billing-cheques/{$a['id']}")->assertOk()->assertJsonPath('data.status', 'RETURNED');

        $csv = $this->asUser($w['manager'])->get('/api/v1/billing-cheques', ['Accept' => 'text/csv'])->assertOk();
        $this->assertStringStartsWith('text/csv', $csv->headers->get('Content-Type'));
        $this->assertStringContainsString('cheque_number,bank,cheque_date', $csv->getContent());
        $this->assertStringContainsString("'=cmd|calc", $csv->getContent());
        $this->assertStringContainsString("'+risky", $csv->getContent());
        $this->assertStringContainsString('Insufficient funds', $csv->getContent());

        $balanceCsv = $this->asUser($w['manager'])->get('/api/v1/billing/balance', ['Accept' => 'text/csv'])->assertOk()->getContent();
        $this->assertStringContainsString('PERIOD,2026-10-01,2026-10-15,500.00,0.00,500.00,OPEN', $balanceCsv);
        $this->assertStringContainsString('TOTAL,,,800.00,50.00,750.00', $balanceCsv);

        $exports = DB::table('audit_events')->where('event_type', 'BILLING_EXPORTED')->get()->map(fn ($e) => json_decode($e->after_metadata, true)['report'])->sort()->values()->all();
        $this->assertSame(['balance', 'cheques'], $exports);
    }
}
