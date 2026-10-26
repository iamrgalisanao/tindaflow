<?php

namespace App\Services\FiscalDay;

use App\Models\User;
use App\Models\ZReading;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * The Z-reading as a standalone, print-optimized HTML document for an 80mm thermal roll, like the invoice (ADR-007): fixed
 * narrow width, monospace, no colour, no scripts, every value escaped. A pure function of the stored reading, so the paper
 * always shows exactly what was fixed when the day closed -- the figures are read from `totals_snapshot` and never
 * recalculated. Only the shop name, the till's code and the name of the person who closed the day are looked up around it, to
 * say whose paper this is.
 *
 * The first printout (at the moment the day closes) is the plain original; every later one carries REPRINT -- COPY and the time,
 * so no unmarked duplicate can be made from history. Deliberately says nothing about legal wording (BIR-006 is parked).
 *
 * A day whose sales were slips (ADR-016 section 7) is the store's business day and this is its internal END OF DAY REPORT: the same
 * stored totals, but no Z-reading title or number, no tax lines (a slip sale carries no tax breakdown) and no accumulated-sales
 * counters. The reading itself is not touched; only what is printed from it changes. It prints on the 58mm roll of a slip-mode store and,
 * when the reading holds a `sales_by_meal_type` section (frozen at close, ADR-016 amendment 2026-10-26), adds SALES BY MEAL TYPE to it.
 */
final class ZReadingPrinter
{
    private const SALES_LINES = [
        'gross_sales' => 'Gross sales',
        'taxable_sales' => 'VATable sales',
        'vat_exempt_sales' => 'VAT-exempt sales',
        'zero_rated_sales' => 'Zero-rated sales',
        'non_vat_sales' => 'Non-VAT sales',
        'vat_amount' => 'VAT amount',
        'void_total' => 'Voids',
        'refund_total' => 'Refunds',
    ];

    /** The longest left-hand label (quantity and name) of a sales-by-meal-type line: with a nine-character amount it fills 28 characters, a 58mm roll. */
    private const NAME_WIDTH = 18;

    /** The lines that are tax figures; an end-of-day report (slip mode) leaves them out. */
    private const TAX_LINES = ['taxable_sales', 'vat_exempt_sales', 'zero_rated_sales', 'non_vat_sales', 'vat_amount'];

