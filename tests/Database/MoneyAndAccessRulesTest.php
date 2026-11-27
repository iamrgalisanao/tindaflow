<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * Gaps the invariant audit left open on money and access, chosen by risk (docs/02-domain/invariant-test-coverage.md):
 * rules 21, 43, 52, 54, 70 and 74. Each test fails when the protection it names is taken away.
 */
class MoneyAndAccessRulesTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tindaflow.login_throttle.max_attempts' => 100000]);
    }

    /** @return array<string, string> everything a sale writes, as it stands now */
    private function saleRecords(): array
    {
        $records = [];
        foreach (['sales', 'sale_items', 'payments', 'invoices', 'invoice_series', 'stock_movements', 'electronic_journal_entries'] as $table) {
            $records[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $records;
    }

    /** Rings one unit of the product for cash and returns the response, whatever it is. */
    private function attemptSale(array $w, Product $product)
    {
        return $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => [['product_id' => $product->id, 'quantity' => '1']],
            'payments' => [['method' => 'CASH', 'amount' => bcadd((string) $product->selling_price, '0', 2)]],
        ], $this->key());
    }

    // ------------------------------------------------------------------ rules 54 and 74: the tax status is never guessed

    public function test_a_sale_with_no_tax_registration_is_refused_and_records_nothing(): void
    {
        $w = $this->world();
        $this->attemptSale($w, $w['product'])->assertStatus(201);
        $before = $this->saleRecords();
        DB::table('tax_registrations')->where('store_id', $w['storeId'])->delete();

        // A setup defect with no code of its own in the error catalog yet, so it answers 500 (TaxRegistrationResolutionException).
        $this->attemptSale($w, $w['product'])->assertStatus(500);

        $this->assertSame($before, $this->saleRecords(), 'no sale, and no invoice number used up');
    }

    public function test_a_vat_registered_store_refuses_to_sell_a_non_vat_item_and_records_nothing(): void
    {
        $w = $this->world();
        $item = Product::factory()->create(['store_id' => $w['storeId'], 'selling_price' => '40.00', 'tax_class' => 'NON_VAT']);
        $before = $this->saleRecords();

        $this->attemptSale($w, $item)->assertStatus(500);

        $this->assertSame($before, $this->saleRecords());
        $this->attemptSale($w, $w['product'])->assertStatus(201); // the till itself is fine
    }

    // ------------------------------------------------------------------ rule 21: one completed void per sale

    public function test_the_database_refuses_a_second_completed_void_of_a_sale(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Wrong item'], $this->key())->assertStatus(201);
        $void = (array) DB::table('voids')->where('sale_id', $sale['id'])->sole();

        try {
            DB::transaction(fn () => DB::table('voids')->insert(['id' => (string) Str::uuid()] + $void));
            $this->fail('A second completed void was accepted.');
        } catch (QueryException $refusal) {
            $this->assertStringContainsString('voids_one_voided_per_sale', $refusal->getMessage());
        }
    }

    // ------------------------------------------------------------------ rule 70: settlements are checked again at approval

    public function test_a_refund_request_whose_payments_no_longer_add_up_is_not_completed_on_approval(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);
        $requested = $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/sales/{$sale['id']}/refunds", [
            'items' => [['sale_item_id' => $sale['items'][0]['id'], 'quantity' => '1', 'disposition' => 'RETURN_TO_STOCK']],
            'settlements' => [['payment_method' => 'CASH', 'amount' => '100.00']],
            'reason' => 'Customer returned it',
        ], $this->key())->assertStatus(201)->assertJson(['status' => 'REQUESTED'])->json();

        // What was asked for is read back from the audit trail. Altered there, behind the application's back, to pay out too much.
        $event = AuditEvent::where('event_type', 'REFUND_REQUESTED')->where('entity_id', $requested['id'])->sole();
        $metadata = $event->after_metadata;
        $metadata['settlements'][0]['amount'] = '900.00';
        DB::table('audit_events')->where('id', $event->id)->update(['after_metadata' => json_encode($metadata)]);

        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/refunds/{$requested['id']}/approve", [], $this->key())
            ->assertStatus(422)->assertJsonPath('error.code', 'REFUND_SETTLEMENT_MISMATCH');

        $this->assertSame('REQUESTED', DB::table('refunds')->where('id', $requested['id'])->value('status'));
        $this->assertSame(0, DB::table('refund_settlements')->where('refund_id', $requested['id'])->count());
        $this->assertSame('COMPLETED', DB::table('sales')->where('id', $sale['id'])->value('status'));
    }

    // ------------------------------------------------------------------ rule 52: who may adjust stock, void and refund

    public function test_a_cashier_is_refused_every_stock_adjustment_operation(): void
    {
        $w = $this->world();
        $id = (string) Str::uuid();
        $operations = [
            ['get', 'stock-rules'], ['put', 'stock-rules'],
            ['post', 'inventory/receipts'], ['post', 'inventory/adjustments'],
            ['get', 'inventory/counts'], ['post', 'inventory/counts'], ['get', "inventory/counts/{$id}"],
            ['put', "inventory/counts/{$id}/lines"], ['delete', "inventory/counts/{$id}/lines/{$id}"],
            ['post', "inventory/counts/{$id}/cancel"], ['post', "inventory/counts/{$id}/post"],
            ['get', 'inventory/transfers'], ['get', "inventory/transfers/{$id}"], ['post', 'inventory/transfers'],
        ];
        $movements = DB::table('stock_movements')->count();

        foreach ($operations as [$verb, $path]) {
            $response = $this->asUser($w['cashier'], $w['enroll1'])->json(strtoupper($verb), "/api/v1/{$path}", [], $this->key());
            $this->assertSame(403, $response->status(), "{$verb} {$path}: {$response->getContent()}");
        }
        $this->assertSame($movements, DB::table('stock_movements')->count());
    }

    public function test_asking_for_a_void_or_a_refund_needs_the_capability_for_it(): void
    {
        // Every role holds SALE_VOID and SALE_REFUND today, so no user can show the gate by being refused: it is read from the route.
        foreach (['api/v1/sales/{saleId}/void' => 'can:SALE_VOID', 'api/v1/sales/{saleId}/refunds' => 'can:SALE_REFUND'] as $uri => $gate) {
            $route = collect(Route::getRoutes()->getRoutes())->first(fn ($route) => $route->uri() === $uri && in_array('POST', $route->methods(), true));
            $this->assertNotNull($route, $uri);
            $this->assertContains($gate, $route->gatherMiddleware(), $uri);
        }
    }

    // ------------------------------------------------------------------ rule 43: a reading changes nothing

    public function test_a_reading_taken_during_a_shift_resets_no_total_and_leaves_the_shift_open(): void
    {
        $w = $this->world();
        $this->ring($w);
        // Everything a reading could reset or use up. The journal is not among them: a reading rightly records itself there.
        $state = fn () => [
            (array) DB::table('shifts')->where('id', $w['cashierShift']->id)->first(),
            (array) DB::table('fiscal_days')->where('id', $w['cashierShift']->fiscal_day_id)->first(),
            array_diff_key($this->saleRecords(), ['electronic_journal_entries' => true]),
        ];
        $before = $state();
        $take = fn () => $this->asUser($w['manager'], $w['enroll1'])->postJson("/api/v1/shifts/{$w['cashierShift']->id}/x-readings", [], $this->key())->assertStatus(201)->json();

        $first = $take();
        $this->assertSame($before, $state());
        $second = $take();

        $this->assertSame($first['totals_snapshot'], $second['totals_snapshot'], 'the first reading used nothing up');
        $this->assertSame('200.00', $second['totals_snapshot']['cash_sales']);
        $this->assertFalse($second['is_closing_reading']);
        $this->assertSame('OPEN', DB::table('shifts')->where('id', $w['cashierShift']->id)->value('status'));
    }
}
