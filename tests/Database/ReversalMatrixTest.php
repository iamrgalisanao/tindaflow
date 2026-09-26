<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\ElectronicJournalEntry;
use App\Models\Invoice;
use App\Models\InvoiceSeries;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\RefundSettlement;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Services\Shift\ShiftReadingAggregator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * The riskiest arithmetic in the system, proved against the real checkout, void and refund flows: a three-line basket of
 * mixed VAT classes with an order-level discount that leaves a rounding residual, paid by two methods.
 *
 * Invariants: #22 full reversal, #23/#26/#60 originals never touched, #17 no invoice number on reversal, #61/#77 a later
 * price or tax-class change never reaches a refund, #62/#63 refund from net_line_amount with a deterministic residual,
 * #66 discount eligibility, #71/#37 the cash effect of a split refund, #6/#72 atomicity.
 */
class ReversalMatrixTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    /**
     * The scenario maps only terminal 1 to a fiscal installation; the manager sells at terminal 2, so give it the same one.
     *
     * @param  array<string, mixed>  $w
     */
    private function managerCanSell(array $w): void
    {
        DB::table('terminal_fiscal_installations')->insert([
            'id' => (string) Str::uuid(), 'store_id' => $w['storeId'], 'terminal_id' => $w['t2']->id,
            'fiscal_installation_id' => DB::table('terminal_fiscal_installations')->where('terminal_id', $w['t1']->id)->value('fiscal_installation_id'),
            'effective_from' => now()->subYear(), 'effective_to' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Half-up to centavos, written independently of Money/RefundCalculator so the test does not just echo the code.
     */
    private function halfUp(string $amount): string
    {
        return bcadd(bcadd($amount, '0.005', 3), '0', 2);
    }

    /** The amount owed after $unitsSoFar of $quantity units of a line worth $net: DISC-005's cumulative rule. */
    private function owed(string $net, int $unitsSoFar, int $quantity): string
    {
        return $this->halfUp(bcdiv(bcmul($net, (string) $unitsSoFar, 6), (string) $quantity, 6));
    }

    /**
     * 3 x VATABLE 33.33 + 2 x VAT_EXEMPT 25.00 + 1 x ZERO_RATED 10.00 = 159.99, less a 10.00 order discount = 149.99,
     * rung by a manager (order discounts need DISCOUNT_OVERRIDE) and paid 100.00 cash + 49.99 GCash.
     *
     * @param  array<string, mixed>  $w
     * @return array{sale: array<string, mixed>, products: array<int, Product>}
     */
    private function ringTheBasket(array $w): array
    {
        $this->managerCanSell($w);
        $vatable = Product::factory()->create(['store_id' => $w['storeId'], 'selling_price' => '33.33', 'cost' => '20.00', 'tax_class' => 'VATABLE']);
        $exempt = Product::factory()->vatExempt()->create(['store_id' => $w['storeId'], 'selling_price' => '25.00', 'cost' => '15.00']);
        $zeroRated = Product::factory()->create(['store_id' => $w['storeId'], 'selling_price' => '10.00', 'cost' => '5.00', 'tax_class' => 'ZERO_RATED']);

        $response = $this->asUser($w['manager'], $w['enroll2'])->postJson('/api/v1/sales', [
            'items' => [
                ['product_id' => $vatable->id, 'quantity' => '3'],
                ['product_id' => $exempt->id, 'quantity' => '2'],
                ['product_id' => $zeroRated->id, 'quantity' => '1'],
            ],
            'order_level_discount_amount' => '10.00',
            'payments' => [['method' => 'CASH', 'amount' => '100.00'], ['method' => 'GCASH', 'amount' => '49.99']],
        ], $this->key());
        $response->assertStatus(201);

        return ['sale' => $response->json(), 'products' => [$vatable, $exempt, $zeroRated]];
    }

    /**
     * Everything a reversal must leave alone, as plain arrays so a difference shows up in the assertion.
     *
     * @return array<string, mixed>
     */
    private function originals(string $saleId): array
    {
        $sale = (array) DB::table('sales')->where('id', $saleId)->first();
        unset($sale['status'], $sale['updated_at']); // the one thing a void or refund legitimately changes

        return [
            'sale' => $sale,
            'items' => DB::table('sale_items')->where('sale_id', $saleId)->orderBy('line_number')->get()->map(fn ($row) => (array) $row)->all(),
            'payments' => DB::table('payments')->where('sale_id', $saleId)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'invoice' => DB::table('invoices')->where('sale_id', $saleId)->get()->map(fn ($row) => (array) $row)->all(),
            'series_counters' => DB::table('invoice_series')->orderBy('id')->pluck('current_number', 'id')->all(),
            'sale_movements' => DB::table('stock_movements')->where('movement_type', 'SALE')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        ];
    }

    // ---------------------------------------------------------------- #66 discount eligibility

    public function test_a_line_that_is_not_eligible_receives_no_share_of_an_order_discount(): void
    {
        $w = $this->world();
        $this->managerCanSell($w);

        $response = $this->asUser($w['manager'], $w['enroll2'])->postJson('/api/v1/sales', [
            'items' => [
                ['product_id' => $w['product']->id, 'quantity' => '1'],
                ['product_id' => $w['product2']->id, 'quantity' => '1', 'order_discount_eligible' => false],
            ],
            'order_level_discount_amount' => '20.00',
            'payments' => [['method' => 'CASH', 'amount' => '130.00']],
        ], $this->key());

        $response->assertStatus(201);
        $response->assertJson(['grand_total' => '130.00']);
        $items = SaleItem::where('sale_id', $response->json('id'))->orderBy('line_number')->get();
        $this->assertTrue($items[0]->order_discount_eligible);
        $this->assertSame('20.00', $items[0]->allocated_order_discount_amount, 'the whole discount lands on the eligible line');
        $this->assertSame('80.00', $items[0]->net_line_amount);
        $this->assertFalse($items[1]->order_discount_eligible);
        $this->assertSame('0.00', $items[1]->allocated_order_discount_amount);
        $this->assertSame('50.00', $items[1]->net_line_amount, 'an ineligible line keeps its full price');
    }

    public function test_an_order_discount_with_no_eligible_line_is_refused_and_records_nothing(): void
    {
        $w = $this->world();
        $this->managerCanSell($w);

        $response = $this->asUser($w['manager'], $w['enroll2'])->postJson('/api/v1/sales', [
            'items' => [['product_id' => $w['product']->id, 'quantity' => '1', 'order_discount_eligible' => false]],
            'order_level_discount_amount' => '10.00',
            'payments' => [['method' => 'CASH', 'amount' => '90.00']],
        ], $this->key());

        $response->assertStatus(422);
        $response->assertJson(['error' => ['code' => 'VALIDATION_FAILED']]);
        $this->assertArrayHasKey('order_level_discount_amount', $response->json('error.details'));
        $this->assertSame(0, Sale::where('cashier_id', $w['manager']->id)->count());
        $this->assertSame(0, AuditEvent::where('event_type', 'DISCOUNT_APPLIED')->count());
    }

    // ---------------------------------------------------------------- refunds

    public function test_partial_refunds_of_a_mixed_basket_add_up_to_exactly_each_line_and_never_touch_the_original(): void
    {
        $w = $this->world();
        $w['managerShift']->update(['opening_cash' => '300.00']);
        ['sale' => $sale, 'products' => [$vatable, $exempt, $zeroRated]] = $this->ringTheBasket($w);
        $saleId = $sale['id'];

        $items = SaleItem::where('sale_id', $saleId)->orderBy('line_number')->get();
        $this->assertSame('149.99', $sale['grand_total']);
        $this->assertSame('10.00', bcadd((string) $items->sum('allocated_order_discount_amount'), '0', 2), 'DISC-001: the allocations add up to the order discount exactly');
        $this->assertSame('149.99', bcadd((string) $items->sum('net_line_amount'), '0', 2), 'DISC-006: the net lines add up to the grand total exactly');
        $this->assertEqualsCanonicalizing(['VATABLE', 'VAT_EXEMPT', 'ZERO_RATED'], $items->pluck('tax_classification_snapshot')->all());

        $before = $this->originals($saleId);

        // One unit per request, line by line: the vatable line (cash), the exempt line (GCash), the zero-rated line (cash).
        $plan = [[0, 3, 'CASH'], [1, 2, 'GCASH'], [2, 1, 'CASH']];
        $cashRefunded = '0.00';
        $steps = 0;
        foreach ($plan as [$index, $quantity, $method]) {
            $item = $items[$index];
            for ($unit = 1; $unit <= $quantity; $unit++) {
                $expected = bcsub($this->owed($item->net_line_amount, $unit, $quantity), $this->owed($item->net_line_amount, $unit - 1, $quantity), 2);

                $response = $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$saleId}/refunds", [
                    'items' => [['sale_item_id' => $item->id, 'quantity' => '1', 'disposition' => 'RETURN_TO_STOCK']],
                    'settlements' => [['payment_method' => $method, 'amount' => $expected]],
                    'reason' => 'Customer returned it',
                ], $this->key());

                $response->assertStatus(201);
                $response->assertJson(['refund_total' => $expected]);
                $cashRefunded = $method === 'CASH' ? bcadd($cashRefunded, $expected, 2) : $cashRefunded;
                $steps++;

                // #61/#77: after the first refund, the shop reprices the product and changes its tax class. The remaining
                // refunds must still come from the sale's own snapshot.
                if ($steps === 1) {
                    $vatable->update(['selling_price' => '999.00', 'tax_class' => 'VAT_EXEMPT']);
                }
            }
        }

        // Each line was refunded to exactly its net amount, in pieces that carried the rounding residual (DISC-005).
        foreach ($items as $item) {
            $this->assertSame($item->net_line_amount, bcadd((string) RefundItem::where('sale_item_id', $item->id)->sum('unit_refund_amount'), '0', 2), "line {$item->line_number} refunded to exactly its net amount");
            $this->assertSame($item->quantity, bcadd((string) RefundItem::where('sale_item_id', $item->id)->sum('quantity_returned'), '0', 3));
        }
        $this->assertSame('149.99', bcadd((string) Refund::where('sale_id', $saleId)->sum('refund_total'), '0', 2));
        $this->assertSame('149.99', bcadd((string) RefundSettlement::sum('amount'), '0', 2));
        $this->assertSame('REFUNDED', Sale::find($saleId)->status);

        // Nothing original was touched, no invoice number was allocated, and the original stock deduction stands (#17/#23/#26/#60).
        $this->assertSame($before, $this->originals($saleId));
        $this->assertSame(1, Invoice::count());

        // A further refund of anything is refused: the sale is exhausted.
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$saleId}/refunds", [
            'items' => [['sale_item_id' => $items[0]->id, 'quantity' => '1', 'disposition' => 'RETURN_TO_STOCK']],
            'settlements' => [['payment_method' => 'CASH', 'amount' => '0.01']],
            'reason' => 'Too many',
        ], $this->key())->assertStatus(422);

        // #71/#37: only the CASH settlements left the drawer; the GCash refund did not.
        $manager = app(ShiftReadingAggregator::class)->aggregate($w['managerShift']->fresh());
        $this->assertSame('100.00', $manager['cash_sales']);
        $this->assertSame(bcsub(bcadd('300.00', '100.00', 2), $cashRefunded, 2), $manager['expected_cash'], 'expected cash = opening + cash sales - cash refunds only');
        $this->assertSame('149.99', $manager['refunds_total'], 'refunds_total counts every method');
    }

    public function test_voiding_a_mixed_basket_reverses_every_line_in_full_and_touches_nothing_else(): void
    {
        $w = $this->world();
        ['sale' => $sale] = $this->ringTheBasket($w);
        $saleId = $sale['id'];
        $before = $this->originals($saleId);

        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$saleId}/void", ['reason' => 'Rung by mistake'], $this->key())
            ->assertStatus(201)->assertJson(['status' => 'VOIDED']);

        // #22: one compensating SALE_RETURN per line, for the full quantity of each.
        $returns = StockMovement::where('movement_type', 'SALE_RETURN')->get();
        $this->assertCount(3, $returns);
        $this->assertEqualsCanonicalizing(['3.000', '2.000', '1.000'], $returns->pluck('quantity')->all());
        $this->assertSame(0, Refund::count(), 'a void is not a refund');

        $this->assertSame($before, $this->originals($saleId), '#23: sale_items, payments, the invoice and the original SALE movements are untouched');
        $this->assertSame(1, Invoice::count(), '#17: a void allocates no invoice number');
        $this->assertSame('VOIDED', Sale::find($saleId)->status);
    }

    // ---------------------------------------------------------------- atomicity (#6, #72)

    /** @return array<string, class-string> the table each injected failure hits, for the assertion messages */
    private function checkoutStepsThatCanFail(): array
    {
        return [
            'the payment rows' => Payment::class,
            'the stock ledger' => StockMovement::class,
            'the invoice' => Invoice::class,
            'the audit event' => AuditEvent::class,
            'the electronic journal' => ElectronicJournalEntry::class,
        ];
    }

    public function test_a_failure_at_any_late_checkout_step_leaves_no_trace_and_the_same_key_then_succeeds_once(): void
    {
        $w = $this->world();
        $key = $this->key();
        $body = [
            'items' => [['product_id' => $w['product']->id, 'quantity' => '2']],
            'payments' => [['method' => 'CASH', 'amount' => '200.00']],
        ];
        $counterBefore = InvoiceSeries::orderBy('id')->pluck('current_number', 'id')->all();
        $salesBefore = Sale::count();

        foreach ($this->checkoutStepsThatCanFail() as $step => $model) {
            Event::listen('eloquent.creating: '.$model, fn () => throw new \RuntimeException("failure injected at {$step}"));
            $failed = $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', $body, $key);
            Event::forget('eloquent.creating: '.$model);

            $failed->assertStatus(500);
            $this->assertSame($salesBefore, Sale::count(), "a failure at {$step} must roll the sale back");
            $this->assertSame(0, SaleItem::count(), "sale items after a failure at {$step}");
            $this->assertSame(0, Payment::count(), "payments after a failure at {$step}");
            $this->assertSame(0, Invoice::count(), "invoices after a failure at {$step}");
            $this->assertSame(0, StockMovement::count(), "stock movements after a failure at {$step}");
            $this->assertSame(0, ElectronicJournalEntry::where('event_type', 'INVOICE')->count(), "journal after a failure at {$step}");
            $this->assertSame(0, AuditEvent::where('event_type', 'SALE_FINALIZED')->count(), "audit after a failure at {$step}");
            $this->assertSame($counterBefore, InvoiceSeries::orderBy('id')->pluck('current_number', 'id')->all(), "the invoice counter after a failure at {$step}");
        }

        $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', $body, $key)->assertStatus(201);
        $this->assertSame($salesBefore + 1, Sale::count());
        $this->assertSame(1, Invoice::count());
        $this->assertSame('000001', Invoice::first()->invoice_number, 'the failed attempts consumed no number');
    }

    public function test_a_failure_after_the_settlements_leaves_the_refund_unrecorded_and_the_same_key_then_succeeds_once(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $item = $sale['items'][0]['id'];
        $key = $this->key();
        $body = [
            'items' => [['sale_item_id' => $item, 'quantity' => '1', 'disposition' => 'RETURN_TO_STOCK']],
            'settlements' => [['payment_method' => 'CASH', 'amount' => '60.00'], ['payment_method' => 'GCASH', 'amount' => '40.00']],
            'reason' => 'Customer returned it',
        ];
        $stockBefore = StockMovement::count();
        $before = $this->originals($sale['id']);

        foreach ([RefundSettlement::class, StockMovement::class, ElectronicJournalEntry::class] as $model) {
            Event::listen('eloquent.creating: '.$model, fn () => throw new \RuntimeException('failure injected'));
            $failed = $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", $body, $key);
            Event::forget('eloquent.creating: '.$model);

            $failed->assertStatus(500);
            $this->assertSame(0, Refund::where('status', 'COMPLETED')->count(), "no completed refund after a failure at {$model}");
            $this->assertSame(0, RefundItem::count());
            $this->assertSame(0, RefundSettlement::count());
            $this->assertSame($stockBefore, StockMovement::count());
            $this->assertSame('COMPLETED', Sale::find($sale['id'])->status);
        }

        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/refunds", $body, $key)->assertStatus(201);
        $this->assertSame(1, Refund::where('status', 'COMPLETED')->count());
        $this->assertSame(2, RefundSettlement::count());
        $this->assertSame($before['sale_movements'], $this->originals($sale['id'])['sale_movements']);
    }
}
