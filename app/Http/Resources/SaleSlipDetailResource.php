<?php

namespace App\Http\Resources;

use App\Models\SaleSlip;
use App\Services\Slips\HtmlSlipPrinter;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;

/**
 * openapi.yaml SaleSlipDetail: SaleSlipSummary plus the sale and terminal it belongs to and `render_html`, rendered exclusively from
 * `slip_snapshot_json` (ADR-006). Without a reprint moment it is the plain original; with one (only ever from the reprint operation)
 * the paper carries the REPRINT/COPY mark. Nothing else differs between the two.
 *
 * @property SaleSlip $resource
 */
class SaleSlipDetailResource extends SaleSlipSummaryResource
{
    private ?CarbonInterface $reprintedAt = null;

    public static function reprinted(SaleSlip $slip, CarbonInterface $reprintedAt): self
    {
        $resource = new self($slip);
        $resource->reprintedAt = $reprintedAt;

        return $resource;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'sale_id' => $this->resource->sale_id,
            'terminal_id' => $this->resource->terminal_id,
            'render_html' => app(HtmlSlipPrinter::class)->render($this->resource, $this->reprintedAt),
        ];
    }
}
