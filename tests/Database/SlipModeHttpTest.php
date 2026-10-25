<?php

namespace Tests\Database;

use App\Models\AuditEvent;
use App\Models\MealOptionGroup;
use App\Models\Store;
use App\Models\StoreSettings;
use App\Models\User;
use App\Services\Menu\DishCategoryService;
use App\Services\Menu\DishService;
use App\Services\Menu\MealOptionGroupService;
use App\Services\Menu\MealOptionService;
use App\Services\Menu\MealService;
use App\Services\Menu\MenuPriceService;
use App\Services\StoreSetup\FiscalConfigurationLock;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * ADR-016 slice 2.2a (invariants.md SLIP-001): a store in SLIP mode gives a small slip for every sale, from its own per-terminal
 * running number, and touches nothing of the invoice path: no fiscal installation, no invoice series, no invoice row, no electronic
 * journal entry. Built through the real endpoints; InvoiceModeUnchangedTest pins that INVOICE mode is byte-for-byte what it was.
 */
class SlipModeHttpTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests sign in as several people many times; the login throttle (tested in AuthenticationSessionTest) is not under test here.
        config(['tindaflow.login_throttle.max_attempts' => 100000]);
    }

    /** @param  array<string, mixed>  $w */
    private function slipMode(array $w, ?string $footer = null): void
    {
        $w['t1']->update(['terminal_code' => 'T-01']);
        $w['t2']->update(['terminal_code' => 'T-02']);
        DB::table('store_settings')->updateOrInsert(['store_id' => $w['storeId']], [
            'business_name' => 'Canteen One', 'registered_name' => '', 'tin' => '', 'sale_document_mode' => 'SLIP', 'slip_footer' => $footer,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A canteen that never set up anything fiscal: no installation for the till, no tax registration. */
    private function withoutFiscalSetup(array $w): void
    {
        DB::table('terminal_fiscal_installations')->delete();
        DB::table('tax_registrations')->where('store_id', $w['storeId'])->delete();
    }

    /** @return array<string, mixed> */
    private function ringAt(array $w, string $who = 'cashier', int $till = 1, ?array $lines = null, string $method = 'CASH'): array
    {
        $lines ??= [[$w['product'], '2']];
        $total = array_reduce($lines, fn (string $carry, array $line) => bcadd($carry, bcmul($line[0]->selling_price, $line[1], 2), 2), '0.00');

        return $this->asUser($w[$who], $w['enroll'.$till])->postJson('/api/v1/sales', [
            'items' => array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1]], $lines),
            'payments' => [['method' => $method, 'amount' => $total]],
        ], $this->key())->assertStatus(201)->json();
    }

    public function test_a_slip_sale_uses_no_invoice_number_and_writes_no_invoice_or_journal_entry(): void
    {
        $w = $this->world();
        $this->slipMode($w);
        $this->withoutFiscalSetup($w);
        $seriesBefore = DB::table('invoice_series')->where('store_id', $w['storeId'])->value('current_number');

        $sale = $this->ringAt($w);

        $this->assertSame('SLIP', $sale['document_mode']);
        $this->assertSame('T-01-000001', $sale['document_reference']);
        $this->assertNull($sale['invoice_number']);
        $this->assertNull($sale['invoice']);
        $this->assertSame(['id' => $sale['slip']['id'], 'slip_number' => 'T-01-000001', 'issued_at' => $sale['slip']['issued_at'], 'schema_version' => 1], $sale['slip']);

        $this->assertSame(0, DB::table('invoices')->where('sale_id', $sale['id'])->count());
        $this->assertSame(1, DB::table('sale_slips')->where('sale_id', $sale['id'])->count());
        $this->assertSame('SLIP', DB::table('sales')->where('id', $sale['id'])->value('document_mode'));
        $this->assertSame($seriesBefore, DB::table('invoice_series')->where('store_id', $w['storeId'])->value('current_number'), 'no invoice number was consumed');
        $this->assertSame(0, DB::table('electronic_journal_entries')->where('event_type', 'INVOICE')->count(), 'a slip sale writes no invoice journal entry');
        $this->assertSame(0, DB::table('electronic_journal_entries')->where('source_id', $sale['id'])->count());

        $audit = AuditEvent::where('event_type', 'SALE_FINALIZED')->where('entity_id', $sale['id'])->sole();
        $this->assertSame('T-01-000001', $audit->after_metadata['slip_number']);
        $this->assertArrayNotHasKey('invoice_number', $audit->after_metadata);
        $this->assertSame('NON_VAT', $audit->after_metadata['tax_registration_assumed'], 'with no registration on file the sale is computed as non-VAT and the audit says so');
    }

    public function test_with_no_tax_registration_the_sale_is_computed_as_non_vat_and_with_one_it_is_computed_as_in_invoice_mode(): void
    {
        $none = $this->world();
        $this->slipMode($none);
        $this->withoutFiscalSetup($none);
        $unregistered = $this->ringAt($none);
        $this->assertSame('0.00', $unregistered['tax_summary']['vat_amount']);
        $this->assertSame('200.00', $unregistered['tax_summary']['non_vat_sales']);
        $this->assertSame('NON_VAT', $unregistered['items'][0]['tax_classification_snapshot']);

        $vat = $this->world(); // registered as VAT
        $this->slipMode($vat);
        $registered = $this->ringAt($vat);
        $this->assertSame('21.43', $registered['tax_summary']['vat_amount'], 'a registration that exists is used exactly as in invoice mode');
        $this->assertArrayNotHasKey('tax_registration_assumed', AuditEvent::where('event_type', 'SALE_FINALIZED')->where('entity_id', $registered['id'])->sole()->after_metadata);
    }

    public function test_slip_numbers_run_per_terminal_with_no_gap_and_two_tills_have_their_own_sequence(): void
    {
        $w = $this->world();
        $this->slipMode($w);

        $first = $this->ringAt($w);
        $second = $this->ringAt($w);
        $other = $this->ringAt($w, 'manager', 2);
        $third = $this->ringAt($w);

        $this->assertSame(['T-01-000001', 'T-01-000002', 'T-01-000003'], [$first['document_reference'], $second['document_reference'], $third['document_reference']]);
        $this->assertSame('T-02-000001', $other['document_reference'], 'the second till starts its own sequence');
        $this->assertSame(3, (int) DB::table('slip_counters')->where('terminal_id', $w['t1']->id)->value('last_number'));
        $this->assertSame(1, (int) DB::table('slip_counters')->where('terminal_id', $w['t2']->id)->value('last_number'));
    }

    public function test_a_sale_that_rolls_back_leaves_no_gap_in_the_slip_numbers(): void
    {
        $w = $this->world();
        $this->slipMode($w);
        $this->ringAt($w);

        // The slip number is allocated early in the checkout; a failure after it (here, the audit event of the sale) rolls the whole sale back,
        // number included.
        $fail = true;
        AuditEvent::creating(function (AuditEvent $event) use (&$fail) {
            if ($fail && $event->event_type === 'SALE_FINALIZED') {
                throw new RuntimeException('audit write failed');
            }
        });
        $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => [['product_id' => $w['product']->id, 'quantity' => '1']],
            'payments' => [['method' => 'CASH', 'amount' => '100.00']],
        ], $this->key())->assertStatus(500);
        $fail = false;

        $this->assertSame(1, DB::table('sale_slips')->count());
        $this->assertSame(1, (int) DB::table('slip_counters')->where('terminal_id', $w['t1']->id)->value('last_number'));

        $next = $this->ringAt($w);
        $this->assertSame('T-01-000002', $next['document_reference'], 'the failed sale took no number');
        $this->assertSame([1, 2], DB::table('sale_slips')->orderBy('slip_sequence')->pluck('slip_sequence')->map(fn ($n) => (int) $n)->all());
    }

    public function test_the_slip_prints_the_least_that_identifies_the_sale_and_never_the_words_invoice_or_official_receipt(): void
    {
        $w = $this->world();
        $this->slipMode($w);
        $sale = $this->ringAt($w);

        $html = $this->asUser($w['cashier'])->getJson("/api/v1/sale-slips/{$sale['slip']['id']}")->assertOk()->json('render_html');

        foreach (['Canteen One', 'T-01-000001', $w['cashier']->name, '200.00', 'TOTAL', 'Cash'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $this->assertStringNotContainsStringIgnoringCase('invoice', $html);
        $this->assertStringNotContainsStringIgnoringCase('official receipt', $html);
        foreach (['VAT', 'TIN', 'Sold to', 'PTU', 'Accreditation', 'REPRINT'] as $notOnASlip) {
            $this->assertStringNotContainsString($notOnASlip, $html);
        }
        $this->assertStringContainsString('size:58mm', $html, 'a very small slip: a 58mm roll');
    }

    public function test_the_footer_is_the_stores_own_line_empty_by_default_and_a_printed_slip_keeps_the_footer_it_was_printed_with(): void
    {
        $w = $this->world();
        $this->slipMode($w);
        $plain = $this->ringAt($w);
        $this->assertNull(DB::table('store_settings')->where('store_id', $w['storeId'])->value('slip_footer'));

        $this->asUser($w['admin'])->putJson('/api/v1/sale-document-settings/slip-footer', ['slip_footer' => 'Salamat po!'])
            ->assertOk()->assertJson(['slip_footer' => 'Salamat po!']);
        $withFooter = $this->ringAt($w);

        $html = fn (array $sale) => $this->asUser($w['cashier'])->getJson("/api/v1/sale-slips/{$sale['slip']['id']}")->json('render_html');
        $this->assertStringNotContainsString('Salamat po!', $html($plain));
        $this->assertStringContainsString('Salamat po!', $html($withFooter));

        $this->asUser($w['admin'])->putJson('/api/v1/sale-document-settings/slip-footer', ['slip_footer' => 'Changed'])->assertOk();
        $this->assertStringContainsString('Salamat po!', $html($withFooter), 'a reprint reads the snapshot, never today\'s footer');
        $this->assertStringNotContainsString('Changed', $html($withFooter));
        $this->asUser($w['admin'])->putJson('/api/v1/sale-document-settings/slip-footer', ['slip_footer' => ''])->assertOk()->assertJson(['slip_footer' => null]);

        $this->assertSame(3, AuditEvent::where('event_type', 'SETTINGS_CHANGED')->where('entity_type', 'store_settings')->count());
        $this->asUser($w['cashier'])->putJson('/api/v1/sale-document-settings/slip-footer', ['slip_footer' => 'x'])->assertStatus(403);
        $this->asUser($w['admin'])->putJson('/api/v1/sale-document-settings/slip-footer', ['slip_footer' => str_repeat('x', 201)])->assertStatus(422);
    }

    public function test_the_first_print_is_the_plain_original_and_a_reprint_is_marked_and_recorded_and_replays_on_a_retry(): void
    {
        $w = $this->world();
        $this->slipMode($w);
        $sale = $this->ringAt($w);
        $slipId = $sale['slip']['id'];

        $original = $this->asUser($w['cashier'])->getJson("/api/v1/sale-slips/{$slipId}")->assertOk()->json('render_html');
        $this->assertStringNotContainsString('REPRINT', $original);
        $this->assertSame(0, AuditEvent::where('event_type', 'SLIP_REPRINTED')->count());

        $key = $this->key();
        $copy = $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/sale-slips/{$slipId}/reprints", [], $key)->assertStatus(201);
        $this->assertTrue($copy->json('is_reprint'));
        $this->assertStringContainsString('REPRINT', $copy->json('slip.render_html'));
        $this->assertStringContainsString('T-01-000001', $copy->json('slip.render_html'), 'a reprint carries the same slip number');
        $this->assertStringNotContainsStringIgnoringCase('invoice', $copy->json('slip.render_html'));

        $event = AuditEvent::where('event_type', 'SLIP_REPRINTED')->sole();
        $this->assertSame($copy->json('reprint_event_id'), $event->id);
        $this->assertSame($w['cashier']->id, $event->actor_user_id);
        $this->assertSame('T-01-000001', $event->after_metadata['slip_number']);

        $replay = $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/sale-slips/{$slipId}/reprints", [], $key)->assertStatus(201);
        $this->assertSame($copy->json('reprint_event_id'), $replay->json('reprint_event_id'));
        $this->assertSame(1, AuditEvent::where('event_type', 'SLIP_REPRINTED')->count(), 'a retry records no second copy');

        $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/sale-slips/{$slipId}/reprints", [], $this->key())->assertStatus(201);
        $this->assertSame(2, AuditEvent::where('event_type', 'SLIP_REPRINTED')->count());
        $this->assertSame(1, DB::table('sale_slips')->where('sale_id', $sale['id'])->count(), 'no new slip, no new number');
        $this->assertSame(1, (int) DB::table('slip_counters')->where('terminal_id', $w['t1']->id)->value('last_number'));
        $this->assertSame(0, DB::table('electronic_journal_entries')->where('source_type', 'invoice_reprint')->count());
    }

    public function test_a_reprint_needs_an_enrolled_terminal_and_an_idempotency_key_and_a_slip_of_another_store_is_not_found(): void
    {
        $w = $this->world();
        $this->slipMode($w);
        $sale = $this->ringAt($w);
        $slipId = $sale['slip']['id'];

        $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/sale-slips/{$slipId}/reprints")->assertStatus(400);
        $this->asUser($w['cashier'])->postJson("/api/v1/sale-slips/{$slipId}/reprints", [], $this->key())->assertStatus(403);

        $elsewhere = $this->world();
        $this->asUser($elsewhere['cashier'])->getJson("/api/v1/sale-slips/{$slipId}")->assertStatus(404)->assertJsonPath('error.code', 'SLIP_NOT_FOUND');
        $this->asUser($elsewhere['cashier'], $elsewhere['enroll1'])->postJson("/api/v1/sale-slips/{$slipId}/reprints", [], $this->key())->assertStatus(404);
    }

    public function test_meals_and_add_ons_print_correctly_on_the_slip(): void
    {
        $w = $this->world();
        $this->slipMode($w);
        $admin = $w['admin'];
        $beef = app(DishCategoryService::class)->create($admin, ['name' => 'Beef']);
        $nilaga = app(DishService::class)->create($admin, ['name' => 'Beef nilaga', 'dish_category_id' => $beef->id]);
        $meal = app(MealService::class)->create($admin, ['name' => 'Standard Meal', 'pricing_group_name' => 'Viand']);
        $viand = MealOptionGroup::where('meal_id', $meal->id)->where('prices_the_meal', true)->sole();
        $nilagaOption = app(MealOptionService::class)->create($admin, $viand->id, ['dish_id' => $nilaga->id]);
        $rice = app(MealOptionGroupService::class)->create($admin, $meal->id, ['name' => 'Extra rice', 'kind' => 'ADDON', 'min_select' => 0, 'max_select' => 1]);
        $wholeRice = app(MealOptionService::class)->create($admin, $rice->id, ['label' => 'Whole rice']);
        app(MenuPriceService::class)->set($admin, $meal->id, ['dish_category_id' => $beef->id, 'amount' => '68.00']);
        app(MenuPriceService::class)->set($admin, $meal->id, ['meal_option_id' => $wholeRice->id, 'amount' => '15.00']);

        $sale = $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => [
                ['meal' => ['meal_id' => $meal->id, 'choices' => [['group_id' => $viand->id, 'option_id' => $nilagaOption->id]], 'addons' => [$wholeRice->id]], 'quantity' => '1'],
                ['product_id' => $w['product2']->id, 'quantity' => '2'],
            ],
            'payments' => [['method' => 'CASH', 'amount' => '200.00'], ['method' => 'GCASH', 'amount' => '0.01', 'external_reference' => 'GC-778']],
        ], $this->key())->assertStatus(201)->json();
        $this->assertSame('183.00', $sale['grand_total']);

        $html = $this->asUser($w['cashier'])->getJson("/api/v1/sale-slips/{$sale['slip']['id']}")->json('render_html');

        $this->assertStringContainsString('Standard Meal - Beef nilaga', $html);
        $this->assertStringContainsString('+ Whole rice', $html, 'an add-on hangs under its meal');
        $this->assertStringContainsString('68.00', $html);
        $this->assertStringContainsString('15.00', $html);
        $this->assertStringContainsString('2 x 50.00', $html, 'a quantity above one shows its unit price');
        $this->assertStringContainsString('183.00', $html);
        $this->assertStringContainsString('GCash', $html);
        $this->assertStringContainsString('Ref: GC-778', $html);
        $this->assertStringContainsString('Change', $html);
        $this->assertGreaterThan(strpos($html, 'Standard Meal'), strpos($html, '+ Whole rice'));
    }

    public function test_void_and_refund_of_a_slip_sale_work_and_name_the_slip(): void
    {
        $w = $this->world();
        $this->slipMode($w);
        $this->withoutFiscalSetup($w);
        $voided = $this->ringAt($w);
        $refunded = $this->ringAt($w);

        $void = $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$voided['id']}/void", ['reason' => 'Wrong order'], $this->key())
            ->assertStatus(201)->assertJson(['status' => 'VOIDED', 'sale' => ['status' => 'VOIDED', 'document_mode' => 'SLIP', 'document_reference' => 'T-01-000001']]);
        $this->assertNull($void->json('sale.invoice_number'));
        $voidJournal = json_decode(DB::table('electronic_journal_entries')->where('event_type', 'VOID')->value('payload_json'), true);
        $this->assertSame('T-01-000001', $voidJournal['slip_number']);
        $this->assertNull($voidJournal['invoice_number']);

        $refund = $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/sales/{$refunded['id']}/refunds", [
            'items' => [['sale_item_id' => $refunded['items'][0]['id'], 'quantity' => '2', 'disposition' => 'RETURN_TO_STOCK']],
            'settlements' => [['payment_method' => 'CASH', 'amount' => '200.00']],
            'reason' => 'Customer returned it',
        ], $this->key())->assertStatus(201)->assertJson(['status' => 'COMPLETED', 'sale' => ['document_mode' => 'SLIP', 'document_reference' => 'T-01-000002']]);
        $this->assertSame('REFUNDED', DB::table('sales')->where('id', $refunded['id'])->value('status'));
        $refundJournal = json_decode(DB::table('electronic_journal_entries')->where('event_type', 'REFUND')->value('payload_json'), true);
        $this->assertSame('T-01-000002', $refundJournal['slip_number']);

        // The slip still opens and reprints after the reversal.
        $this->asUser($w['cashier'])->getJson("/api/v1/sale-slips/{$voided['slip']['id']}")->assertOk();
        $detail = $this->asUser($w['manager'])->getJson("/api/v1/sales/{$voided['id']}")->assertOk()->json();
        $this->assertSame('T-01-000001', $detail['document_reference']);
        $this->assertSame('T-01-000001', $detail['slip']['slip_number']);
    }

    public function test_a_sale_is_found_by_its_slip_number_and_an_invoice_sale_by_its_invoice_number(): void
    {
        $w = $this->world();
        $invoiceSale = $this->ring($w); // INVOICE mode first
        $this->slipMode($w);
        $slipSale = $this->ringAt($w);

        foreach (['slip_number', 'document_number'] as $filter) {
            $found = $this->asUser($w['manager'])->getJson("/api/v1/sales?{$filter}=T-01-000001")->assertOk();
            $this->assertSame([$slipSale['id']], $found->json('data.*.id'), $filter);
            $this->assertSame('T-01-000001', $found->json('data.0.document_reference'));
            $this->assertSame('SLIP', $found->json('data.0.document_mode'));
        }
        $byInvoice = $this->asUser($w['manager'])->getJson('/api/v1/sales?document_number='.$invoiceSale['invoice_number'])->assertOk();
        $this->assertSame([$invoiceSale['id']], $byInvoice->json('data.*.id'));
        $this->assertSame('INVOICE', $byInvoice->json('data.0.document_mode'));
        $this->assertSame($invoiceSale['invoice_number'], $byInvoice->json('data.0.document_reference'));
        $this->assertSame([], $this->asUser($w['manager'])->getJson('/api/v1/sales?invoice_number=T-01-000001')->json('data'));
        $this->assertSame([], $this->asUser($w['manager'])->getJson('/api/v1/sales?document_number=nope')->json('data'));
        $this->assertSame([$slipSale['id'], $invoiceSale['id']], $this->asUser($w['manager'])->getJson('/api/v1/sales')->json('data.*.id'));
    }

    public function test_shift_close_gives_the_same_variance_in_both_modes(): void
    {
        $close = function (array $w): array {
            $w['cashierShift']->update(['opening_cash' => '1000.00']);
            $w['cashierShift']->refresh();
            $this->ringAt($w, 'cashier', 1, [[$w['product'], '2']]);
            $this->ringAt($w, 'cashier', 1, [[$w['product2'], '1']], 'GCASH');
            $response = $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/shifts/{$w['cashierShift']->id}/close", ['declared_cash' => bcadd((string) $w['cashierShift']->opening_cash, '190.00', 2)], $this->key())->assertOk();

            return $response->json('shift');
        };

        $invoice = $this->world();
        $slip = $this->world();
        $this->slipMode($slip);

        $invoiceShift = $close($invoice);
        $slipShift = $close($slip);

        $this->assertSame('-10.00', $invoiceShift['variance']);
        foreach (['expected_cash', 'declared_cash', 'variance', 'cash_sales', 'non_cash_sales', 'refunds_total', 'cash_in_total', 'cash_out_total'] as $field) {
            $this->assertSame($invoiceShift[$field], $slipShift[$field], $field);
        }
        $this->assertSame('SLIP', DB::table('sales')->where('store_id', $slip['storeId'])->value('document_mode'));
    }

    public function test_the_day_close_prints_an_end_of_day_report_in_slip_mode_and_a_z_reading_in_invoice_mode(): void
    {
        $print = function (array $w): array {
            $this->ringAt($w);
            $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/shifts/{$w['cashierShift']->id}/close", ['declared_cash' => bcadd((string) $w['cashierShift']->opening_cash, '200.00', 2)], $this->key())->assertOk();
            $closed = $this->asUser($w['manager'], $w['enroll1'])->postJson("/api/v1/fiscal-days/{$w['cashierShift']->fiscal_day_id}/close", [], $this->key())->assertOk();
            $printed = $this->asUser($w['manager'])->postJson("/api/v1/fiscal-days/{$w['cashierShift']->fiscal_day_id}/z-reading/prints")->assertStatus(201);

            return ['mode' => $closed->json('z_reading.document_mode'), 'html' => $printed->json('render_html'), 'totals' => $closed->json('z_reading.totals_snapshot')];
        };

        $invoice = $this->world();
        $slip = $this->world();
        $this->slipMode($slip);

        $z = $print($invoice);
        $eod = $print($slip);

        $this->assertSame('INVOICE', $z['mode']);
        $this->assertStringContainsString('Z-READING', $z['html']);
        $this->assertStringContainsString('VATable sales', $z['html']);
        $this->assertStringContainsString('Accumulated sales', $z['html']);

        $this->assertSame('SLIP', $eod['mode']);
        $this->assertStringContainsString('END OF DAY REPORT', $eod['html']);
        $this->assertStringNotContainsString('Z-READING', $eod['html']);
        $this->assertStringNotContainsString('Z-Reading', $eod['html']);
        $this->assertStringNotContainsString('VAT', $eod['html']);
        $this->assertStringNotContainsString('Accumulated', $eod['html']);
        $this->assertStringContainsString('200.00', $eod['html']);
        $this->assertSame($z['totals']['gross_sales'], $eod['totals']['gross_sales'], 'the figures themselves are unchanged');
    }

    public function test_readiness_does_not_ask_a_slip_store_for_fiscal_setup(): void
    {
        $w = $this->world();
        $this->withoutFiscalSetup($w);

        $invoiceMode = $this->asUser($w['cashier'], $w['enroll1'])->getJson('/api/v1/store-setup/readiness')->assertOk();
        $this->assertFalse($invoiceMode->json('ready'));
        $this->assertSame('INVOICE', $invoiceMode->json('document_mode'));

        $this->slipMode($w);
        $slipMode = $this->asUser($w['cashier'], $w['enroll1'])->getJson('/api/v1/store-setup/readiness')->assertOk();
        $this->assertTrue($slipMode->json('ready'));
        $this->assertSame('SLIP', $slipMode->json('document_mode'));
        $this->assertTrue($this->asUser($w['admin'])->getJson('/api/v1/store-setup/overview')->assertOk()->json('ready'));

        DB::table('inventory_locations')->where('store_id', $w['storeId'])->delete();
        $this->assertFalse($this->asUser($w['cashier'], $w['enroll1'])->getJson('/api/v1/store-setup/readiness')->json('ready'), 'a default inventory location is still needed');
    }

    public function test_a_shift_opens_in_slip_mode_with_no_fiscal_installation_at_all(): void
    {
        $w = $this->world();
        $this->slipMode($w);
        $this->withoutFiscalSetup($w);
        $this->asUser($w['cashier'], $w['enroll1'])->postJson("/api/v1/shifts/{$w['cashierShift']->id}/close", ['declared_cash' => (string) $w['cashierShift']->opening_cash], $this->key())->assertOk();

        $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/shifts/open', ['opening_cash' => '500.00'], $this->key())->assertStatus(201)->assertJson(['shift' => ['status' => 'OPEN']]);
    }

    // ---------------------------------------------------------------- the mode switch

    public function test_the_mode_is_changed_only_with_the_unlock_capability_and_is_audited(): void
    {
        $admin = $this->fiscalAdmin();
        $manager = User::factory()->manager()->create(['store_id' => $admin->store_id]);
        $this->assertSame(0, StoreSettings::count());

        $this->asUser($admin)->getJson('/api/v1/sale-document-settings')->assertOk()
            ->assertExactJson(['sale_document_mode' => 'INVOICE', 'slip_footer' => null, 'open_shifts' => 0, 'open_fiscal_days' => 0]);

        $this->asUser($manager)->putJson('/api/v1/sale-document-settings/mode', ['sale_document_mode' => 'SLIP'])->assertStatus(403);
        $this->asUser($admin)->putJson('/api/v1/sale-document-settings/mode', ['sale_document_mode' => 'BOTH'])->assertStatus(422);

        $this->asUser($admin)->putJson('/api/v1/sale-document-settings/mode', ['sale_document_mode' => 'SLIP'])->assertOk()->assertJson(['sale_document_mode' => 'SLIP']);
        $settings = StoreSettings::sole();
        $this->assertSame('SLIP', $settings->sale_document_mode);
        $this->assertSame(Store::findOrFail($admin->store_id)->name, $settings->business_name, 'a store with no settings gets the same blank baseline the business details screen shows');
        $this->assertSame('', $settings->tin);

        $event = AuditEvent::where('event_type', 'SALE_DOCUMENT_MODE_CHANGED')->sole();
        $this->assertSame($admin->id, $event->actor_user_id);
        $this->assertSame(['sale_document_mode' => 'INVOICE'], $event->before_metadata);
        $this->assertSame(['sale_document_mode' => 'SLIP'], $event->after_metadata);

        // Asking for the mode the store is already in changes nothing and writes nothing.
        $this->asUser($admin)->putJson('/api/v1/sale-document-settings/mode', ['sale_document_mode' => 'SLIP'])->assertOk();
        $this->assertSame(1, AuditEvent::where('event_type', 'SALE_DOCUMENT_MODE_CHANGED')->count());

        $this->asUser($admin)->putJson('/api/v1/sale-document-settings/mode', ['sale_document_mode' => 'INVOICE'])->assertOk()->assertJson(['sale_document_mode' => 'INVOICE']);
        $this->assertSame(2, AuditEvent::where('event_type', 'SALE_DOCUMENT_MODE_CHANGED')->count());
    }

    public function test_an_admin_without_the_vendor_unlock_cannot_change_the_mode(): void
    {
        $w = $this->world();
        $this->asUser($w['admin'])->putJson('/api/v1/sale-document-settings/mode', ['sale_document_mode' => 'SLIP'])->assertStatus(403);
        $this->assertSame(0, StoreSettings::count());
        $this->assertSame(0, AuditEvent::where('event_type', 'SALE_DOCUMENT_MODE_CHANGED')->count());
    }

    public function test_the_mode_cannot_change_while_a_shift_or_business_day_is_open_and_can_once_they_are_closed(): void
    {
        $w = $this->world();
        app(FiscalConfigurationLock::class)->unlock($w['storeId'], 60);

        $blocked = $this->asUser($w['admin'])->putJson('/api/v1/sale-document-settings/mode', ['sale_document_mode' => 'SLIP'])
            ->assertStatus(409)->assertJsonPath('error.code', 'SALE_DOCUMENT_MODE_CHANGE_BLOCKED');
        $this->assertSame(2, $blocked->json('error.details.open_shifts'));
        $this->assertSame(2, $blocked->json('error.details.open_fiscal_days'));
        $this->assertSame(0, StoreSettings::count(), 'a refused change creates nothing');
        $this->assertSame(0, AuditEvent::where('event_type', 'SALE_DOCUMENT_MODE_CHANGED')->count());

        // Closing the shifts is not enough: the business days are still open.
        foreach ([[$w['cashier'], 'enroll1', $w['cashierShift']], [$w['manager'], 'enroll2', $w['managerShift']]] as [$user, $enroll, $shift]) {
            $this->asUser($user, $w[$enroll])->postJson("/api/v1/shifts/{$shift->id}/close", ['declared_cash' => (string) $shift->opening_cash], $this->key())->assertOk();
        }
        $this->asUser($w['admin'])->putJson('/api/v1/sale-document-settings/mode', ['sale_document_mode' => 'SLIP'])
            ->assertStatus(409)->assertJsonPath('error.details.open_shifts', 0)->assertJsonPath('error.details.open_fiscal_days', 2);

        $this->asUser($w['manager'], $w['enroll1'])->postJson("/api/v1/fiscal-days/{$w['cashierShift']->fiscal_day_id}/close", [], $this->key())->assertOk();
        $this->asUser($w['manager'], $w['enroll2'])->postJson("/api/v1/fiscal-days/{$w['managerShift']->fiscal_day_id}/close", [], $this->key())->assertOk();

        $this->asUser($w['admin'])->putJson('/api/v1/sale-document-settings/mode', ['sale_document_mode' => 'SLIP'])->assertOk()->assertJson(['sale_document_mode' => 'SLIP', 'open_shifts' => 0, 'open_fiscal_days' => 0]);
        $this->assertSame(1, AuditEvent::where('event_type', 'SALE_DOCUMENT_MODE_CHANGED')->count());
    }

    public function test_a_store_that_switches_keeps_the_document_each_sale_was_made_with(): void
    {
        $w = $this->world();
        $before = $this->ring($w);
        $this->slipMode($w);
        $during = $this->ringAt($w);

        $this->assertSame('INVOICE', $before['document_mode']);
        $this->assertSame('SLIP', $during['document_mode']);
        $this->assertSame(1, DB::table('invoices')->where('sale_id', $before['id'])->count());
        $this->assertSame(0, DB::table('sale_slips')->where('sale_id', $before['id'])->count());
        $this->assertSame(0, DB::table('invoices')->where('sale_id', $during['id'])->count());
        $this->assertSame(1, DB::table('sale_slips')->where('sale_id', $during['id'])->count());

        // The invoice sale still prints its invoice, and the slip sale its slip.
        $this->asUser($w['cashier'])->getJson("/api/v1/invoices/{$before['invoice']['id']}")->assertOk();
        $this->asUser($w['cashier'])->getJson("/api/v1/sale-slips/{$during['slip']['id']}")->assertOk();
    }
}
