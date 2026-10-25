<?php

namespace App\Services\Slips;

use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Support\Collection;

/**
 * ADR-016 section 7: what a slip prints, copied at the moment of the sale into `sale_slips.slip_snapshot_json` (schema_version 1) so
 * a reprint reads only this and never today's settings, menu or prices (ADR-006). The same figures the sale was finalized with;
 * nothing is recalculated here.
 */
final class SlipSnapshotBuilder
{
    /**
     * @param  Collection<int, SaleItem>  $saleItems
     * @param  list<array<string, mixed>>  $payments  the request's payments, as accepted
     * @return array<string, mixed>
     */
    public function build(Sale $sale, Collection $saleItems, array $payments, string $slipNumber, string $terminalCode, string $storeName, string $cashierName, ?string $footer): array
    {
        $tendered = array_reduce($payments, fn (string $carry, array $payment) => bcadd($carry, (string) $payment['amount'], 2), '0.00');

        return [
            'schema_version' => 1,
            'sale_id' => $sale->id,
            'slip_number' => $slipNumber,
            'issued_at' => $sale->sold_at->toIso8601String(),
            'store_name' => $storeName,
            'terminal_code' => $terminalCode,
            'cashier_name' => $cashierName,
            'items' => $saleItems->map(fn (SaleItem $item) => [
                'line_number' => $item->line_number,
                'description' => $item->product_name_snapshot,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price_snapshot,
                'net_line_amount' => $item->net_line_amount,
                // An add-on line (rice with a meal) hangs under its meal on the paper.
                'is_addon' => $item->parent_line_number !== null,
            ])->all(),
            'discount_total' => $sale->discount_total,
            'grand_total' => $sale->grand_total,
            'payments' => array_map(fn (array $payment) => [
                'method' => $payment['method'],
                'amount' => (string) $payment['amount'],
                'reference' => $payment['external_reference'] ?? null,
            ], $payments),
            'amount_tendered' => $tendered,
            'change' => bccomp($tendered, (string) $sale->grand_total, 2) === 1 ? bcsub($tendered, (string) $sale->grand_total, 2) : '0.00',
            'footer' => $footer,
        ];
    }
}
