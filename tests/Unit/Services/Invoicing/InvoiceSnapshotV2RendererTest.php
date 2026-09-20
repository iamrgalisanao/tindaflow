<?php

namespace Tests\Unit\Services\Invoicing;

use App\Models\Invoice;
use App\Services\Invoicing\HtmlInvoicePrinter;
use App\Services\Invoicing\Renderers\InvoiceSnapshotV1Renderer;
use App\Services\Invoicing\Renderers\InvoiceSnapshotV2Renderer;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Snapshot schema version 2 (stage 24, decision 4): version 1's document plus machine registration data, the payments
 * taken and an optional discount beneficiary. It prints what the snapshot recorded and never fills a gap, and it leaves
 * a version 1 invoice exactly as it was.
 */
class InvoiceSnapshotV2RendererTest extends TestCase
{
    /** @return array<string, mixed> */
    private function snapshot(array $overrides = []): array
    {
        return $overrides + [
            'schema_version' => 2,
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
            ],
            'subtotal' => '110.00',
            'discount_total' => '0.00',
            'taxable_sales' => '98.21',
            'vat_exempt_sales' => '0.00',
            'zero_rated_sales' => '0.00',
            'vat_amount' => '11.79',
            'non_vat_sales' => '0.00',
            'grand_total' => '110.00',
            'registration' => [
                'min' => '24010112345678901',
                'machine_serial_number' => 'SN-0042',
                'software_version' => '1.0.0',
                'ptu_number' => 'FP012024-045-0123456-00000',
                'ptu_date' => '2026-01-15',
                'accreditation_number' => '045-2024-00012',
                'accreditation_date' => '2024-03-01',
                'accreditation_valid_from' => '2024-03-01',
                'accreditation_valid_to' => '2029-02-28',
            ],
            'payments' => [['method' => 'CASH', 'amount' => '200.00']],
            'amount_tendered' => '200.00',
            'change' => '90.00',
            'discount_beneficiary' => null,
        ];
    }

    private function render(array $snapshot, ?Carbon $reprintedAt = null): string
    {
        return (new InvoiceSnapshotV2Renderer)->render($snapshot, $reprintedAt);
    }

    public function test_it_prints_the_registration_data_the_payments_and_the_change(): void
    {
        $html = $this->render($this->snapshot());

        // header: machine identification
        $this->assertStringContainsString('MIN: 24010112345678901 &middot; S/N: SN-0042', $html);
        // footer: authority to use and accreditation
        $this->assertStringContainsString('PTU No.: FP012024-045-0123456-00000 &middot; 2026-01-15', $html);
        $this->assertStringContainsString('Accreditation No.: 045-2024-00012 &middot; 2024-03-01 &middot; valid until 2029-02-28', $html);
        // payments under the total
        $this->assertStringContainsString('<span>CASH</span><span>PHP 200.00</span>', $html);
        $this->assertStringContainsString('<span>Change</span><span>PHP 90.00</span>', $html);
        // and the document itself is still the invoice
        $this->assertStringContainsString('Rice 1kg', $html);
        $this->assertStringContainsString('PHP 110.00', $html);
        $this->assertStringNotContainsString('Signature', $html, 'no beneficiary, no signature line');
    }

    public function test_the_registration_sits_in_the_header_the_payments_under_the_total_and_the_authority_before_the_store_footer(): void
    {
        $html = $this->render($this->snapshot(['invoice_footer' => 'Salamat po!']));

        $positions = [
            strpos($html, 'VAT REGISTERED'),
            strpos($html, 'MIN: '),
            strpos($html, 'INVOICE</div>'),
            strpos($html, '<span>TOTAL</span>'),
            strpos($html, '<span>CASH</span>'),
            strpos($html, 'V VATable'),
            strpos($html, 'PTU No.'),
            strpos($html, 'Salamat po!'),
        ];

        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'blocks appear in reading order');
    }

    public function test_it_never_fills_a_gap(): void
    {
        $html = $this->render($this->snapshot([
            'registration' => ['min' => null, 'machine_serial_number' => null, 'ptu_number' => null, 'accreditation_number' => null],
            'payments' => [],
            'change' => '0.00',
        ]));

        foreach (['MIN', 'S/N', 'PTU', 'Accreditation', 'CASH', 'Change', 'valid until'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, "{$absent} must not appear when there is nothing to print");
        }

        $none = $this->render($this->snapshot(['registration' => null]));
        $this->assertStringNotContainsString('PTU', $none);
    }

    public function test_a_partly_registered_terminal_prints_only_what_it_has(): void
    {
        $html = $this->render($this->snapshot(['registration' => ['min' => 'M-1', 'machine_serial_number' => null, 'ptu_number' => 'PTU-9', 'ptu_date' => null, 'accreditation_number' => null]]));

        $this->assertStringContainsString('MIN: M-1', $html);
        $this->assertStringNotContainsString('S/N', $html);
        $this->assertStringContainsString('PTU No.: PTU-9', $html);
        $this->assertStringNotContainsString('PTU No.: PTU-9 &middot;', $html);
        $this->assertStringNotContainsString('Accreditation', $html);
    }

    public function test_a_discount_beneficiary_prints_with_a_signature_line(): void
    {
        $html = $this->render($this->snapshot(['discount_beneficiary' => ['type' => 'Senior Citizen', 'name' => 'Lola Remedios', 'id_number' => 'SC-2026-0099', 'tin' => null]]));

        $this->assertStringContainsString('Discount: Senior Citizen', $html);
        $this->assertStringContainsString('Name: Lola Remedios', $html);
        $this->assertStringContainsString('ID No.: SC-2026-0099', $html);
        $this->assertStringNotContainsString('TIN: ', str_replace('TIN: 123-456-789-000', '', $html), 'an absent TIN is omitted');
        $this->assertStringContainsString('Signature: ____', $html);
    }

    public function test_everything_typed_by_a_person_is_escaped(): void
    {
        $html = $this->render($this->snapshot([
            'registration' => ['min' => '<script>alert(1)</script>', 'machine_serial_number' => '"><img src=x>', 'ptu_number' => '<b>1</b>'],
            'discount_beneficiary' => ['type' => '<i>x</i>', 'name' => '<script>evil()</script>', 'id_number' => "'; drop table"],
        ]));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('<b>1</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public function test_a_reprint_carries_the_mark_and_the_same_registration_data(): void
    {
        $html = $this->render($this->snapshot(), Carbon::parse('2026-09-19T08:30:00+00:00'));

        $this->assertSame(2, substr_count($html, 'REPRINT &mdash; COPY'));
        $this->assertStringContainsString('MIN: 24010112345678901', $html);
        $this->assertStringContainsString('PTU No.: FP012024-045-0123456-00000', $html);
    }

    public function test_the_same_input_always_renders_the_same_bytes(): void
    {
        $this->assertSame($this->render($this->snapshot()), $this->render($this->snapshot()));
    }

    public function test_a_version_2_snapshot_with_none_of_the_new_data_renders_exactly_like_version_1(): void
    {
        $bare = $this->snapshot(['registration' => null, 'payments' => [], 'change' => '0.00', 'amount_tendered' => '0.00']);

        $this->assertSame((new InvoiceSnapshotV1Renderer)->render($bare), $this->render($bare));
    }

    public function test_version_1_ignores_the_new_data_so_issued_invoices_never_change(): void
    {
        $v1 = (new InvoiceSnapshotV1Renderer)->render($this->snapshot(['schema_version' => 1]));

        foreach (['MIN', 'PTU', 'Accreditation', '<span>CASH</span>', 'Change'] as $absent) {
            $this->assertStringNotContainsString($absent, $v1);
        }
    }

    public function test_the_printer_hands_each_version_to_its_own_renderer(): void
    {
        $printer = new HtmlInvoicePrinter;

        $v2 = $printer->render(new Invoice(['invoice_snapshot_json' => $this->snapshot()]));
        $v1 = $printer->render(new Invoice(['invoice_snapshot_json' => $this->snapshot(['schema_version' => 1])]));

        $this->assertStringContainsString('MIN: 24010112345678901', $v2);
        $this->assertStringNotContainsString('MIN:', $v1);
    }
}
