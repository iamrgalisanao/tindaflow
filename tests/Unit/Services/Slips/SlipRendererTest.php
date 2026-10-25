<?php

namespace Tests\Unit\Services\Slips;

use App\Services\Slips\SlipRenderer;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * ADR-016 section 7: the slip is a pure function of its snapshot (ADR-006), very small, and never says "invoice" or "official
 * receipt" -- the only free text on it is the store's own footer.
 */
class SlipRendererTest extends TestCase
{
    /** @return array<string, mixed> */
    private function snapshot(array $overrides = []): array
    {
        return $overrides + [
            'schema_version' => 1,
            'slip_number' => 'T-01-000007',
            'issued_at' => '2026-10-26T04:15:00+00:00',
            'store_name' => 'Canteen One',
            'terminal_code' => 'T-01',
            'cashier_name' => 'Ana Reyes',
            'items' => [
                ['line_number' => 1, 'description' => 'Standard Meal - Beef nilaga', 'quantity' => '1.000', 'unit_price' => '68.00', 'net_line_amount' => '68.00', 'is_addon' => false],
                ['line_number' => 2, 'description' => 'Whole rice', 'quantity' => '1.000', 'unit_price' => '15.00', 'net_line_amount' => '15.00', 'is_addon' => true],
                ['line_number' => 3, 'description' => 'Bottled water', 'quantity' => '3.000', 'unit_price' => '20.00', 'net_line_amount' => '60.00', 'is_addon' => false],
            ],
            'discount_total' => '0.00',
            'grand_total' => '143.00',
            'payments' => [['method' => 'CASH', 'amount' => '200.00', 'reference' => null]],
            'amount_tendered' => '200.00',
            'change' => '57.00',
            'footer' => null,
        ];
    }

    public function test_it_prints_store_number_date_cashier_lines_total_and_payment(): void
    {
        config(['app.timezone' => 'Asia/Manila']);
        $html = (new SlipRenderer)->render($this->snapshot());

        foreach (['Canteen One', 'T-01-000007', '2026-10-26 12:15', 'Ana Reyes', 'Standard Meal - Beef nilaga', '+ Whole rice', '3 x 20.00', '143.00', 'TOTAL', 'Cash', '200.00', 'Change', '57.00'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $this->assertStringContainsString('size:58mm', $html);
        $this->assertStringNotContainsString('REPRINT', $html);
    }

    public function test_it_never_says_invoice_or_official_receipt_and_prints_no_tax_buyer_or_registration_data(): void
    {
        $html = (new SlipRenderer)->render($this->snapshot(['tax_registration_type' => 'VAT', 'seller_tin' => '123', 'buyer_name' => 'Juan']));

        $this->assertStringNotContainsStringIgnoringCase('invoice', $html);
        $this->assertStringNotContainsStringIgnoringCase('official receipt', $html);
        foreach (['VAT', 'TIN', 'Juan', 'PTU', 'Accreditation', 'Sold to'] as $absent) {
            $this->assertStringNotContainsString($absent, $html);
        }
    }

    public function test_the_footer_is_printed_only_when_the_store_set_one_and_is_escaped(): void
    {
        $this->assertStringNotContainsString('class="c pre"', (new SlipRenderer)->render($this->snapshot()));

        $html = (new SlipRenderer)->render($this->snapshot(['footer' => "Salamat po!\n<b>x</b>"]));
        $this->assertStringContainsString('Salamat po!', $html);
        $this->assertStringContainsString('&lt;b&gt;x&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
    }

    public function test_a_reprint_is_marked_at_the_top_and_bottom_with_the_time_and_the_original_is_not(): void
    {
        config(['app.timezone' => 'Asia/Manila']);
        $copy = (new SlipRenderer)->render($this->snapshot(), Carbon::parse('2026-10-26 09:00:00', 'Asia/Manila'));

        $this->assertSame(2, substr_count($copy, 'REPRINT &mdash; COPY'));
        $this->assertStringContainsString('Reprinted 2026-10-26 09:00', $copy);
        $this->assertStringContainsString('T-01-000007', $copy);
        $this->assertStringNotContainsStringIgnoringCase('invoice', $copy);
        $this->assertSame(0, substr_count((new SlipRenderer)->render($this->snapshot()), 'REPRINT'));
    }

    public function test_a_discount_and_a_reference_are_shown_and_every_value_is_escaped(): void
    {
        $html = (new SlipRenderer)->render($this->snapshot([
            'store_name' => '<script>alert(1)</script>',
            'discount_total' => '10.00',
            'payments' => [['method' => 'GCASH', 'amount' => '133.00', 'reference' => 'GC-<1>']],
            'change' => '0.00',
        ]));

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('-10.00', $html);
        $this->assertStringContainsString('GCash', $html);
        $this->assertStringContainsString('Ref: GC-&lt;1&gt;', $html);
        $this->assertStringNotContainsString('Change', $html);
    }

    public function test_it_is_the_same_bytes_every_time_for_the_same_snapshot(): void
    {
        $this->assertSame((new SlipRenderer)->render($this->snapshot()), (new SlipRenderer)->render($this->snapshot()));
    }
}
