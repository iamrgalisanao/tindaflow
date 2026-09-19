<?php

namespace App\Http\Resources;

use App\Models\Invoice;
use App\Services\Invoicing\InvoicePrinter;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

/**
 * openapi.yaml InvoiceDetail: InvoiceSummary plus the snapshot-derived identity fields and `render_html`,
 * rendered exclusively from `invoice_snapshot_json` (ADR-006). Without a reprint moment it is the plain
 * original; with one (only ever from the reprint operation) the document carries the REPRINT/COPY mark.
 * Nothing else about the invoice differs between the two.
 *
 * @property Invoice $resource
 */
class InvoiceDetailResource extends InvoiceSummaryResource
{
    private ?CarbonInterface $reprintedAt = null;

    public static function reprinted(Invoice $invoice, CarbonInterface $reprintedAt): self
    {
        $resource = new self($invoice);
        $resource->reprintedAt = $reprintedAt;

        return $resource;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'sale_id' => $this->resource->sale_id,
            'invoice_series_id' => $this->resource->invoice_series_id,
            'terminal_id' => $this->resource->terminal_id,
            'fiscal_installation_id' => $this->resource->fiscal_installation_id,
            'seller_registered_name_snapshot' => $this->resource->seller_registered_name_snapshot,
            'tax_registration_type_snapshot' => $this->resource->tax_registration_type_snapshot,
            'terminal_code_snapshot' => $this->resource->terminal_code_snapshot,
            'render_html' => app(InvoicePrinter::class)->render($this->resource, $this->reprintedAt),
        ];
    }
}
