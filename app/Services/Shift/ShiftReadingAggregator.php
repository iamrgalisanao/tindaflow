<?php

namespace App\Services\Shift;

use App\Domain\Money;
use App\Models\CashMovement;
use App\Models\Refund;
use App\Models\RefundSettlement;
use App\Models\Sale;
use App\Models\SaleVoid;
use App\Models\Shift;
use App\Services\Payments\NetCollectionService;

/**
 * Produces an XReadingTotalsSnapshot (openapi.yaml) for a shift, always
 * recomputed from the authoritative ledger (Sale/Payment/Refund/
 * CashMovement) -- invariant #40, "readings are reproducible, never
 * authoritative inputs." A VOIDED sale is excluded entirely from every
 * total here (its payment never happened, from a reporting point of
 * view) -- the same treatment Z-Reading gives it.
 */
final class ShiftReadingAggregator
{
    public function __construct(private readonly NetCollectionService $netCollection) {}

    /** @return array<string, mixed> */
    public function aggregate(Shift $shift, ?Money $declaredCash = null): array
    {
        // A closed shift reads AS OF ITS CLOSE: a sale voided after the shift closed (its business day still open) was the
        // shift's at close and stays in its reading; the void belongs to the voiding shift and day, through its own
        // fiscal_day_id/shift_id. So the reading stored at close can always be reproduced from the ledger (invariant #40, read
        // as "as of close": stage-23 addendum 8). An open shift has no closing instant, so any voided sale is out. Timestamps are stored
        // to whole seconds, so a void in the very second of the close counts as after it.
        $closedAt = $shift->status === 'CLOSED' ? $shift->closed_at : null;
        $saleIds = Sale::where('shift_id', $shift->id)
            ->where(function ($query) use ($closedAt) {
                $query->where('status', '!=', 'VOIDED');
                if ($closedAt !== null) {
                    $query->orWhereIn('id', SaleVoid::where('status', 'VOIDED')->where('resolved_at', '>=', $closedAt)->select('sale_id'));
                }
            })
            ->pluck('id');

        // What the sales collected NET of the change handed back: the stored payments are what was tendered, and
        // counting them as they are would put every peso of change into the drawer figure (NetTenderAllocator).
        $collected = $this->netCollection->forSales($saleIds->all())['totals'];

        $cashSales = $collected['CASH'] ?? Money::zero();
        $nonCashSales = Money::zero();
        foreach ($collected as $method => $amount) {
            if ($method !== 'CASH') {
                $nonCashSales = $nonCashSales->add($amount);
            }
        }

        $paymentBreakdown = array_map(fn (Money $amount): string => $amount->toApiString(), $collected);

        $refundsTotal = $this->sumMoney(
            Refund::where('shift_id', $shift->id)->where('status', 'COMPLETED')->sum('refund_total'),
        );

        $cashRefunds = $this->sumMoney(
            RefundSettlement::where('payment_method', 'CASH')
                ->whereIn('refund_id', Refund::where('shift_id', $shift->id)->where('status', 'COMPLETED')->pluck('id'))
                ->sum('amount'),
        );

        $cashInTotal = $this->sumMoney(CashMovement::where('shift_id', $shift->id)->where('type', 'CASH_IN')->sum('amount'));
        $cashOutTotal = $this->sumMoney(CashMovement::where('shift_id', $shift->id)->where('type', 'CASH_OUT')->sum('amount'));

        $openingCash = Money::fromApiString((string) $shift->opening_cash);
        $expectedCash = $openingCash->add($cashSales)->subtract($cashRefunds)->add($cashInTotal)->subtract($cashOutTotal);

        $transactionCount = $saleIds->count();

        return [
            'opening_cash' => $openingCash->toApiString(),
            'expected_cash' => $expectedCash->toApiString(),
            'cash_sales' => $cashSales->toApiString(),
            'non_cash_sales' => $nonCashSales->toApiString(),
            'payment_breakdown' => (object) $paymentBreakdown,
            'refunds_total' => $refundsTotal->toApiString(),
            'cash_in_total' => $cashInTotal->toApiString(),
            'cash_out_total' => $cashOutTotal->toApiString(),
            'variance' => $declaredCash === null ? null : $declaredCash->subtract($expectedCash)->toApiString(),
            'transaction_count' => $transactionCount,
        ];
    }

    private function sumMoney(mixed $rawSum): Money
    {
        return Money::fromApiString((string) ($rawSum ?: '0.00'));
    }
}
