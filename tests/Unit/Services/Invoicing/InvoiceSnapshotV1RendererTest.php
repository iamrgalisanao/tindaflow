<?php

namespace Tests\Unit\Services\Invoicing;

use App\Models\Invoice;
use App\Services\Invoicing\HtmlInvoicePrinter;
use App\Services\Invoicing\Renderers\InvoiceSnapshotV1Renderer;
use Carbon\Carbon;
use LogicException;
use Tests\TestCase;

/** ADR-006/007: a renderer is a pure function of (snapshot, reprint moment) and output is safe to show. */
class InvoiceSnapshotV1RendererTest extends TestCase
{
    /** @return array<string, mixed> */
    private function snapshot(array $overrides = []): array
    {
        return $overrides + [
            'schema_version' => 1,
            'invoice_number' => '000123',
            'issued_at' => '2026-09-18T13:13:49+00:00',
            'seller_registered_name' => 'Aling Nena Sari-Sari Store',
            'seller_tin' => '123-456-789-000',
            'seller_address' => '12 Rizal St., Quezon City',
            'tax_registration_type' => 'VAT',
            'terminal_code' => 'T-01',
            'buyer_name' => null,
            'buyer_address' => null,
            'buyer_tin' => null,
            'buyer_business_style' => null,
            'items' => [
                ['line_number' => 1, 'product_name' => 'Rice 1kg', 'quantity' => '2.000', 'unit_price' => '55.00', 'net_line_amount' => '110.00', 'tax_classification' => 'VATABLE', 'tax_amount' => '11.79'],
                ['line_number' => 2, 'product_name' => 'Eggs (tray)', 'quantity' => '0.500', 'unit_price' => '1200.00', 'net_line_amount' => '600.00', 'tax_classification' => 'VAT_EXEMPT', 'tax_amount' => '0.00'],
            ],
            'subtotal' => '710.00',
            'discount_total' => '0.00',
            'taxable_sales' => '98.21',
            'vat_exempt_sales' => '600.00',
            'zero_rated_sales' => '0.00',
            'vat_amount' => '11.79',
            'non_vat_sales' => '0.00',
            'grand_total' => '710.00',
        ];
    }

    private function render(array $snapshot, ?Carbon $reprintedAt = null): string
    {
        return (new InvoiceSnapshotV1Renderer)->render($snapshot, $reprintedAt);
    }

