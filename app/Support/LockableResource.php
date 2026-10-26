<?php

namespace App\Support;

/**
 * The resource kinds named in architecture.md's Global Lock Order
 * (`shift -> fiscal_day -> employee -> override_grant -> sale -> sale_item (ascending) -> invoice_series -> slip_counter`).
 * Ordinal order matches acquisition order -- a lower case()->order()
 * value must always be locked before a higher one within one operation.
 */
enum LockableResource: string
{
    case Shift = 'shift';
    case FiscalDay = 'fiscal_day';
    // ADR-016 slice 3.2: the employee row a credit sale, void or refund charges or reverses, locked before the credit balance is read.
    case Employee = 'employee';
    // ADR-016 slice 3.3: the supervisor override grant a credit sale consumes (FOR UPDATE), taken after the employee and before the sale exists.
    case OverrideGrant = 'override_grant';
    case Sale = 'sale';
    case SaleItem = 'sale_item';
    case InvoiceSeries = 'invoice_series';
    // ADR-016 slice 2.2a: a terminal's slip counter row (SLIP mode only). Taken last; an operation never takes it together with InvoiceSeries.
    case SlipCounter = 'slip_counter';
    // ADR-016 slice 4.2: the billing party row (FOR UPDATE) that serialises recording or returning a cheque, so two cheques cannot over-allocate a period.
    // A cheque operation takes no other lock, so it sits last; it never runs inside a sale.
    case BillingParty = 'billing_party';
    // ADR-016 slice 4.2: the cheque row a return changes, taken after the party.
    case BillingCheque = 'billing_cheque';

    public function order(): int
    {
        return match ($this) {
            self::Shift => 0,
            self::FiscalDay => 1,
            self::Employee => 2,
            self::OverrideGrant => 3,
            self::Sale => 4,
            self::SaleItem => 5,
            self::InvoiceSeries => 6,
            self::SlipCounter => 7,
            self::BillingParty => 8,
            self::BillingCheque => 9,
        };
    }
}
