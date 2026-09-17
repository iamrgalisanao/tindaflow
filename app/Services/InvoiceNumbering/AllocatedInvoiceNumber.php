<?php

namespace App\Services\InvoiceNumbering;

/** The result of one `InvoiceSeriesAllocator::allocateForStore()` call, ready to persist onto `invoice.invoice_number` verbatim. */
final readonly class AllocatedInvoiceNumber
{
    public function __construct(
        public string $invoiceSeriesId,
        public int $serial,
        public string $formattedNumber,
    ) {}
}
