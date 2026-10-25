<?php

namespace App\Services\Slips;

use App\Models\SaleSlip;
use Carbon\CarbonInterface;
use LogicException;

/**
 * ADR-007's seam for the slip, like HtmlInvoicePrinter: dispatches on the snapshot's own `schema_version` to the renderer that
 * understands it. Printing happens strictly after the sale has committed, so a printing failure can never affect the sale or the slip.
 */
final class HtmlSlipPrinter
{
    public function __construct(private readonly SlipRenderer $renderer = new SlipRenderer) {}

    /** @param  CarbonInterface|null  $reprintedAt  null for the plain original; the moment of this reprint otherwise (puts REPRINT/COPY on the paper) */
    public function render(SaleSlip $slip, ?CarbonInterface $reprintedAt = null): string
    {
        $snapshot = $slip->slip_snapshot_json;
        $version = $snapshot['schema_version'] ?? null;

        return match ($version) {
            1 => $this->renderer->render($snapshot, $reprintedAt),
            default => throw new LogicException('No slip renderer exists for snapshot schema_version '.var_export($version, true)." (slip {$slip->id})."),
        };
    }
}
