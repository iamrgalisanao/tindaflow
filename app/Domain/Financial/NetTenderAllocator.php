<?php

namespace App\Domain\Financial;

use App\Domain\Money;

/**
 * What a sale actually collected, per payment, once the change handed back is taken out.
 *
 * A `payment` row records what the customer TENDERED (invariant #8: SUM(payment.amount) >= grand_total, and the
 * stored rows are immutable), so a P200.00 note on a P107.00 sale is stored as CASH 200.00 and the P93.00 change is
 * only ever derived (sale detail, invoice snapshot). Every figure that answers "how much did this sale bring in"
 * -- the shift's expected drawer cash, X- and Z-reading payment breakdowns, the payment-method report -- must
 * therefore use the amount NET of change, or it overstates the drawer by every peso given back
 * (docs/06-backend/stage-9-shift-close-fiscal-day-close.md, "Change is not collected").
 *
 * Rule: change is handed back in cash, so it comes off the CASH tender first (never below zero). Change that is
 * larger than all the cash tendered means a non-cash method was over-tendered; that remainder comes off the
 * non-cash payments starting from the last one listed, so the sale's payments always add up to exactly its
 * grand total. Only subtraction on already-rounded Money is used: no new rounding point exists here.
 */
final class NetTenderAllocator
{
    private const CASH = 'CASH';

    /**
     * @param  list<array{method: string, amount: Money}>  $payments  in the order they were recorded
     * @return list<Money> the net collected per payment, in the same order and with the same keys
     */
    public function allocate(Money $grandTotal, array $payments): array
    {
        $tendered = Money::zero();
        foreach ($payments as $payment) {
            $tendered = $tendered->add($payment['amount']);
        }

        $change = $tendered->greaterThan($grandTotal) ? $tendered->subtract($grandTotal) : Money::zero();

        $net = array_map(fn (array $payment): Money => $payment['amount'], $payments);

        $order = array_merge(
            array_keys(array_filter($payments, fn (array $payment): bool => $payment['method'] === self::CASH)),
            array_reverse(array_keys(array_filter($payments, fn (array $payment): bool => $payment['method'] !== self::CASH))),
        );

        foreach ($order as $index) {
            if ($change->isZero()) {
                break;
            }
            $taken = $net[$index]->lessThan($change) ? $net[$index] : $change;
            $net[$index] = $net[$index]->subtract($taken);
            $change = $change->subtract($taken);
        }

        return $net;
    }
}
