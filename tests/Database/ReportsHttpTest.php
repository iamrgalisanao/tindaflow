<?php

namespace Tests\Database;

use App\Models\Category;
use App\Models\FiscalDay;
use App\Models\InventoryLocation;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleVoid;
use App\Models\Shift;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\Terminal;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/** openapi.yaml Reports tag -- all 15 operations, REPORT_VIEW, session-only, no domain-specific failure mode. */
class ReportsHttpTest extends PostgresSchemaTestCase
{
    private function login(User $user): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']);
    }

    private function forwardSessionCookie(TestResponse $prior): static
    {
        $this->app['auth']->forgetGuards();

        $cookie = collect($prior->headers->getCookies())
            ->first(fn ($c) => $c->getName() === config('session.cookie'));

        return $this->withUnencryptedCookie(config('session.cookie'), $cookie?->getValue() ?? '')->withCredentials();
    }

    private function makeShift(Store $store, ?string $businessDate = null): Shift
    {
        $terminal = Terminal::factory()->create(['store_id' => $store->id]);
        $fiscalDay = FiscalDay::factory()->create(array_filter([
            'store_id' => $store->id,
            'terminal_id' => $terminal->id,
            'business_date' => $businessDate,
        ]));
        $cashier = User::factory()->create(['store_id' => $store->id]);

        return Shift::factory()->create([
            'terminal_id' => $terminal->id,
            'fiscal_day_id' => $fiscalDay->id,
            'cashier_id' => $cashier->id,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function makeSale(Shift $shift, array $overrides = []): Sale
    {
        return Sale::factory()->create(array_merge([
            'store_id' => $shift->fiscalDay->store_id,
            'terminal_id' => $shift->terminal_id,
            'fiscal_day_id' => $shift->fiscal_day_id,
            'shift_id' => $shift->id,
            'cashier_id' => $shift->cashier_id,
        ], $overrides));
    }

    public function test_a_cashier_cannot_view_reports(): void
    {
        $cashier = User::factory()->create();
        $login = $this->login($cashier);

        $response = $this->forwardSessionCookie($login)->getJson('/api/v1/reports/daily-sales-summary');

        $response->assertStatus(403);
        $response->assertJson(['error' => ['code' => 'AUTHORIZATION_DENIED']]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/v1/reports/daily-sales-summary');

        $response->assertStatus(401);
        $response->assertJson(['error' => ['code' => 'AUTHENTICATION_REQUIRED']]);
    }

    public function test_daily_sales_summary_excludes_voided_sales(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $shift = $this->makeShift($store);

        $this->makeSale($shift, ['grand_total' => '100.00', 'subtotal' => '100.00', 'sold_at' => '2026-06-01 10:00:00']);
        $this->makeSale($shift, ['grand_total' => '50.00', 'subtotal' => '50.00', 'sold_at' => '2026-06-01 11:00:00']);
        $this->makeSale($shift, ['grand_total' => '999.00', 'subtotal' => '999.00', 'sold_at' => '2026-06-01 12:00:00', 'status' => 'VOIDED']);

        $response = $this->forwardSessionCookie($this->login($admin))
            ->getJson('/api/v1/reports/daily-sales-summary?from=2026-06-01&to=2026-06-01');

        $response->assertOk();
        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame('150.00', $rows[0]['grand_total']);
        $this->assertSame(2, $rows[0]['transaction_count']);
    }

    public function test_sales_by_date_range_lists_every_status(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $shift = $this->makeShift($store);

        $this->makeSale($shift, ['status' => 'COMPLETED', 'sold_at' => '2026-06-01 10:00:00']);
        $this->makeSale($shift, ['status' => 'VOIDED', 'sold_at' => '2026-06-01 11:00:00']);

        $response = $this->forwardSessionCookie($this->login($admin))
            ->getJson('/api/v1/reports/sales-by-date-range?from=2026-06-01&to=2026-06-01');

        $response->assertOk();
        $this->assertCount(2, $response->json('rows'));
        $this->assertEqualsCanonicalizing(
            ['COMPLETED', 'VOIDED'],
            collect($response->json('rows'))->pluck('status')->all(),
        );
    }

    public function test_sales_by_product_aggregates_quantity_and_can_filter_by_product(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $shift = $this->makeShift($store);
        $product = Product::factory()->create(['store_id' => $store->id]);
        $otherProduct = Product::factory()->create(['store_id' => $store->id]);

        $sale = $this->makeSale($shift);
        SaleItem::factory()->create(['sale_id' => $sale->id, 'line_number' => 1, 'product_id' => $product->id, 'quantity' => '2.000', 'net_line_amount' => '100.00']);
        SaleItem::factory()->create(['sale_id' => $sale->id, 'line_number' => 2, 'product_id' => $product->id, 'quantity' => '3.000', 'net_line_amount' => '150.00']);
        SaleItem::factory()->create(['sale_id' => $sale->id, 'line_number' => 3, 'product_id' => $otherProduct->id, 'quantity' => '1.000', 'net_line_amount' => '40.00']);

        $response = $this->forwardSessionCookie($this->login($admin))->getJson('/api/v1/reports/sales-by-product');
        $response->assertOk();
        $this->assertCount(2, $response->json('rows'));

        $filtered = $this->forwardSessionCookie($this->login($admin))->getJson("/api/v1/reports/sales-by-product?product_id={$product->id}");
        $filtered->assertOk();
        $rows = $filtered->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame('5.000', $rows[0]['quantity_sold']);
        $this->assertSame('250.00', $rows[0]['net_sales']);
    }

    public function test_sales_by_category_aggregates_across_products(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $shift = $this->makeShift($store);
        $category = Category::factory()->create(['store_id' => $store->id]);
        $product = Product::factory()->create(['store_id' => $store->id, 'category_id' => $category->id]);

        $sale = $this->makeSale($shift);
        SaleItem::factory()->create(['sale_id' => $sale->id, 'product_id' => $product->id, 'net_line_amount' => '75.00']);

        $response = $this->forwardSessionCookie($this->login($admin))->getJson("/api/v1/reports/sales-by-category?category_id={$category->id}");

        $response->assertOk();
        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame($category->id, $rows[0]['category_id']);
        $this->assertSame('75.00', $rows[0]['net_sales']);
    }

    public function test_sales_by_cashier_counts_voids_and_refunds(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $shift = $this->makeShift($store);

        $completed = $this->makeSale($shift, ['grand_total' => '100.00']);
        $voided = $this->makeSale($shift, ['grand_total' => '50.00', 'status' => 'VOIDED']);
        $refunded = $this->makeSale($shift, ['grand_total' => '30.00', 'status' => 'REFUNDED']);
        SaleVoid::factory()->voided()->create(['sale_id' => $voided->id]);
        Refund::factory()->completed()->create(['sale_id' => $refunded->id]);

        $response = $this->forwardSessionCookie($this->login($admin))->getJson('/api/v1/reports/sales-by-cashier');

        $response->assertOk();
        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame(3, $rows[0]['transaction_count']);
        $this->assertSame('130.00', $rows[0]['gross_sales'], 'gross_sales excludes VOIDED only -- a REFUNDED sale is still revenue');
        $this->assertSame(1, $rows[0]['void_count']);
        $this->assertSame(1, $rows[0]['refund_count']);
    }

    public function test_sales_by_payment_method_groups_and_excludes_voided(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $shift = $this->makeShift($store);

        $cashSale = $this->makeSale($shift, ['grand_total' => '55.00']);
        Payment::factory()->create(['sale_id' => $cashSale->id, 'method' => 'CASH', 'amount' => '55.00']);

        $gcashSale = $this->makeSale($shift, ['grand_total' => '80.00']);
        Payment::factory()->gcash()->create(['sale_id' => $gcashSale->id, 'amount' => '80.00']);

        $voidedSale = $this->makeSale($shift, ['grand_total' => '999.00', 'status' => 'VOIDED']);
        Payment::factory()->create(['sale_id' => $voidedSale->id, 'method' => 'CASH', 'amount' => '999.00']);

        $response = $this->forwardSessionCookie($this->login($admin))->getJson('/api/v1/reports/sales-by-payment-method');

        $response->assertOk();
        $byMethod = collect($response->json('rows'))->keyBy('payment_method');
        $this->assertSame('55.00', $byMethod['CASH']['total_amount']);
        $this->assertSame('80.00', $byMethod['GCASH']['total_amount']);
    }

    public function test_tax_breakdown_sums_vat_columns_by_business_date(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $shift = $this->makeShift($store);

        $this->makeSale($shift, ['vat_amount' => '12.00', 'taxable_sales' => '100.00', 'sold_at' => '2026-06-01 10:00:00']);
        $this->makeSale($shift, ['vat_amount' => '6.00', 'taxable_sales' => '50.00', 'sold_at' => '2026-06-01 11:00:00']);

        $response = $this->forwardSessionCookie($this->login($admin))
            ->getJson('/api/v1/reports/tax-breakdown?from=2026-06-01&to=2026-06-01');

        $response->assertOk();
        $rows = $response->json('rows');
        $this->assertSame('18.00', $rows[0]['vat_amount']);
        $this->assertSame('150.00', $rows[0]['taxable_sales']);
    }

    public function test_void_report_lists_every_status(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $shift = $this->makeShift($store);
        $sale = $this->makeSale($shift, ['grand_total' => '75.00']);
        SaleVoid::factory()->rejected()->create(['sale_id' => $sale->id, 'reason' => 'Customer changed mind']);

        $response = $this->forwardSessionCookie($this->login($admin))->getJson('/api/v1/reports/voids');

        $response->assertOk();
        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame('REJECTED', $rows[0]['status'], 'a rejected void must be distinguishable from an executed one');
        $this->assertNotNull($rows[0]['requested_at']);
        $this->assertSame('75.00', $rows[0]['sale_grand_total']);
    }

    public function test_refund_report_lists_completed_refunds(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $shift = $this->makeShift($store);
        $sale = $this->makeSale($shift);
        $refund = Refund::factory()->completed()->create(['sale_id' => $sale->id, 'refund_total' => '20.00']);

        $response = $this->forwardSessionCookie($this->login($admin))->getJson('/api/v1/reports/refunds');

        $response->assertOk();
        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame($refund->id, $rows[0]['refund_id']);
        $this->assertSame('COMPLETED', $rows[0]['status']);
        $this->assertSame('20.00', $rows[0]['refund_total']);
    }

    public function test_discount_report_only_shows_discounted_sales(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $shift = $this->makeShift($store);

        $this->makeSale($shift, ['discount_total' => '0.00']);
        $this->makeSale($shift, ['discount_total' => '15.00', 'order_level_discount_amount' => '15.00']);

        $response = $this->forwardSessionCookie($this->login($admin))->getJson('/api/v1/reports/discounts');

        $response->assertOk();
        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame('15.00', $rows[0]['discount_total']);
    }

    public function test_inventory_on_hand_lists_balances_for_the_actors_store(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $product = Product::factory()->create(['store_id' => $store->id, 'reorder_level' => 10]);
        $location = InventoryLocation::factory()->create(['store_id' => $store->id]);
        StockBalance::factory()->create(['product_id' => $product->id, 'location_id' => $location->id, 'quantity_on_hand' => '42.000']);

        StockBalance::factory()->create(); // another store -- must not appear

        $response = $this->forwardSessionCookie($this->login($admin))->getJson('/api/v1/reports/inventory-on-hand');

        $response->assertOk();
        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame('42.000', $rows[0]['quantity_on_hand']);
    }

    public function test_inventory_on_hand_ignores_products_that_do_not_track_inventory(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $location = InventoryLocation::factory()->create(['store_id' => $store->id]);
        $tracked = Product::factory()->create(['store_id' => $store->id]);
        $untracked = Product::factory()->create(['store_id' => $store->id, 'track_inventory' => false]);
        StockBalance::factory()->create(['product_id' => $tracked->id, 'location_id' => $location->id, 'quantity_on_hand' => '5.000']);
        StockBalance::factory()->create(['product_id' => $untracked->id, 'location_id' => $location->id, 'quantity_on_hand' => '-12.000']);

        $rows = $this->forwardSessionCookie($this->login($admin))->getJson('/api/v1/reports/inventory-on-hand')->assertOk()->json('rows');

        $this->assertCount(1, $rows);
        $this->assertSame('5.000', $rows[0]['quantity_on_hand']);
    }

    public function test_low_stock_only_shows_products_below_reorder_level(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $location = InventoryLocation::factory()->create(['store_id' => $store->id]);

        $lowProduct = Product::factory()->create(['store_id' => $store->id, 'reorder_level' => 10]);
        StockBalance::factory()->create(['product_id' => $lowProduct->id, 'location_id' => $location->id, 'quantity_on_hand' => '3.000']);

        $healthyProduct = Product::factory()->create(['store_id' => $store->id, 'reorder_level' => 10]);
        StockBalance::factory()->create(['product_id' => $healthyProduct->id, 'location_id' => $location->id, 'quantity_on_hand' => '50.000']);

        $response = $this->forwardSessionCookie($this->login($admin))->getJson('/api/v1/reports/low-stock');

        $response->assertOk();
        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame($lowProduct->id, $rows[0]['product_id']);
        $this->assertSame('7.000', $rows[0]['shortfall']);
    }

    public function test_inventory_movement_lists_movements_for_the_actors_store(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $product = Product::factory()->create(['store_id' => $store->id]);
        $location = InventoryLocation::factory()->create(['store_id' => $store->id]);
        StockMovement::factory()->create(['product_id' => $product->id, 'location_id' => $location->id, 'movement_type' => 'PURCHASE_RECEIPT', 'quantity' => '25.000']);

        $response = $this->forwardSessionCookie($this->login($admin))->getJson('/api/v1/reports/inventory-movement');

        $response->assertOk();
        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame('PURCHASE_RECEIPT', $rows[0]['movement_type']);
        $this->assertSame('25.000', $rows[0]['quantity']);
    }

    public function test_shift_report_lists_shifts_for_the_actors_store(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $this->makeShift($store);
        $this->makeShift(Store::factory()->create()); // another store -- must not appear

        $response = $this->forwardSessionCookie($this->login($admin))->getJson('/api/v1/reports/shifts');

        $response->assertOk();
        $this->assertCount(1, $response->json('rows'));
    }

    public function test_cash_variance_report_only_shows_closed_shifts(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $this->makeShift($store); // OPEN -- must not appear

        $closedShift = $this->makeShift($store);
        $closedShift->update(['status' => 'CLOSED', 'closed_at' => now(), 'expected_cash' => '1000.00', 'declared_cash' => '950.00', 'variance' => '-50.00']);

        $response = $this->forwardSessionCookie($this->login($admin))->getJson('/api/v1/reports/cash-variance');

        $response->assertOk();
        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame('-50.00', $rows[0]['variance']);
    }

    public function test_a_report_can_be_exported_as_csv(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $shift = $this->makeShift($store, '2026-06-01');
        $this->makeSale($shift, [
            'grand_total' => '55.00', 'subtotal' => '55.00', 'taxable_sales' => '49.11',
            'vat_amount' => '5.89', 'vat_exempt_sales' => '0.00', 'zero_rated_sales' => '0.00', 'non_vat_sales' => '0.00',
            'sold_at' => '2026-06-01 10:00:00',
        ]);

        $response = $this->forwardSessionCookie($this->login($admin))
            ->withHeader('Accept', 'text/csv')
            ->get('/api/v1/reports/daily-sales-summary?from=2026-06-01&to=2026-06-01');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $lines = explode("\n", trim($response->getContent()));
        $this->assertSame(
            'business_date,gross_sales,discount_total,taxable_sales,vat_exempt_sales,zero_rated_sales,vat_amount,grand_total,transaction_count,non_vat_sales',
            $lines[0],
        );
        $this->assertStringContainsString('2026-06-01', $lines[1]);
        $this->assertStringContainsString('55.00', $lines[1]);
    }

    public function test_void_csv_keeps_the_pinned_columns_and_omits_the_json_only_status(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $shift = $this->makeShift($store);
        SaleVoid::factory()->rejected()->create(['sale_id' => $this->makeSale($shift)->id]);

        $response = $this->forwardSessionCookie($this->login($admin))
            ->withHeader('Accept', 'text/csv')
            ->get('/api/v1/reports/voids');

        $response->assertOk();
        $lines = explode('
', trim($response->getContent()));
        $this->assertSame(
            'void_id,sale_id,invoice_number,requested_by,approved_by,reason,terminal_id,fiscal_day_id,resolved_at,sale_grand_total',
            $lines[0],
        );
    }

    public function test_json_is_returned_by_default(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);

        $response = $this->forwardSessionCookie($this->login($admin))->getJson('/api/v1/reports/inventory-on-hand');

        $response->assertOk();
        $response->assertJsonStructure(['generated_at', 'filters_applied', 'rows', 'summary']);
    }

    public function test_a_manager_can_also_view_reports(): void
    {
        $store = Store::factory()->create();
        $manager = User::factory()->manager()->create(['store_id' => $store->id]);

        $response = $this->forwardSessionCookie($this->login($manager))->getJson('/api/v1/reports/shifts');

        $response->assertOk();
    }

    public function test_sales_by_hour_buckets_across_the_date_range_and_excludes_voided(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $shift = $this->makeShift($store);

        // Two sales in the 10:00 hour on different days both fall into the same "hour" bucket.
        $this->makeSale($shift, ['subtotal' => '100.00', 'grand_total' => '100.00', 'sold_at' => '2026-06-01 10:15:00']);
        $this->makeSale($shift, ['subtotal' => '50.00', 'grand_total' => '50.00', 'sold_at' => '2026-06-02 10:45:00']);
        $this->makeSale($shift, ['subtotal' => '20.00', 'grand_total' => '20.00', 'sold_at' => '2026-06-01 14:00:00']);
        $this->makeSale($shift, ['subtotal' => '999.00', 'grand_total' => '999.00', 'sold_at' => '2026-06-01 10:30:00', 'status' => 'VOIDED']);

        $response = $this->forwardSessionCookie($this->login($admin))->getJson('/api/v1/reports/sales-by-hour');

        $response->assertOk();
        $byHour = collect($response->json('rows'))->keyBy('hour');
        $this->assertSame(2, $byHour[10]['transaction_count']);
        $this->assertSame('150.00', $byHour[10]['gross_sales']);
        $this->assertSame(1, $byHour[14]['transaction_count']);
        $this->assertSame('20.00', $byHour[14]['gross_sales']);
        $this->assertSame('170.00', $response->json('summary.gross_sales'));
    }

    public function test_gross_profit_computes_from_cost_snapshot_and_discloses_unknown_cost(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $shift = $this->makeShift($store, '2026-06-01');
        $product = Product::factory()->create(['store_id' => $store->id]);

        $sale = $this->makeSale($shift, ['sold_at' => '2026-06-01 10:00:00']);
        // 3 units sold at net 150.00, cost 10.00 each -> COGS 30.00, gross profit 120.00, margin 80.00%.
        SaleItem::factory()->create([
            'sale_id' => $sale->id, 'line_number' => 1, 'product_id' => $product->id,
            'quantity' => '3.000', 'net_line_amount' => '150.00', 'unit_cost_snapshot' => '10.00',
        ]);
        // A line whose product predates cost tracking: no snapshot, costs nothing in the sum but is
        // flagged, never silently treated as a real zero-cost line.
        SaleItem::factory()->create([
            'sale_id' => $sale->id, 'line_number' => 2, 'product_id' => $product->id,
            'quantity' => '1.000', 'net_line_amount' => '25.00', 'unit_cost_snapshot' => null,
        ]);

        $voidedSale = $this->makeSale($shift, ['sold_at' => '2026-06-01 11:00:00', 'status' => 'VOIDED']);
        SaleItem::factory()->create(['sale_id' => $voidedSale->id, 'product_id' => $product->id, 'net_line_amount' => '999.00', 'unit_cost_snapshot' => '1.00']);

        $response = $this->forwardSessionCookie($this->login($admin))->getJson('/api/v1/reports/gross-profit?from=2026-06-01&to=2026-06-01');

        $response->assertOk();
        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame('2026-06-01', $rows[0]['business_date']);
        $this->assertSame(1, $rows[0]['transaction_count']);
        $this->assertSame('175.00', $rows[0]['net_sales']);
        $this->assertSame('30.00', $rows[0]['cost_of_goods_sold']);
        $this->assertSame('145.00', $rows[0]['gross_profit']);
        $this->assertSame('82.86', $rows[0]['gross_margin_percent']); // 145 / 175 * 100
        $this->assertSame(1, $rows[0]['lines_with_unknown_cost']);
    }

    public function test_gross_profit_margin_is_null_when_net_sales_is_zero(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $shift = $this->makeShift($store, '2026-06-01');
        $product = Product::factory()->create(['store_id' => $store->id]);

        $sale = $this->makeSale($shift, ['sold_at' => '2026-06-01 10:00:00']);
        SaleItem::factory()->create(['sale_id' => $sale->id, 'product_id' => $product->id, 'net_line_amount' => '0.00', 'unit_cost_snapshot' => '0.00']);

        $response = $this->forwardSessionCookie($this->login($admin))->getJson('/api/v1/reports/gross-profit?from=2026-06-01&to=2026-06-01');

        $response->assertOk();
        $this->assertNull($response->json('rows.0.gross_margin_percent'));
    }

    public function test_gross_profit_by_product_groups_by_product_instead_of_date(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $shift = $this->makeShift($store);
        $product = Product::factory()->create(['store_id' => $store->id]);

        $sale = $this->makeSale($shift);
        SaleItem::factory()->create(['sale_id' => $sale->id, 'line_number' => 1, 'product_id' => $product->id, 'quantity' => '2.000', 'net_line_amount' => '100.00', 'unit_cost_snapshot' => '20.00']);

        $voidedSale = $this->makeSale($shift, ['status' => 'VOIDED']);
        SaleItem::factory()->create(['sale_id' => $voidedSale->id, 'product_id' => $product->id, 'net_line_amount' => '999.00', 'unit_cost_snapshot' => '1.00']);

        $response = $this->forwardSessionCookie($this->login($admin))->getJson('/api/v1/reports/gross-profit-by-product');

        $response->assertOk();
        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame($product->id, $rows[0]['product_id']);
        $this->assertSame('2.000', $rows[0]['quantity_sold']);
        $this->assertSame('100.00', $rows[0]['net_sales']);
        $this->assertSame('40.00', $rows[0]['cost_of_goods_sold']);
        $this->assertSame('60.00', $rows[0]['gross_profit']);
        $this->assertSame('60.00', $rows[0]['gross_margin_percent']);
    }

    public function test_product_velocity_lists_a_never_sold_product_alongside_its_stock(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $shift = $this->makeShift($store);
        $location = InventoryLocation::factory()->create(['store_id' => $store->id]);

        // Named A/B/C (rather than the factory's random name) so the never-sold products' tie-break
        // order below is deterministic, not a coincidence of faker's output this run.
        $soldProduct = Product::factory()->create(['store_id' => $store->id, 'name' => 'A Sold Product']);
        StockBalance::factory()->create(['product_id' => $soldProduct->id, 'location_id' => $location->id, 'quantity_on_hand' => '5.000']);
        $sale = $this->makeSale($shift, ['sold_at' => '2026-06-01 10:00:00']);
        SaleItem::factory()->create(['sale_id' => $sale->id, 'product_id' => $soldProduct->id, 'quantity' => '3.000', 'net_line_amount' => '150.00']);

        $neverSoldProduct = Product::factory()->create(['store_id' => $store->id, 'name' => 'B Never Sold Product']);
        StockBalance::factory()->create(['product_id' => $neverSoldProduct->id, 'location_id' => $location->id, 'quantity_on_hand' => '40.000']);

        $untrackedProduct = Product::factory()->create(['store_id' => $store->id, 'name' => 'C Untracked Product', 'track_inventory' => false]);

        $response = $this->forwardSessionCookie($this->login($admin))->getJson('/api/v1/reports/product-velocity?from=2026-06-01&to=2026-06-01');

        $response->assertOk();
        $byProduct = collect($response->json('rows'))->keyBy('product_id');
        $this->assertCount(3, $byProduct);

        $this->assertSame('3.000', $byProduct[$soldProduct->id]['quantity_sold']);
        $this->assertSame(1, $byProduct[$soldProduct->id]['transaction_count']);
        $this->assertSame('150.00', $byProduct[$soldProduct->id]['net_sales']);
        $this->assertSame('5.000', $byProduct[$soldProduct->id]['quantity_on_hand']);

        // The never-sold product still appears -- with real stock but zero sales, which is the
        // whole point of this report (salesByProduct, an INNER JOIN, could never show it at all).
        $this->assertSame('0.000', $byProduct[$neverSoldProduct->id]['quantity_sold']);
        $this->assertSame(0, $byProduct[$neverSoldProduct->id]['transaction_count']);
        $this->assertSame('0.00', $byProduct[$neverSoldProduct->id]['net_sales']);
        $this->assertSame('40.000', $byProduct[$neverSoldProduct->id]['quantity_on_hand']);

        // Untracked: no ledger, so null ("not tracked"), never "0.000" ("confirmed empty").
        $this->assertNull($byProduct[$untrackedProduct->id]['quantity_on_hand']);

        // Sorted fastest-first.
        $this->assertSame([$soldProduct->id, $neverSoldProduct->id, $untrackedProduct->id], collect($response->json('rows'))->pluck('product_id')->all());
    }

    public function test_product_velocity_excludes_a_voided_sales_quantity(): void
    {
        $store = Store::factory()->create();
        $admin = User::factory()->admin()->create(['store_id' => $store->id]);
        $shift = $this->makeShift($store);
        $product = Product::factory()->create(['store_id' => $store->id]);

        $voidedSale = $this->makeSale($shift, ['status' => 'VOIDED']);
        SaleItem::factory()->create(['sale_id' => $voidedSale->id, 'product_id' => $product->id, 'quantity' => '99.000', 'net_line_amount' => '999.00']);

        $response = $this->forwardSessionCookie($this->login($admin))->getJson('/api/v1/reports/product-velocity');

        $response->assertOk();
        $row = collect($response->json('rows'))->firstWhere('product_id', $product->id);
        $this->assertSame('0.000', $row['quantity_sold']);
        $this->assertSame(0, $row['transaction_count']);
    }
}
