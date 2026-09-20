<?php

namespace App\Http\Resources;

use App\Models\StockCount;
use App\Models\StockCountLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml StockCount. The list omits `lines` and carries `lines_count`; the detail carries the lines
 * (ordered by product name) and a `summary` of what posting does or did.
 *
 * @property StockCount $resource
 */
class StockCountResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $count = $this->resource;

        return [
            'id' => $count->id,
            'location_id' => $count->location_id,
            'location_name' => $count->relationLoaded('location') ? $count->location?->name : null,
            'status' => $count->status,
            'note' => $count->note,
            'created_by' => $count->created_by,
            'posted_by' => $count->posted_by,
            'posted_at' => $count->posted_at?->toIso8601String(),
            'cancelled_at' => $count->cancelled_at?->toIso8601String(),
            'created_at' => $count->created_at?->toIso8601String(),
            'updated_at' => $count->updated_at?->toIso8601String(),
            'lines_count' => $count->lines_count ?? ($count->relationLoaded('lines') ? $count->lines->count() : 0),
            $this->mergeWhen($count->relationLoaded('lines'), fn () => [
                'summary' => $this->summary($count),
                'lines' => $count->lines
                    ->sortBy(fn (StockCountLine $line) => mb_strtolower((string) $line->product?->name).'|'.$line->product_id)
                    ->values()
                    ->map(fn (StockCountLine $line) => [
                        'product_id' => $line->product_id,
                        'sku' => $line->product?->sku,
                        'product_name' => $line->product?->name,
                        'counted_quantity' => $line->counted_quantity,
                        'expected_quantity' => $line->expected_quantity,
                        'variance' => $line->variance(),
                        'counted_by' => $line->counted_by,
                        'counted_at' => $line->counted_at?->toIso8601String(),
                        'stock_movement_id' => $line->stock_movement_id,
                    ])->all(),
            ]),
        ];
    }

    /** @return array{lines_counted: int, lines_with_variance: int, units_over: string, units_short: string} */
    private function summary(StockCount $count): array
    {
        $over = '0.000';
        $short = '0.000';
        $withVariance = 0;
        foreach ($count->lines as $line) {
            $variance = $line->variance();
            if (bccomp($variance, '0', 3) > 0) {
                $over = bcadd($over, $variance, 3);
                $withVariance++;
            } elseif (bccomp($variance, '0', 3) < 0) {
                $short = bcadd($short, ltrim($variance, '-'), 3);
                $withVariance++;
            }
        }

        return [
            'lines_counted' => $count->lines->count(),
            'lines_with_variance' => $withVariance,
            'units_over' => $over,
            'units_short' => $short,
        ];
    }
}
