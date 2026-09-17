<?php

namespace App\Support;

/**
 * The five resource kinds named in architecture.md's Global Lock Order
 * (`shift -> fiscal_day -> sale -> sale_item (ascending) -> invoice_series`).
 * Ordinal order matches acquisition order -- a lower case()->order()
 * value must always be locked before a higher one within one operation.
 */
enum LockableResource: string
{
    case Shift = 'shift';
    case FiscalDay = 'fiscal_day';
    case Sale = 'sale';
    case SaleItem = 'sale_item';
    case InvoiceSeries = 'invoice_series';

    public function order(): int
    {
        return match ($this) {
            self::Shift => 0,
            self::FiscalDay => 1,
            self::Sale => 2,
            self::SaleItem => 3,
            self::InvoiceSeries => 4,
        };
    }
}
