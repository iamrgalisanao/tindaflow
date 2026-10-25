<?php

namespace Tests\Database;

use App\Models\Invoice;
use App\Services\Invoicing\InvoicePrinter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Database\Concerns\BuildsSalesScenario;

/**
 * ADR-016 slice 2.2a, SLIP-001: INVOICE mode is the default and its path is byte-for-byte what it was before slip mode existed.
 *
 * tests/Database/fixtures/invoice_mode_baseline.json was captured from the checkout BEFORE the slip-mode change was made (commit
 * eb1baa3): the invoice snapshot, the printed HTML, the journal entry, the audit event and every money column of the sale, its
 * lines and payments, for one fixed sale at a fixed moment. This test rings the same sale again and compares. If a later change
 * alters anything an invoice-mode store prints or stores, this fails. Regenerate the fixture only for a deliberate, owner-approved
 * change to the invoice document itself (CAPTURE_INVOICE_FIXTURE=1).
 */
class InvoiceModeUnchangedTest extends PostgresSchemaTestCase
{
    use BuildsSalesScenario;

    private const FIXTURE = __DIR__.'/fixtures/invoice_mode_baseline.json';

    /** @return array<string, mixed> */
    private function captureOneFixedSale(): array
    {
        $w = $this->world();
        $w['t1']->update(['terminal_code' => 'T-01']);
        $w['product']->update(['name' => 'Fixed Product A', 'sku' => 'FIX-A', 'barcode' => '4800000000017']);
        $w['product2']->update(['name' => 'Fixed Product B', 'sku' => 'FIX-B', 'barcode' => '4800000000024']);
        DB::table('fiscal_installations')->where('store_id', $w['storeId'])->update(['machine_serial_number' => 'SN-0001']);
        DB::table('store_settings')->insert([
            'store_id' => $w['storeId'], 'business_name' => 'Fixed Store', 'registered_name' => 'Fixed Store Inc.', 'business_address' => '1 Fixed St',
            'tin' => '123-456-789-000', 'branch_code' => '00000', 'invoice_header' => 'Header line', 'invoice_footer' => 'Footer line',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->travelTo(Carbon::parse('2026-10-26 09:30:00', config('app.timezone')));
        $sale = $this->asUser($w['cashier'], $w['enroll1'])->postJson('/api/v1/sales', [
            'items' => [
                ['product_id' => $w['product']->id, 'quantity' => '2'],
                ['product_id' => $w['product2']->id, 'quantity' => '1'],
            ],
            'payments' => [['method' => 'CASH', 'amount' => '200.00'], ['method' => 'GCASH', 'amount' => '100.00', 'external_reference' => 'REF-1']],
            'buyer_name' => 'Juan Dela Cruz',
        ], $this->key())->assertStatus(201)->json();

        $saleId = $sale['id'];
        $invoice = DB::table('invoices')->where('sale_id', $saleId)->first();
        $normalise = fn (mixed $value) => json_decode(str_replace($saleId, '<SALE>', json_encode($value)), true);

        $columns = fn (string $table, array $keys, array $where) => array_map(
            fn ($row) => array_intersect_key((array) $row, array_flip($keys)),
            DB::table($table)->where($where)->orderBy($table === 'sale_items' ? 'line_number' : 'id')->get()->all(),
        );

        return [
            'invoice_snapshot' => $normalise(json_decode($invoice->invoice_snapshot_json, true)),
            'invoice_row' => array_intersect_key((array) $invoice, array_flip(['invoice_number', 'seller_registered_name_snapshot', 'tax_registration_type_snapshot', 'terminal_code_snapshot'])),
            'invoice_html' => app(InvoicePrinter::class)->render(Invoice::find($invoice->id)),
            'journal_payload' => $normalise(json_decode(DB::table('electronic_journal_entries')->where('source_type', 'invoice')->where('source_id', $invoice->id)->value('payload_json'), true)),
            'audit_after_metadata' => $normalise(json_decode(DB::table('audit_events')->where('event_type', 'SALE_FINALIZED')->where('entity_id', $saleId)->value('after_metadata'), true)),
            'sale' => array_intersect_key((array) DB::table('sales')->where('id', $saleId)->first(), array_flip([
                'subtotal', 'order_level_discount_amount', 'discount_total', 'taxable_sales', 'vat_exempt_sales', 'zero_rated_sales', 'vat_amount',
                'non_vat_sales', 'grand_total', 'status', 'buyer_name', 'sold_at',
            ])),
            'sale_items' => $columns('sale_items', ['line_number', 'product_name_snapshot', 'sku_snapshot', 'quantity', 'unit_price_snapshot', 'gross_line_amount', 'net_line_amount', 'tax_classification_snapshot', 'tax_rate_snapshot', 'taxable_base', 'tax_amount'], ['sale_id' => $saleId]),
            'payments' => $columns('payments', ['method', 'amount', 'reference_note'], ['sale_id' => $saleId]),
            'stock_movements' => DB::table('stock_movements')->where('movement_type', 'SALE')->count(),
            'electronic_journal_rows_for_the_sale' => DB::table('electronic_journal_entries')->whereIn('source_id', [$invoice->id])->count(),
            'sale_response_invoice_number' => $sale['invoice_number'],
        ];
    }

    public function test_an_invoice_mode_sale_is_byte_for_byte_what_it_was_before_slip_mode(): void
    {
        $captured = $this->captureOneFixedSale();

        if (getenv('CAPTURE_INVOICE_FIXTURE') === '1') {
            file_put_contents(self::FIXTURE, json_encode($captured, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            $this->markTestSkipped('Fixture captured.');
        }

        $expected = json_decode((string) file_get_contents(self::FIXTURE), true);

        $this->assertSame($expected['invoice_html'], $captured['invoice_html'], 'the printed invoice changed');
        $this->assertSame($expected['invoice_snapshot'], $captured['invoice_snapshot'], 'the invoice snapshot changed');
        $this->assertEquals($expected, $captured);
    }

    public function test_the_default_mode_is_invoice_and_a_sale_in_it_has_an_invoice_and_no_slip(): void
    {
        $w = $this->world();
        $sale = $this->ring($w);

        $this->assertSame(1, DB::table('invoices')->where('sale_id', $sale['id'])->count());
        $this->assertSame('INVOICE', DB::table('sales')->where('id', $sale['id'])->value('document_mode'));
        $this->assertSame(0, DB::table('sale_slips')->where('sale_id', $sale['id'])->count());
        $this->assertSame(0, DB::table('slip_counters')->count());
    }
}
