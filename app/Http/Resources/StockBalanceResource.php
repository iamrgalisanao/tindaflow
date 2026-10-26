<?php

namespace App\Http\Resources;

use App\Models\StockBalance;
use App\Services\Inventory\StockFreshness;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml StockBalance -- a projection of the stock ledger, never written to directly.
 *
 * @property StockBalance $resource
 */
class StockBalanceResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $freshness = StockFreshness::flag(
            $this->resource->getAttribute('shelf_life_days'),
            $this->resource->quantity_on_hand,
            $this->resource->getAttribute('last_received_at'),
            $this->resource->getAttribute('freshness_days_old'),
        );

        return [
            'product_id' => $this->resource->product_id,
            'location_id' => $this->resource->location_id,
            'quantity_on_hand' => $this->resource->quantity_on_hand,
            // The product's own reorder level, or the store's low-stock default when it has none (ADR-017 section 2); null where the row was not read through the product join.
            'low_stock_threshold' => $this->resource->getAttribute('low_stock_threshold') === null ? null : (int) $this->resource->getAttribute('low_stock_threshold'),
            // ADR-017 slice C: the product's rough shelf life, when it last arrived (a receipt or opening stock; Manila date) and whether to check
            // freshness. Advisory only. Null where the row was not read through the product join.
            'shelf_life_days' => $this->resource->getAttribute('shelf_life_days') === null ? null : (int) $this->resource->getAttribute('shelf_life_days'),
            'last_received_on' => $this->resource->getAttribute('last_received_at') === null
                ? null
                : CarbonImmutable::parse($this->resource->getAttribute('last_received_at'))->setTimezone(config('app.timezone'))->toDateString(),
            'check_freshness' => $freshness !== null,
            'days_old' => $freshness['days_old'] ?? null,
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
