<?php

namespace Tests\Database;

use App\Models\CashMovement;
use App\Models\Product;
use App\Models\Shift;
use App\Services\FiscalDay\FiscalDayReadingAggregator;
use App\Services\Shift\ShiftReadingAggregator;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * Change handed back to a customer is never part of what a sale collected. A payment row records what was TENDERED
 * (P200.00 on a P100.00 sale is stored as CASH 200.00), so the shift's expected drawer cash, the X- and Z-reading
 * payment breakdowns and the payment-method report must all count it net of change
 * (docs/06-backend/stage-9-shift-close-fiscal-day-close.md, "Change is not collected"). Sales are rung through the
 * real checkout, so the tendered amounts are exactly what the till sends.
 */
class CashChangeCollectionHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    /**
     * @param  list<array{0: string, 1: string}>  $payments  [method, amount]
     * @param  list<array{0: Product, 1: string}>  $lines
     * @return array<string, mixed>
     */
    private function sell(array $w, array $lines, array $payments): array
    {
        $response = $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines),
            'payments' => array_map(fn (array $p) => ['method' => $p[0], 'amount' => $p[1]], $payments),
        ], $this->key());
        $response->assertStatus(201);

        return $response->json();
    }

    /** The scenario's cashier shift, opened with a known drawer. */
    private function knownDrawer(array $w, string $openingCash): void
    {
        $w['cashierShift']->update(['opening_cash' => $openingCash]);
    }

    public function test_a_cash_sale_with_change_adds_only_the_sale_total_to_the_expected_drawer(): void
    {
        $w = $this->world();
        $this->knownDrawer($w, '500.00');

        // 100.00 sale, 200.00 note, 100.00 change.
        $sale = $this->sell($w, [[$w['product'], '1']], [['CASH', '200.00']]);
        $this->assertSame('100.00', $sale['change']);

        $reading = app(ShiftReadingAggregator::class)->aggregate($w['cashierShift']->fresh());

        $this->assertSame('100.00', $reading['cash_sales']);
        $this->assertSame('600.00', $reading['expected_cash']);
        $this->assertEquals(['CASH' => '100.00'], (array) $reading['payment_breakdown']);
    }

    public function test_an_honest_count_after_change_is_given_closes_with_zero_variance(): void
    {
        $w = $this->world();
        $this->knownDrawer($w, '500.00');

        $this->sell($w, [[$w['product'], '1']], [['CASH', '200.00']]);                                            // 100.00 collected
        $this->sell($w, [[$w['product'], '1'], [$w['product2'], '1']], [['GCASH', '50.00'], ['CASH', '120.00']]); // 150.00: 50 GCash + 100 cash
        $this->sell($w, [[$w['product2'], '1']], [['CASH', '50.00']]);                                            // 50.00 exact
        CashMovement::create(['shift_id' => $w['cashierShift']->id, 'type' => 'CASH_IN', 'amount' => '200.00', 'reason' => 'Change fund']);

        // Drawer: 500 opening + 200 cash-in + 100 + 100 + 50 = 950.00. Nothing else was ever put in it.
        $response = $this->asUser($w['cashier'], $w['enroll1'])
            ->postJson("/api/v1/shifts/{$w['cashierShift']->id}/close", ['declared_cash' => '950.00'], $this->key());

        $response->assertOk();
        $response->assertJson(['shift' => [
            'expected_cash' => '950.00', 'declared_cash' => '950.00', 'variance' => '0.00',
            'cash_sales' => '250.00', 'non_cash_sales' => '50.00', 'cash_in_total' => '200.00',
        ]]);
        $this->assertSame(['CASH' => '250.00', 'GCASH' => '50.00'], (array) $response->json('x_reading.totals_snapshot.payment_breakdown'));
    }

    public function test_a_refund_paid_in_cash_comes_out_of_the_drawer_it_is_taken_from_and_nothing_is_counted_twice(): void
    {
        $w = $this->world();
        $this->knownDrawer($w, '500.00');
        $w['managerShift']->update(['opening_cash' => '300.00']);

        // 200.00 sale, 300.00 note: 100.00 change.
        $sale = $this->sell($w, [[$w['product'], '2']], [['CASH', '300.00']]);

        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", [
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => '1', 'disposition' => 'RETURN_TO_STOCK']],
            'settlements' => [['payment_method' => 'CASH', 'amount' => '100.00']],
            'reason' => 'Customer returned one',
        ], $this->key())->assertStatus(201);

        $aggregator = app(ShiftReadingAggregator::class);

        // The sale left 200.00 in the cashier's drawer, not the 300.00 tendered.
        $cashier = $aggregator->aggregate($w['cashierShift']->fresh());
        $this->assertSame('200.00', $cashier['cash_sales']);
        $this->assertSame('700.00', $cashier['expected_cash']);

        // The refund is attributed to the manager's own shift and drawer: 300.00 - 100.00.
        $manager = $aggregator->aggregate(Shift::findOrFail($w['managerShift']->id));
        $this->assertSame('200.00', $manager['expected_cash']);
        $this->assertSame('0.00', $manager['cash_sales']);
    }

    public function test_the_z_reading_and_the_payment_method_report_count_collections_net_of_change(): void
    {
        $w = $this->world();

        $this->sell($w, [[$w['product'], '1']], [['CASH', '200.00']]);                                            // CASH 100
        $this->sell($w, [[$w['product'], '1'], [$w['product2'], '1']], [['GCASH', '50.00'], ['CASH', '120.00']]); // GCASH 50, CASH 100
        $this->sell($w, [[$w['product'], '1']], [['GCASH', '150.00']]);                                          // GCASH 100 (over-tendered)

        $z = app(FiscalDayReadingAggregator::class)->aggregate($w['cashierShift']->fiscalDay);
        $this->assertSame('350.00', $z['gross_sales']);
        $this->assertSame(['CASH' => '200.00', 'GCASH' => '150.00'], (array) $z['payment_breakdown']);

        $report = $this->asUser($w['admin'])->getJson('/api/v1/reports/sales-by-payment-method');
        $report->assertOk();
        $byMethod = collect($report->json('rows'))->keyBy('payment_method');
        $this->assertSame('200.00', $byMethod['CASH']['total_amount']);
        $this->assertSame(2, $byMethod['CASH']['transaction_count']);
        $this->assertSame('150.00', $byMethod['GCASH']['total_amount']);
        $this->assertSame(2, $byMethod['GCASH']['transaction_count']);
        $this->assertSame('350.00', $report->json('summary.total_amount'));
    }
}
