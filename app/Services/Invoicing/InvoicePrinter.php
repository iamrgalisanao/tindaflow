<?php

namespace App\Services\Invoicing;

use App\Models\Invoice;
use Carbon\CarbonInterface;

/**
 * ADR-007's seam: given an invoice, produce something printable. Today there is one implementation
 * (print-optimized HTML for an 80mm roll, sent to the browser's print dialog); an ESC/POS or
 * cash-drawer implementation can be added behind this interface without touching Sale, Invoice or
 * checkout. Printing happens strictly after the sale has committed, so a printing failure can never
 * affect the sale or the invoice.
 */
interface InvoicePrinter
{
    /**
     * @param  CarbonInterface|null  $reprintedAt  null for the plain original; the moment of this reprint
     *                                             otherwise, which puts the visible REPRINT/COPY mark on the output (invariant #15)
     */
    public function render(Invoice $invoice, ?CarbonInterface $reprintedAt = null): string;
}
