<?php

namespace App\Services\FiscalDay;

use App\Domain\Money;
use App\Models\FiscalDay;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\SaleVoid;
use App\Models\ZReading;
use App\Services\Payments\NetCollectionService;
use App\Services\Reports\SalesByMealTypeQuery;

/**
 * Produces a ZReadingTotalsSnapshot (openapi.yaml, RMO 24-2023) for a
 * fiscal day, always recomputed from the authoritative ledger --
 * invariant #40. A VOIDED sale is excluded from every "sales" total
 * (`gross_sales` and its breakdown) and counted separately in
 * `void_total` instead.
 *
 * A slip-mode day (ADR-016 section 7) additionally carries `sales_by_meal_type`, frozen here with the rest: see SalesByMealTypeQuery.
 */
final class FiscalDayReadingAggregator
{
    public function __construct(
        private readonly NetCollectionService $netCollection,
        private readonly SalesByMealTypeQuery $salesByMealType,
    ) {}

    /** @return array<string, mixed> */
    public function aggregate(FiscalDay $fiscalDay): array
    {
        $saleIds = Sale::where('fiscal_day_id', $fiscalDay->id)->where('status', '!=', 'VOIDED')->pluck('id');

        $grossSales = $this->sumMoney(Sale::whereIn('id', $saleIds)->sum('grand_total'));
        $taxableSales = $this->sumMoney(Sale::whereIn('id', $saleIds)->sum('taxable_sales'));
        $vatExemptSales = $this->sumMoney(Sale::whereIn('id', $saleIds)->sum('vat_exempt_sales'));
        $zeroRatedSales = $this->sumMoney(Sale::whereIn('id', $saleIds)->sum('zero_rated_sales'));
        $vatAmount = $this->sumMoney(Sale::whereIn('id', $saleIds)->sum('vat_amount'));
        $nonVatSales = $this->sumMoney(Sale::whereIn('id', $saleIds)->sum('non_vat_sales'));

        // Net of the change handed back, exactly as the shift readings count it (NetTenderAllocator).
        $paymentTotals = array_map(
            fn (Money $amount): string => $amount->toApiString(),
            $this->netCollection->forSales($saleIds->all())['totals'],
        );

        $voidedSaleIds = SaleVoid::where('fiscal_day_id', $fiscalDay->id)->where('status', 'VOIDED')->pluck('sale_id');
        $voidTotal = $this->sumMoney(Sale::whereIn('id', $voidedSaleIds)->sum('grand_total'));

        $refundTotal = $this->sumMoney(
            Refund::where('fiscal_day_id', $fiscalDay->id)->where('status', 'COMPLETED')->sum('refund_total'),
        );

        // This reading's own counter: a day that already has one keeps it (so aggregating a closed day again reproduces its
        // stored snapshot), a day still being closed takes the next number on this terminal.
        $zCounter = ZReading::where('fiscal_day_id', $fiscalDay->id)->value('z_counter')
            ?? ZReading::where('terminal_id', $fiscalDay->terminal_id)->count() + 1;

        // The accumulated grand total is derived from the ledger, never chained from the previous reading's stored
        // snapshot (invariants.md #40: readings are never inputs): the gross of every earlier closed day of this terminal.
        // A closed day's gross cannot change after it closes -- a sale can only be voided while its own fiscal day is open
        // (VoidService), and a refund never marks a sale VOIDED -- so no "as of close" logic is needed; later voids and
        // refunds land in the day they were executed in, through void_total and refund_total.
        $earlierDayIds = ZReading::where('terminal_id', $fiscalDay->terminal_id)->where('z_counter', '<', $zCounter)->pluck('fiscal_day_id');
        $accumulatedBefore = $this->sumMoney(
            Sale::whereIn('fiscal_day_id', $earlierDayIds)->where('status', '!=', 'VOIDED')->sum('grand_total'),
        );
        $accumulatedAfter = $accumulatedBefore->add($grossSales);

        $snapshot = [
            'accumulated_grand_total_sales_before' => $accumulatedBefore->toApiString(),
            'accumulated_grand_total_sales_after' => $accumulatedAfter->toApiString(),
            'gross_sales' => $grossSales->toApiString(),
            'taxable_sales' => $taxableSales->toApiString(),
            'vat_exempt_sales' => $vatExemptSales->toApiString(),
            'zero_rated_sales' => $zeroRatedSales->toApiString(),
            'vat_amount' => $vatAmount->toApiString(),
            'non_vat_sales' => $nonVatSales->toApiString(),
            'payment_breakdown' => (object) $paymentTotals,
            'void_total' => $voidTotal->toApiString(),
            'refund_total' => $refundTotal->toApiString(),
            'z_counter' => $zCounter,
            'reset_counter' => 0,
        ];

        // ADR-016 amendment 2026-10-26 (EOD-001): only a day of slips (the store's business day) carries the sales-by-meal-type section, and
        // only when the day has something to list. An invoice-mode day's snapshot has exactly the keys above, byte for byte, and a day closed
        // before this existed simply lacks the key.
        if (DayDocumentMode::of($fiscalDay->id, $fiscalDay->store_id)->isSlip()) {
            $section = $this->salesByMealType->forFiscalDay($fiscalDay->store_id, $fiscalDay->id);
            if ($section['meal_types'] !== []) {
                $snapshot['sales_by_meal_type'] = $section;
            }
        }

        return $snapshot;
    }

    private function sumMoney(mixed $rawSum): Money
    {
        return Money::fromApiString((string) ($rawSum ?: '0.00'));
    }
}