    public function test_the_original_shows_the_issued_document_without_a_reprint_mark(): void
    {
        $html = $this->render($this->snapshot());

        $this->assertStringStartsWith('<!doctype html>', $html);
        foreach (['Aling Nena Sari-Sari Store', 'TIN: 123-456-789-000', '12 Rizal St., Quezon City', 'VAT REGISTERED', 'Invoice No.', '000123', 'T-01'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $this->assertStringContainsString('Rice 1kg', $html);
        $this->assertStringContainsString('2 x PHP 55.00', $html);
        $this->assertStringContainsString('0.5 x PHP 1,200.00', $html);
        $this->assertStringContainsString('PHP 110.00 V', $html);
        $this->assertStringContainsString('PHP 600.00 E', $html);
        $this->assertStringContainsString('VAT-Exempt Sales', $html);
        $this->assertStringContainsString('TOTAL', $html);
        $this->assertStringContainsString('PHP 710.00', $html);
        $this->assertStringNotContainsString('REPRINT', $html);
        $this->assertStringNotContainsString('Discounts', $html);
    }

    public function test_a_reprint_carries_the_visible_mark_and_its_own_moment_but_the_same_document(): void
    {
        $original = $this->render($this->snapshot());
        $reprint = $this->render($this->snapshot(), Carbon::parse('2026-09-19T08:30:00+00:00'));

        $this->assertSame(2, substr_count($reprint, 'REPRINT &mdash; COPY'));
        $this->assertStringContainsString('Reprinted 2026-09-19 08:30:00', $reprint);
        $this->assertStringContainsString('No new invoice number was issued', $reprint);
        // Everything that is not the mark is identical to the original: same number, lines, totals.
        foreach (['000123', 'Rice 1kg', 'PHP 710.00', '2026-09-18 13:13:49'] as $expected) {
            $this->assertStringContainsString($expected, $original);
            $this->assertStringContainsString($expected, $reprint);
        }
    }

    public function test_the_same_input_always_renders_the_same_bytes(): void
    {
        $at = Carbon::parse('2026-09-19T08:30:00+00:00');

        $this->assertSame($this->render($this->snapshot(), $at), $this->render($this->snapshot(), $at));
        $this->assertSame($this->render($this->snapshot()), $this->render($this->snapshot()));
    }

    public function test_every_typed_value_is_escaped_and_no_script_can_appear(): void
    {
        $html = $this->render($this->snapshot([
            'seller_registered_name' => '<script>alert(1)</script> & Sons',
            'buyer_name' => '"><img src=x onerror=alert(1)>',
            'buyer_tin' => "1'2",
            'items' => [['line_number' => 1, 'product_name' => '<b onmouseover=alert(1)>Rice</b>', 'quantity' => '1', 'unit_price' => '1.00', 'net_line_amount' => '1.00', 'tax_classification' => 'VATABLE', 'tax_amount' => '0.11']],
        ]));

        $this->assertStringNotContainsString('<script', strtolower($html));
        $this->assertStringNotContainsString('<img', strtolower($html));
        $this->assertStringNotContainsString('<b onmouseover', strtolower($html));
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; &amp; Sons', $html);
        $this->assertStringContainsString('&lt;b onmouseover=alert(1)&gt;Rice&lt;/b&gt;', $html);
        $this->assertStringContainsString('1&#039;2', $html);
    }

    public function test_buyer_details_and_discounts_appear_only_when_present(): void
    {
        $plain = $this->render($this->snapshot());
        $this->assertStringNotContainsString('Sold to', $plain);

        $with = $this->render($this->snapshot(['buyer_name' => 'Juan Dela Cruz', 'buyer_tin' => '999-888-777', 'discount_total' => '25.50']));
        $this->assertStringContainsString('Sold to: Juan Dela Cruz', $with);
        $this->assertStringContainsString('TIN: 999-888-777', $with);
        $this->assertStringContainsString('-PHP 25.50', $with);
    }

    public function test_missing_seller_details_are_omitted_not_invented(): void
    {
        $html = $this->render($this->snapshot(['seller_registered_name' => null, 'seller_tin' => null, 'seller_address' => null]));

        $this->assertStringNotContainsString('TIN:', $html);
        $this->assertStringNotContainsString('null', strtolower($html));
        $this->assertStringContainsString('VAT REGISTERED', $html);
    }

    public function test_a_non_vat_registration_shows_the_non_vat_breakdown_only(): void
    {
        $html = $this->render($this->snapshot([
            'tax_registration_type' => 'NON_VAT', 'taxable_sales' => '0.00', 'vat_exempt_sales' => '0.00', 'vat_amount' => '0.00', 'non_vat_sales' => '710.00',
        ]));

        $this->assertStringContainsString('NON-VAT REGISTERED', $html);
        $this->assertStringContainsString('Non-VAT Sales', $html);
        $this->assertStringNotContainsString('VAT Amount', $html);
        $this->assertStringNotContainsString('VATable Sales', $html);
    }

    public function test_it_is_laid_out_for_an_80mm_roll_and_carries_no_script(): void
    {
        $html = $this->render($this->snapshot());

        $this->assertStringContainsString('size:80mm', $html);
        $this->assertStringContainsString('width:72mm', $html);
        $this->assertStringContainsString('Courier', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_an_unknown_snapshot_version_is_refused_rather_than_guessed(): void
    {
        $invoice = new Invoice(['invoice_snapshot_json' => ['schema_version' => 99]]);

        $this->expectException(LogicException::class);

        (new HtmlInvoicePrinter)->render($invoice);
    }
}
