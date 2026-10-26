<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\Concerns\RendersReportResponse;
use App\Services\Checkout\InventoryLocationResolver;
use App\Services\Inventory\DailyStockQuery;
use App\Services\Inventory\StockRules;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * openapi.yaml reportDailyStock (ADR-017 slice B, client decision 7): the daily cooked / sold / leftover report, REPORT_VIEW. Per stock date and
 * item: cooked (portions entered), removed, sold, returned (0 for cooked food, which is never put back), the LEFTOVER (that date's remaining
 * balance, derived, nothing is written at close) and an oversold flag (a negative leftover). `from` and `to` are STOCK dates (YYYY-MM-DD) and default to
 * the current stock date; a range is capped at 366 days. CSV via `Accept: text/csv` (csv-export-contract.md).
 */
class DailyStockReportController extends Controller
{
    use RendersReportResponse;

    public const COLUMNS = ['stock_date', 'item_type', 'item_name', 'category', 'cooked', 'removed', 'sold', 'returned', 'leftover', 'oversold'];

    public function dailyStock(Request $request, DailyStockQuery $query, InventoryLocationResolver $locations): Response
    {
        $actor = Auth::guard('web')->user();
        $today = StockRules::forStore($actor->store_id)->stockDateAt(Carbon::now());
        $from = $this->fromDate($request)?->toDateString() ?? $today;
        $to = $this->toDate($request)?->toDateString() ?? $from;
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 366) {
            $from = Carbon::parse($to)->subDays(366)->toDateString();
        }

        $rows = array_map(fn (array $row): array => [
            'stock_date' => $row['stock_date'],
            'item_type' => $row['kind'] === 'DISH' ? 'DISH' : 'PRODUCT',
            'item_id' => $row['id'],
            'item_name' => $row['name'],
            'category' => $row['group'],
            'cooked' => $row['cooked'],
            'removed' => $row['removed'],
            'sold' => $row['sold'],
            'returned' => $row['returned'],
            'leftover' => $row['leftover'],
            'oversold' => $row['oversold'] ? 'true' : 'false',
        ], $query->report($actor->store_id, $locations->resolveDefaultForStore($actor->store_id), $from, $to));

        $summary = [
            'row_count' => count($rows),
            'cooked' => $this->total($rows, 'cooked'),
            'sold' => $this->total($rows, 'sold'),
            'leftover' => $this->total($rows, 'leftover'),
            'oversold_count' => count(array_filter($rows, fn (array $row): bool => $row['oversold'] === 'true')),
        ];

        return $this->reportResponse($request, ['from' => $from, 'to' => $to], $rows, $summary, self::COLUMNS);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function total(array $rows, string $column): string
    {
        return array_reduce($rows, fn (string $carry, array $row): string => bcadd($carry, (string) $row[$column], 3), '0.000');
    }
}
