<?php

namespace App\Services\Slips;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * ADR-016 section 7: the slip as a standalone, print-optimized HTML document for a narrow 58mm thermal roll (thermal paper is
 * costly, so it carries the least that identifies the sale): store name, slip number, date and time, cashier, the lines (a meal and
 * its dish, add-ons indented), the total and how it was paid, then the store's own footer line if it set one. A pure function of the
 * stored snapshot (ADR-006): it never reads the database, so a reprint looks the same forever.
 *
 * It is not a sales invoice or an official receipt and never says so in words either way: it prints no tax breakdown, buyer block,
 * TIN, series or machine data, and the only free text is the store's own footer, whose wording is the store's decision.
 */
final class SlipRenderer
{
    private const METHODS = ['CASH' => 'Cash', 'GCASH' => 'GCash', 'MAYA' => 'Maya', 'CARD' => 'Card', 'OTHER' => 'Other', 'CREDIT' => 'CREDIT'];

    /** @param  array<string, mixed>  $snapshot */
    public function render(array $snapshot, ?CarbonInterface $reprintedAt = null): string
    {
        $number = $this->text($snapshot['slip_number'] ?? '');

        $html = [];
        $html[] = '<!doctype html><html lang="en"><head><meta charset="utf-8">';
        $html[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $html[] = "<title>Slip {$number}</title>";
        $html[] = '<style>'.$this->stylesheet().'</style></head><body><div class="doc">';

        if ($reprintedAt !== null) {
            $html[] = '<div class="mark">REPRINT &mdash; COPY</div>';
        }

        if ($this->has($snapshot, 'store_name')) {
            $html[] = '<div class="c b wrap">'.$this->text($snapshot['store_name']).'</div>';
        }
        $html[] = $this->row('Slip', $number);
        $html[] = $this->row('Date', $this->text($this->dateTime($snapshot['issued_at'] ?? null)));
        if ($this->has($snapshot, 'cashier_name')) {
            $html[] = $this->row('Cashier', $this->text($snapshot['cashier_name']));
        }
        $html[] = '<div class="sep"></div>';

        foreach ($snapshot['items'] ?? [] as $item) {
            $addOn = ! empty($item['is_addon']);
            $name = ($addOn ? '+ ' : '').$this->text($item['description'] ?? '');
            $quantity = $this->quantity((string) ($item['quantity'] ?? '1'));
            if ($quantity === '1') {
                $html[] = '<div class="row item"><span class="wrap'.($addOn ? ' in' : '').'">'.$name.'</span><span>'.$this->amount((string) ($item['net_line_amount'] ?? '0.00')).'</span></div>';
            } else {
                $html[] = '<div class="item"><div class="wrap'.($addOn ? ' in' : '').'">'.$name.'</div>';
                $html[] = '<div class="row in"><span>'.$quantity.' x '.$this->amount((string) ($item['unit_price'] ?? '0.00')).'</span><span>'
                    .$this->amount((string) ($item['net_line_amount'] ?? '0.00')).'</span></div></div>';
            }
        }

        $html[] = '<div class="sep"></div>';
        if ($this->positive((string) ($snapshot['discount_total'] ?? '0.00'))) {
            $html[] = $this->row('Discount', '-'.$this->amount((string) $snapshot['discount_total']));
        }
        $html[] = '<div class="row b total"><span>TOTAL</span><span>'.$this->amount((string) ($snapshot['grand_total'] ?? '0.00')).'</span></div>';

        foreach ($snapshot['payments'] ?? [] as $payment) {
            $html[] = $this->row($this->method((string) ($payment['method'] ?? '')), $this->amount((string) ($payment['amount'] ?? '0.00')));
            if ($this->has($payment, 'reference')) {
                $html[] = '<div class="legend wrap">Ref: '.$this->text($payment['reference']).'</div>';
            }
        }
        if ($this->positive((string) ($snapshot['change'] ?? '0.00'))) {
            $html[] = $this->row('Change', $this->amount((string) $snapshot['change']));
        }

        // A credit sale names who is charged: ADR-016 slice 3.2. The key exists only on such a slip.
        if (is_array($snapshot['employee'] ?? null)) {
            $html[] = '<div class="sep"></div>';
            if ($this->has($snapshot['employee'], 'name')) {
                $html[] = '<div class="wrap b">'.$this->text($snapshot['employee']['name']).'</div>';
            }
            if ($this->has($snapshot['employee'], 'code')) {
                $html[] = $this->row('Employee', $this->text($snapshot['employee']['code']));
            }
        }

        if ($this->has($snapshot, 'footer')) {
            $html[] = '<div class="sep"></div><div class="c pre">'.$this->text($snapshot['footer']).'</div>';
        }

        if ($reprintedAt !== null) {
            $html[] = '<div class="mark">REPRINT &mdash; COPY</div>';
            $html[] = '<div class="c legend">Reprinted '.$this->text($this->dateTime($reprintedAt->toIso8601String())).'</div>';
        }

        $html[] = '</div></body></html>';

        return implode('', $html);
    }

    private function stylesheet(): string
    {
        return '@page{size:58mm auto;margin:2mm}'
            .'*{box-sizing:border-box}'
            .'body{margin:0;background:#fff;color:#000;font:11px/1.3 "Courier New",Courier,monospace}'
            .'.doc{width:50mm;max-width:100%;margin:0 auto;padding:1mm 0}'
            .'.c{text-align:center}.b{font-weight:bold}'
            .'.row{display:flex;justify-content:space-between;gap:6px}'
            .'.row span:last-child{text-align:right;white-space:nowrap}'
            .'.wrap{overflow-wrap:anywhere}'
            .'.in{padding-left:2ch}'
            .'.pre{white-space:pre-line;overflow-wrap:anywhere}'
            .'.item{break-inside:avoid}'
            .'.sep{border-top:1px dashed #000;margin:4px 0}'
            .'.total{font-size:13px}'
            .'.legend{font-size:9px}'
            .'.mark{border:2px solid #000;text-align:center;font-weight:bold;letter-spacing:.1em;padding:2px 0;margin:4px 0}'
            .'@media print{body{-webkit-print-color-adjust:exact;print-color-adjust:exact}}';
    }

    private function row(string $left, string $right): string
    {
        return '<div class="row"><span>'.$this->text($left).'</span><span>'.$right.'</span></div>';
    }

    /** @param  array<string, mixed>  $snapshot */
    private function has(array $snapshot, string $key): bool
    {
        return isset($snapshot[$key]) && trim((string) $snapshot[$key]) !== '';
    }

    private function text(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function positive(string $money): bool
    {
        return bccomp($money, '0', 2) === 1;
    }

    private function method(string $method): string
    {
        return $this->text(self::METHODS[$method] ?? ucfirst(strtolower(str_replace('_', ' ', $method))));
    }

    /** "1234.5" -> "1,234.50"; string arithmetic only, the amount is already a decimal string. */
    private function amount(string $amount): string
    {
        $negative = str_starts_with($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-'), 2), 2, '00');
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);

        return $this->text(($negative ? '-' : '').$grouped.'.'.substr($fraction.'00', 0, 2));
    }

    private function quantity(string $value): string
    {
        return $this->text(str_contains($value, '.') ? (rtrim(rtrim($value, '0'), '.') ?: '0') : $value);
    }

    private function dateTime(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '';
        }

        return Carbon::parse($iso)->timezone(config('app.timezone'))->format('Y-m-d H:i');
    }
}
