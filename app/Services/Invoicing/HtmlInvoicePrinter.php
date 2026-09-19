<?php

namespace App\Services\Invoicing;

use App\Models\Invoice;
use App\Services\Invoicing\Renderers\InvoiceSnapshotV1Renderer;
use Carbon\CarbonInterface;
use LogicException;

/**
 * ADR-006: dispatches on the snapshot's own `schema_version` to the renderer that understands it. A
 * renderer is a pure function of (snapshot, reprint moment) -> HTML and never reads the database, so an
 * invoice looks the same forever regardless of later settings, prices or code changes. When the snapshot
 * shape ever changes it ships as a new version with its own renderer; the old one stays for every invoice
 * already issued in that shape.
 */
final class HtmlInvoicePrinter implements InvoicePrinter
{
    public function render(Invoice $invoice, ?CarbonInterface $reprintedAt = null): string
    {
        $snapshot = $invoice->invoice_snapshot_json;
        $version = $snapshot['schema_version'] ?? null;

        return match ($version) {
            1 => (new InvoiceSnapshotV1Renderer)->render($snapshot, $reprintedAt),
            default => throw new LogicException('No invoice renderer exists for snapshot schema_version '.var_export($version, true)." (invoice {$invoice->id})."),
        };
    }
}
