<?php

namespace App\Services\StoreSetup;

use App\Domain\Exceptions\InventoryLocationResolutionException;
use App\Models\AuditEvent;
use App\Models\Store;
use App\Models\StoreSettings;
use App\Models\User;
use App\Services\Checkout\InventoryLocationResolver;
use App\Services\Inventory\StockRules;
use Illuminate\Support\Facades\DB;

/**
 * ADR-017 section 1: the store's stock rules (block a sale without stock, allow a manager override, the low-stock threshold) and the
 * pre-flight count shown before blocking is turned on. Changing them needs STOCK_ADJUST (MANAGER and ADMIN since 2026-10-27, client decision 8; it was ADMIN-only) and writes one STOCK_RULES_CHANGED
 * audit event with the old and new values; saving what is already saved writes nothing. Turning blocking on never touches a balance: it
 * only makes the sale refuse from then on, so the screen says how many tracked items are at zero (or have no stock record) right now.
 */
final class StockRulesService
{
    /** How many of the items that would be unsellable are named on the screen; the count is always the full figure. */
    private const PREFLIGHT_SAMPLE = 25;

    public function __construct(private readonly InventoryLocationResolver $locations = new InventoryLocationResolver) {}

    /** @return array{block_sale_without_stock: bool, stock_override_enabled: bool, low_stock_default_threshold: int, stock_day_cutover_hour: int, preflight: array{unsellable_count: int, tracked_count: int, sample: list<array{product_id: string, name: string, sku: string}>}} */
    public function current(string $storeId): array
    {
        $rules = StockRules::forStore($storeId);

        return [
            'block_sale_without_stock' => $rules->blockSaleWithoutStock,
            'stock_override_enabled' => $rules->overrideEnabled,
            'low_stock_default_threshold' => $rules->defaultThreshold,
            'stock_day_cutover_hour' => $rules->cutoverHour,
            'preflight' => $this->preflight($storeId),
        ];
    }

    /**
     * @param  array{block_sale_without_stock: bool, stock_override_enabled: bool, low_stock_default_threshold: int}  $values
     * @return array<string, mixed> {@see current()}
     */
    public function update(User $actor, array $values): array
    {
        return DB::transaction(function () use ($actor, $values): array {
            $row = StoreSettings::whereKey($actor->store_id)->lockForUpdate()->first();
            $before = StockRules::forStore($actor->store_id);
            $old = [
                'block_sale_without_stock' => $before->blockSaleWithoutStock,
                'stock_override_enabled' => $before->overrideEnabled,
                'low_stock_default_threshold' => $before->defaultThreshold,
                'stock_day_cutover_hour' => $before->cutoverHour,
            ];
            $new = [
                'block_sale_without_stock' => (bool) $values['block_sale_without_stock'],
                'stock_override_enabled' => (bool) $values['stock_override_enabled'],
                'low_stock_default_threshold' => (int) $values['low_stock_default_threshold'],
                'stock_day_cutover_hour' => (int) ($values['stock_day_cutover_hour'] ?? $before->cutoverHour),
            ];

            if ($old !== $new) {
                $this->row($actor->store_id, $row)->update($new);

                $after = $new;
                if ($new['block_sale_without_stock'] && ! $old['block_sale_without_stock']) {
                    // The figure the administrator was shown when switching blocking on: kept with the change so "why did sales stop?" has an answer.
                    $after['unsellable_count_when_enabled'] = $this->preflight($actor->store_id)['unsellable_count'];
                }

                AuditEvent::create([
                    'store_id' => $actor->store_id,
                    'event_type' => 'STOCK_RULES_CHANGED',
                    'actor_user_id' => $actor->id,
                    'entity_type' => 'store_settings',
                    'entity_id' => $actor->store_id,
                    'before_metadata' => $old,
                    'after_metadata' => $after,
                ]);
            }

            return $this->current($actor->store_id);
        });
    }

    /**
     * The canteen loader's one-time default for the stock day (ADR-017 section 4, client decision 3): sets the cutover hour only while it still holds
     * the column default 0, and writes the same STOCK_RULES_CHANGED event (no actor: it ran from the server console). Never run again for a store whose menu
     * was already loaded, so a manager who set it back to 0 is not overridden.
     */
    public function setCutoverHourOnFirstLoad(string $storeId, int $hour): bool
    {
        return DB::transaction(function () use ($storeId, $hour): bool {
            $row = StoreSettings::whereKey($storeId)->lockForUpdate()->first();
            $current = StockRules::forStore($storeId);
            if ($current->cutoverHour !== 0 || $hour === 0) {
                return false;
            }
            $this->row($storeId, $row)->update(['stock_day_cutover_hour' => $hour]);
            AuditEvent::create([
                'store_id' => $storeId,
                'event_type' => 'STOCK_RULES_CHANGED',
                'actor_user_id' => null,
                'entity_type' => 'store_settings',
                'entity_id' => $storeId,
                'before_metadata' => ['stock_day_cutover_hour' => 0],
                'after_metadata' => ['stock_day_cutover_hour' => $hour, 'via' => 'console'],
            ]);

            return true;
        });
    }

    /**
     * RUNNING, active, sellable products (daily items have no running stock to run out of; they are entered each morning) whose stock at the default location is zero or negative, or that have no stock record at all.
     *
     * @return array{unsellable_count: int, tracked_count: int, sample: list<array{product_id: string, name: string, sku: string}>}
     */
    public function preflight(string $storeId): array
    {
        $tracked = DB::table('products')->where('store_id', $storeId)->where('stock_mode', 'RUNNING')->where('active', true)->where('menu_hidden', false);
        $trackedCount = (clone $tracked)->count();

        try {
            $locationId = $this->locations->resolveDefaultForStore($storeId);
        } catch (InventoryLocationResolutionException) {
            return ['unsellable_count' => $trackedCount, 'tracked_count' => $trackedCount, 'sample' => []];
        }

        $unsellable = (clone $tracked)
            ->leftJoin('stock_balances', fn ($join) => $join->on('stock_balances.product_id', '=', 'products.id')->where('stock_balances.location_id', $locationId))
            ->whereRaw('COALESCE(stock_balances.quantity_on_hand, 0) <= 0');

        return [
            'unsellable_count' => (clone $unsellable)->count(),
            'tracked_count' => $trackedCount,
            'sample' => (clone $unsellable)->orderBy('products.name')->orderBy('products.id')->limit(self::PREFLIGHT_SAMPLE)
                ->get(['products.id', 'products.name', 'products.sku'])
                ->map(fn ($product): array => ['product_id' => $product->id, 'name' => $product->name, 'sku' => $product->sku])->all(),
        ];
    }

    /** The store's settings row, created on first need with the same blank baseline the other settings services use. */
    private function row(string $storeId, ?StoreSettings $row): StoreSettings
    {
        if ($row !== null) {
            return $row;
        }

        $created = new StoreSettings(['store_id' => $storeId, 'business_name' => Store::findOrFail($storeId)->name, 'registered_name' => '', 'tin' => '']);
        $created->save();

        return $created;
    }
}
