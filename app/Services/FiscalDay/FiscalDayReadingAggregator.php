<?php

namespace App\Services\FiscalDay;

use App\Domain\Money;
use App\Models\FiscalDay;
use App\Models\Refund;
use App\Models\Sale;
use App\Models\SaleVoid;
use App\Models\ZReading;
use App\Services\Payments\NetCollectionService;

/**
 * Produces a ZReadingTotalsSnapshot (openapi.yaml, RMO 24-2023) for a
 * fiscal day, always recomputed from the authoritative ledger --
 * invariant #40. A VOIDED sale is excluded from every "sales" total
 * (`gross_sales` and its breakdown) and counted separately in
 * `void_total` instead.
 */
final class FiscalDayReadingAggregator
{
    public function __construct(private readonly NetCollectionService $netCollection) {}

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

        $priorReading = ZReading::where('terminal_id', $fiscalDay->terminal_id)->orderByDesc('z_counter')->first();
        $accumulatedBefore = Money::fromApiString(
            (string) ($priorReading?->totals_snapshot['accumulated_grand_total_sales_after'] ?? '0.00'),
        );
        $accumulatedAfter = $accumulatedBefore->add($grossSales);
        $zCounter = ZReading::where('terminal_id', $fiscalDay->terminal_id)->count() + 1;

        return [
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
    }

    private function sumMoney(mixed $rawSum): Money
    {
        return Money::fromApiString((string) ($rawSum ?: '0.00'));
    }
}
