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
    // ADR-016 slice 2.2a: a terminal's slip counter row (SLIP mode only). Taken last; an operation never takes it together with InvoiceSeries.
    case SlipCounter = 'slip_counter';

    public function order(): int
    {
        return match ($this) {
            self::Shift => 0,
            self::FiscalDay => 1,
            self::Sale => 2,
            self::SaleItem => 3,
            self::InvoiceSeries => 4,
            self::SlipCounter => 5,
        };
    }
}
