<?php

namespace App\Services\Invoicing\Renderers;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Renders `invoice_snapshot_json` schema_version 1 (the shape CheckoutService writes) as a standalone,
 * print-optimized HTML document for an 80mm thermal roll: fixed narrow width, monospace, no colour, no
 * scripts. Every value comes from the snapshot and is escaped -- product names, buyer and seller
 * details are typed by people, and the result is displayed in a browser.
 *
 * Deliberately says nothing about legal wording (no "official receipt" or "valid for input tax" text):
 * which statements a BIR-registered invoice must carry is an open review item (BIR-006), not something
 * to invent here. The seller block prints what the snapshot recorded and omits what it did not.
 */
final class InvoiceSnapshotV1Renderer
{
    private const TAX_CODE = ['VATABLE' => 'V', 'VAT_EXEMPT' => 'E', 'ZERO_RATED' => 'Z', 'NON_VAT' => 'N'];

    /** @param  array<string, mixed>  $snapshot */
    public function render(array $snapshot, ?CarbonInterface $reprintedAt = null): string
    {
        $reprint = $reprintedAt !== null;
        $isVat = ($snapshot['tax_registration_type'] ?? 'VAT') === 'VAT';
        $number = $this->text($snapshot['invoice_number'] ?? '');

        $html = [];
        $html[] = '<!doctype html><html lang="en"><head><meta charset="utf-8">';
        $html[] = '<meta name="viewport" content="width=device-width, initial-scale=1">';
        $html[] = "<title>Invoice {$number}</title>";
        $html[] = '<style>'.$this->stylesheet().'</style></head><body><div class="doc">';

        if ($reprint) {
            $html[] = '<div class="mark">REPRINT &mdash; COPY</div>';
        }

        $html[] = '<div class="c">';
        if ($this->has($snapshot, 'seller_registered_name')) {
            $html[] = '<div class="b">'.$this->text($snapshot['seller_registered_name']).'</div>';
        }
        if ($this->has($snapshot, 'seller_address')) {
            $html[] = '<div class="wrap">'.$this->text($snapshot['seller_address']).'</div>';
        }
        if ($this->has($snapshot, 'seller_tin')) {
            $html[] = '<div>TIN: '.$this->text($snapshot['seller_tin']).'</div>';
        }
        $html[] = '<div>'.($isVat ? 'VAT REGISTERED' : 'NON-VAT REGISTERED').'</div>';
        $html[] = '</div><div class="sep"></div>';

        $html[] = '<div class="c b title">INVOICE</div>';
        $html[] = $this->row('Invoice No.', $number);
        $html[] = $this->row('Date', $this->text($this->dateTime($snapshot['issued_at'] ?? null)));
        if ($this->has($snapshot, 'terminal_code')) {
            $html[] = $this->row('Terminal', $this->text($snapshot['terminal_code']));
        }

        $buyer = array_filter([
            'Sold to' => $snapshot['buyer_name'] ?? null,
            'Address' => $snapshot['buyer_address'] ?? null,
            'TIN' => $snapshot['buyer_tin'] ?? null,
            'Business style' => $snapshot['buyer_business_style'] ?? null,
        ], fn ($value) => $value !== null && trim((string) $value) !== '');
        if ($buyer !== []) {
            $html[] = '<div class="sep"></div>';
            foreach ($buyer as $label => $value) {
                $html[] = '<div class="wrap">'.$this->text($label).': '.$this->text($value).'</div>';
            }
        }

        $html[] = '<div class="sep"></div>';
        foreach ($snapshot['items'] ?? [] as $item) {
            $code = self::TAX_CODE[$item['tax_classification'] ?? ''] ?? '';
            $html[] = '<div class="item"><div class="wrap">'.$this->text($item['product_name'] ?? '').'</div>';
            $html[] = '<div class="row"><span>'.$this->quantity($item['quantity'] ?? '0').' x '.$this->money($item['unit_price'] ?? '0.00').'</span><span>'
                .$this->money($item['net_line_amount'] ?? '0.00').($code !== '' ? ' '.$code : '').'</span></div></div>';
        }

        $html[] = '<div class="sep"></div>';
        $html[] = $this->row('Subtotal', $this->money($snapshot['subtotal'] ?? '0.00'));
        if ($this->positive($snapshot['discount_total'] ?? '0.00')) {
            $html[] = $this->row('Discounts', '-'.$this->money($snapshot['discount_total']));
        }
        if ($isVat) {
            $html[] = $this->row('VATable Sales', $this->money($snapshot['taxable_sales'] ?? '0.00'));
            $html[] = $this->row('VAT-Exempt Sales', $this->money($snapshot['vat_exempt_sales'] ?? '0.00'));
            $html[] = $this->row('Zero-Rated Sales', $this->money($snapshot['zero_rated_sales'] ?? '0.00'));
            $html[] = $this->row('VAT Amount', $this->money($snapshot['vat_amount'] ?? '0.00'));
        } else {
            $html[] = $this->row('Non-VAT Sales', $this->money($snapshot['non_vat_sales'] ?? '0.00'));
        }
        $html[] = '<div class="sep"></div>';
        $html[] = '<div class="row b total"><span>TOTAL</span><span>'.$this->money($snapshot['grand_total'] ?? '0.00').'</span></div>';
        $html[] = '<div class="sep"></div>';
        $html[] = '<div class="legend">V VATable &middot; E VAT-exempt &middot; Z Zero-rated &middot; N Non-VAT</div>';

        if ($reprint) {
            $html[] = '<div class="mark">REPRINT &mdash; COPY</div>';
            $html[] = '<div class="c legend">Reprinted '.$this->text($this->dateTime($reprintedAt->toIso8601String())).'. No new invoice number was issued.</div>';
        }

        $html[] = '</div></body></html>';

        return implode('', $html);
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
            .'.item{margin-bottom:3px;break-inside:avoid}'
            .'.sep{border-top:1px dashed #000;margin:6px 0}'
            .'.total{font-size:14px}'
            .'.legend{font-size:10px}'
            .'.mark{border:2px solid #000;text-align:center;font-weight:bold;letter-spacing:.15em;padding:3px 0;margin:6px 0}'
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

    /** "1234.5" -> "PHP 1,234.50" string arithmetic only; the amount is already a 2-place decimal string. */
    private function money(string $amount): string
    {
        $negative = str_starts_with($amount, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($amount, '-'), 2), 2, '00');
        $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);

        return $this->text(($negative ? '-' : '').'PHP '.$grouped.'.'.substr($fraction.'00', 0, 2));
    }

    private function quantity(string $value): string
    {
        return $this->text(rtrim(rtrim($value, '0'), '.') ?: '0');
    }

    private function dateTime(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '';
        }

        return Carbon::parse($iso)->timezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }
}
