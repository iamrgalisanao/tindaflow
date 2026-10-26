<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\DB;

/**
 * A store's stock rules (ADR-017 section 1): the typed columns on `store_settings`. A store that has never saved settings, or a row
 * from before the columns existed, gets the defaults: blocking OFF (stage 14's behaviour, negative stock allowed), override ON (it only
 * matters while blocking is on), and a low-stock threshold of 3.
 */
final class StockRules
{
    public const DEFAULT_THRESHOLD = 3;

    public function __construct(
        public readonly bool $blockSaleWithoutStock = false,
        public readonly bool $overrideEnabled = true,
        public readonly int $defaultThreshold = self::DEFAULT_THRESHOLD,
    ) {}

    public static function forStore(string $storeId): self
    {
        $row = DB::table('store_settings')->where('store_id', $storeId)
            ->first(['block_sale_without_stock', 'stock_override_enabled', 'low_stock_default_threshold']);

        return $row === null ? new self : new self((bool) $row->block_sale_without_stock, (bool) $row->stock_override_enabled, (int) $row->low_stock_default_threshold);
    }

    /** Whether a manager may approve a blocked sale: only while blocking is on, and only if the store has not switched overrides off. */
    public function overrideAvailable(): bool
    {
        return $this->blockSaleWithoutStock && $this->overrideEnabled;
    }

    /** The low-stock threshold of a product: its own `reorder_level` when one was set (even 0), else the store default. */
    public function thresholdFor(?int $reorderLevel): int
    {
        return $reorderLevel ?? $this->defaultThreshold;
    }
}
