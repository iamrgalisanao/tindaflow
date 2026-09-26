<?php

namespace App\Services\Payments;

use App\Domain\Financial\NetTenderAllocator;
use App\Domain\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The one place that turns stored payment rows (amounts TENDERED) into what the sales actually collected, by
 * payment method, net of the change given back -- see NetTenderAllocator. The shift X-reading and shift close,
 * the fiscal-day Z-reading and the sales-by-payment-method report all read it, so they can never disagree with
 * each other about how much cash a sale left in the drawer.
 */
final class NetCollectionService
{
    public function __construct(private readonly NetTenderAllocator $allocator) {}

    /**
     * @param  iterable<string>  $saleIds
     * @return array{totals: array<string, Money>, sales: array<string, int>} net collected per method, and how
     *                                                                        many distinct sales used each method
     */
    public function forSales(iterable $saleIds): array
    {
        $ids = is_array($saleIds) ? $saleIds : iterator_to_array($saleIds, false);

        return $this->forQuery(DB::table('payments')
            ->join('sales', 'sales.id', '=', 'payments.sale_id')
            ->whereIn('payments.sale_id', $ids));
    }

    /**
     * @param  Builder  $rows  `payments` joined to `sales`, already filtered to the sales that count
     * @return array{totals: array<string, Money>, sales: array<string, int>}
     */
    public function forQuery(Builder $rows): array
    {
        $totals = [];
        $sales = [];

        $cursor = $rows
            ->select('payments.sale_id', 'payments.method', 'payments.amount', 'sales.grand_total')
            ->orderBy('payments.sale_id')
            ->orderBy('payments.recorded_at')
            ->orderBy('payments.id')
            ->cursor();

        $saleId = null;
        $grandTotal = Money::zero();
        $group = [];

        $flush = function () use (&$group, &$grandTotal, &$totals, &$sales): void {
            if ($group === []) {
                return;
            }
            $net = $this->allocator->allocate($grandTotal, $group);
            $seen = [];
            foreach ($group as $index => $payment) {
                $method = $payment['method'];
                $totals[$method] = ($totals[$method] ?? Money::zero())->add($net[$index]);
                if (! isset($seen[$method])) {
                    $seen[$method] = true;
                    $sales[$method] = ($sales[$method] ?? 0) + 1;
                }
            }
            $group = [];
        };

        foreach ($cursor as $row) {
            if ($row->sale_id !== $saleId) {
                $flush();
                $saleId = $row->sale_id;
                $grandTotal = Money::fromApiString((string) $row->grand_total);
            }
            $group[] = ['method' => $row->method, 'amount' => Money::fromApiString((string) $row->amount)];
        }
        $flush();

        // A stable, predictable order for every reading and report that lists the methods.
        ksort($totals);
        ksort($sales);

        return ['totals' => $totals, 'sales' => $sales];
    }
}