    public function render(ZReading $reading, ?CarbonInterface $reprintedAt = null): string
    {
        $totals = $reading->totals_snapshot ?? [];
        $number = $this->text((string) ($totals['z_counter'] ?? $reading->z_counter ?? ''));
        $till = $reading->terminal;
        $shop = $till?->store?->name;
        $terminal = $till?->terminal_code;
        $closedBy = $reading->generated_by ? User::find($reading->generated_by)?->name : null;
        $endOfDay = $till !== null && DayDocumentMode::of($reading->fiscal_day_id, $till->store_id)->isSlip();

        $html = [];
        $html[] = '<!doctype html><html lang="en"><head><meta charset="utf-8">';
        $html[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $html[] = $endOfDay ? "<title>End of day report {$number}</title>" : "<title>Z-Reading {$number}</title>";
        $html[] = '<style>'.($endOfDay ? $this->endOfDayStylesheet() : $this->stylesheet()).'</style></head><body><div class="doc">';

        if ($reprintedAt !== null) {
            $html[] = '<div class="mark">REPRINT &mdash; COPY</div>';
        }

        $html[] = '<div class="c">';
        if ($shop !== null && trim($shop) !== '') {
            $html[] = '<div class="b wrap">'.$this->text($shop).'</div>';
        }
        $html[] = $endOfDay ? '<div class="c b title">END OF DAY REPORT</div></div><div class="sep"></div>' : '<div class="c b title">Z-READING</div></div><div class="sep"></div>';

        $html[] = $this->row($endOfDay ? 'Report No.' : 'Z-Reading No.', $number);
        $html[] = $this->row('Business date', $this->text($reading->business_date?->toDateString() ?? ''));
        if ($terminal !== null) {
            $html[] = $this->row('Terminal', $this->text($terminal));
        }
        $html[] = $this->row('From', $this->text($this->dateTime($reading->from_at)));
        $html[] = $this->row('To', $this->text($this->dateTime($reading->to_at)));
        $html[] = $this->row('Closed', $this->text($this->dateTime($reading->generated_at)));
        if ($closedBy !== null) {
            $html[] = $this->row('Closed by', $this->text($closedBy));
        }
        if (! $endOfDay) {
            $html[] = $this->row('Reset counter', $this->text((string) ($totals['reset_counter'] ?? 0)));
        }

        $html[] = '<div class="sep"></div>';
        foreach (self::SALES_LINES as $key => $label) {
            if ($endOfDay && in_array($key, self::TAX_LINES, true)) {
                continue;
            }
            $html[] = $this->row($label, $this->money((string) ($totals[$key] ?? '0.00')));
        }

        $html[] = '<div class="sep"></div>';
        $html[] = '<div class="b">PAYMENTS</div>';
        $payments = (array) ($totals['payment_breakdown'] ?? []);
        if ($payments === []) {
            $html[] = '<div>No payments were taken.</div>';
        }
        foreach ($payments as $method => $amount) {
            $html[] = $this->row((string) $method, $this->money((string) $amount));
        }

        if ($endOfDay && is_array($totals['sales_by_meal_type'] ?? null)) {
            array_push($html, ...$this->salesByMealType($totals['sales_by_meal_type']));
        }

        if (! $endOfDay) {
            $html[] = '<div class="sep"></div>';
            $html[] = $this->row('Accumulated sales before', $this->money((string) ($totals['accumulated_grand_total_sales_before'] ?? '0.00')));
            $html[] = '<div class="row b"><span>Accumulated sales after</span><span>'.$this->money((string) ($totals['accumulated_grand_total_sales_after'] ?? '0.00')).'</span></div>';
        }
        $html[] = '<div class="sep"></div>';
        $html[] = '<div class="c legend">These figures were fixed when the business day closed.</div>';

        if ($reprintedAt !== null) {
            $html[] = '<div class="mark">REPRINT &mdash; COPY</div>';
            $html[] = '<div class="c legend">Reprinted '.$this->text($this->dateTime($reprintedAt)).'. Nothing in the reading was recalculated.</div>';
        }

        $html[] = '</div></body></html>';

        return implode('', $html);
    }

    /**
     * SALES BY MEAL TYPE (slip-mode days only), from the section frozen in the reading: a heading per meal type with its net amount, then one
     * compact line per item (quantity and name left, net amount right). Names are cut to NAME_WIDTH characters so a line never wraps on a
     * 58mm roll (about 28 characters), and no blank lines are added: paper is paid for. Items and types that netted to nothing (sold and
     * fully returned) are left off the paper but remain in the stored section. Ends with the bridge to the net sales total.
     *
     * @param  array<string, mixed>  $section
     * @return list<string>
     */
    private function salesByMealType(array $section): array
    {
        $out = ['<div class="sep"></div>', '<div class="b">SALES BY MEAL TYPE</div>'];

        foreach ((array) ($section['meal_types'] ?? []) as $type) {
            $items = array_values(array_filter((array) ($type['items'] ?? []), fn (array $item): bool => ! $this->isZero((string) ($item['quantity_net'] ?? '0')) || ! $this->isZero((string) ($item['net_amount'] ?? '0'))));
            if ($items === [] && $this->isZero((string) ($type['net_amount'] ?? '0'))) {
                continue;
            }
            $out[] = '<div class="row b"><span>'.$this->text($this->cut((string) ($type['meal_type'] ?? ''))).'</span><span>'.$this->amount((string) ($type['net_amount'] ?? '0.00')).'</span></div>';
            foreach ($items as $item) {
                $left = $this->quantity((string) ($item['quantity_net'] ?? '0')).' '.(($item['kind'] ?? '') === 'ADDON' ? '+' : '').($item['item'] ?? '');
                $out[] = '<div class="row in"><span>'.$this->text($this->cut($left)).'</span><span>'.$this->amount((string) ($item['net_amount'] ?? '0.00')).'</span></div>';
            }
        }

        $out[] = '<div class="sep"></div>';
        if (! $this->isZero((string) ($section['returned_amount'] ?? '0'))) {
            $out[] = $this->row('Sales', $this->amount((string) ($section['sold_amount'] ?? '0.00')));
            $out[] = $this->row('Refunds', $this->amount('-'.ltrim((string) $section['returned_amount'], '-')));
        }
        $out[] = '<div class="row b"><span>NET SALES</span><span>'.$this->amount((string) ($section['net_amount'] ?? '0.00')).'</span></div>';
        if (! $this->isZero((string) ($section['voided_amount'] ?? '0'))) {
            $out[] = $this->row('Voids (excl.)', $this->amount((string) $section['voided_amount']));
        }

        return $out;
    }

    /** Cuts a left-hand label to NAME_WIDTH characters, ending in "..." when it was longer. */
    private function cut(string $label): string
    {
        return mb_strimwidth($label, 0, self::NAME_WIDTH, '...');
    }

    private function isZero(string $value): bool
    {
        return bccomp($value, '0', 3) === 0;
    }

    /** "12.000" -> "12", "1.500" -> "1.5". */
    private function quantity(string $value): string
    {
        $trimmed = rtrim(rtrim(bcadd($value, '0', 3), '0'), '.');

        return $trimmed === '' || $trimmed === '-0' ? '0' : $trimmed;
    }

    /** "1234.5" -> "1,234.50" (the section's lines carry no currency prefix, to keep the line short). */
    private function amount(string $amount): string
    {
        $negative = str_starts_with($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-'), 2), 2, '00');

        return $this->text(($negative ? '-' : '').preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole).'.'.substr($fraction.'00', 0, 2));
    }

    /** The 58mm sheet an end-of-day report (slip mode) prints on, like the slip itself. The Z-reading of an invoice-mode day keeps the 80mm sheet. */
    private function endOfDayStylesheet(): string
    {
        return '@page{size:58mm auto;margin:2mm}'
            .'*{box-sizing:border-box}'
            .'body{margin:0;background:#fff;color:#000;font:11px/1.3 "Courier New",Courier,monospace}'
            .'.doc{width:50mm;max-width:100%;margin:0 auto;padding:1mm 0}'
            .'.c{text-align:center}.b{font-weight:bold}.title{letter-spacing:.1em;margin:2px 0}'
            .'.row{display:flex;justify-content:space-between;gap:6px}'
            .'.row span:last-child{text-align:right;white-space:nowrap}'
            .'.in{padding-left:1ch}'
            .'.wrap{overflow-wrap:anywhere}'
            .'.sep{border-top:1px dashed #000;margin:4px 0}'
            .'.legend{font-size:9px}'
            .'.mark{border:2px solid #000;text-align:center;font-weight:bold;letter-spacing:.1em;padding:2px 0;margin:4px 0}'
            .'@media print{body{-webkit-print-color-adjust:exact;print-color-adjust:exact}}';
    }

    private function stylesheet(): string
    {
        return '@page{size:80mm auto;margin:3mm}'
            .'*{box-sizing:border-box}'
            .'body{margin:0;background:#fff;color:#000;font:12px/1.35 "Courier New",Courier,monospace}'
            .'.doc{width:72mm;max-width:100%;margin:0 auto;padding:2mm 0}'
            .'.c{text-align:center}.b{font-weight:bold}.title{letter-spacing:.2em;margin:2px 0}'
            .'.row{display:flex;justify-content:space-between;gap:8px}'
            .'.row span:last-child{text-align:right;white-space:nowrap}'
            .'.wrap{overflow-wrap:anywhere}'
            .'.sep{border-top:1px dashed #000;margin:6px 0}'
            .'.legend{font-size:10px}'
            .'.mark{border:2px solid #000;text-align:center;font-weight:bold;letter-spacing:.15em;padding:3px 0;margin:6px 0}'
            .'@media print{body{-webkit-print-color-adjust:exact;print-color-adjust:exact}}';
    }

    private function row(string $left, string $right): string
    {
        return '<div class="row"><span>'.$this->text($left).'</span><span>'.$right.'</span></div>';
    }

    private function text(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** "1234.5" -> "PHP 1,234.50"; string arithmetic only, the amount is already a decimal string. */
    private function money(string $amount): string
    {
        $negative = str_starts_with($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-'), 2), 2, '00');
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);

        return $this->text(($negative ? '-' : '').'PHP '.$grouped.'.'.substr($fraction.'00', 0, 2));
    }

    private function dateTime(mixed $moment): string
    {
        if ($moment === null || $moment === '') {
            return '';
        }

        return Carbon::parse($moment)->timezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
