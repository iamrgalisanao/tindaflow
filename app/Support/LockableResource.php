<?php

namespace App\Support;

/**
 * The resource kinds named in architecture.md's Global Lock Order
 * (`shift -> fiscal_day -> employee -> sale -> sale_item (ascending) -> invoice_series -> slip_counter`).
 * Ordinal order matches acquisition order -- a lower case()->order()
 * value must always be locked before a higher one within one operation.
 */
enum LockableResource: string
{
    case Shift = 'shift';
    case FiscalDay = 'fiscal_day';
    // ADR-016 slice 3.2: the employee row a credit sale, void or refund charges or reverses, locked before the credit balance is read.
    case Employee = 'employee';
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
            self::Employee => 2,
            self::Sale => 3,
            self::SaleItem => 4,
            self::InvoiceSeries => 5,
            self::SlipCounter => 6,
        };
    }
}
