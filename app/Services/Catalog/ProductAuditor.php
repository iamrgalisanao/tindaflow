<?php

namespace App\Services\Catalog;

use App\Models\AuditEvent;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\User;
use Illuminate\Support\Arr;

/**
 * Writes the audit trail for catalog changes (docs/06-backend/stage-24-owner-decisions.md, decision 2).
 *
 * Three events, all written inside the same transaction as the change they describe, so an audit row can never
 * exist without its change nor the reverse (and a dry-run import, which rolls back, leaves nothing):
 *   PRODUCT_CREATED   the product as created;
 *   PRODUCT_UPDATED   only the fields that changed, before and after (an activate or deactivate is the `active`
 *                     field), plus the product's sku for context; nothing is written if nothing changed;
 *   PRODUCT_IMPORTED  one summary per CSV import (counts and the file's hash); every row that actually changed also
 *                     has its own PRODUCT_CREATED / PRODUCT_UPDATED, linked to the summary by the batch id.
 * Categories and brands are not audited: they carry no price, tax or stock meaning.
 */
final class ProductAuditor
{
    /** The fields worth a record: everything a sale, a price or a tax figure can depend on. */
    public const TRACKED = [
        'sku', 'barcode', 'name', 'description', 'category_id', 'brand_id', 'unit_of_measure',
        'cost', 'selling_price', 'tax_class', 'track_inventory', 'reorder_level', 'active',
    ];

    /**
     * What changed on a model that has been filled but not yet saved: [before, after], tracked fields only.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public function diff(Product $product): array
    {
        $before = [];
        $after = [];

        foreach (array_keys(Arr::only($product->getDirty(), self::TRACKED)) as $field) {
            $before[$field] = $product->getOriginal($field);
            $after[$field] = $product->getAttribute($field);
        }

        return [$before, $after];
    }

    public function created(User $actor, Product $product, ?string $reason = null): void
    {
        $this->write($actor, 'PRODUCT_CREATED', $product->id, null, $this->snapshot($product), $reason);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function changed(User $actor, Product $product, array $before, array $after, ?string $reason = null): void
    {
        if ($after === []) {
            return;
        }

        // The sku says which product this was, even when it is not one of the fields that changed.
        $this->write($actor, 'PRODUCT_UPDATED', $product->id, $before, $after + ['sku' => $product->sku], $reason);
    }

    /** A packaging was added: a code the product can now also be scanned by, and/or a named pack (stages 26 and 29). */
    public function barcodeAdded(User $actor, Product $product, ProductBarcode $packaging): void
    {
        $this->write($actor, 'PRODUCT_BARCODE_ADDED', $product->id, null, $this->packagingSnapshot($product, $packaging), null);
    }

    /** A packaging was removed; the product can no longer be scanned by it or received in it. */
    public function barcodeRemoved(User $actor, Product $product, ProductBarcode $packaging): void
    {
        $this->write($actor, 'PRODUCT_BARCODE_REMOVED', $product->id, $this->packagingSnapshot($product, $packaging), [], null);
    }

    /**
     * The sku and the barcode; a name and a pack size only when there is one, so a plain alias records exactly what it
     * did before stage 29.
     *
     * @return array<string, mixed>
     */
    private function packagingSnapshot(Product $product, ProductBarcode $packaging): array
    {
        return array_filter([
            'sku' => $product->sku,
            'barcode' => $packaging->barcode,
            'name' => $packaging->name,
            'units_per_base' => bccomp((string) $packaging->units_per_base, '1', 3) === 0 ? null : (string) $packaging->units_per_base,
        ], fn ($value) => $value !== null);
    }

    /** @param  array<string, mixed>  $summary */
    public function imported(User $actor, string $batchId, array $summary): void
    {
        $this->write($actor, 'PRODUCT_IMPORTED', $batchId, null, $summary, null, 'product_import');
    }

    /** @return array<string, mixed> */
    private function snapshot(Product $product): array
    {
        $snapshot = [];
        foreach (self::TRACKED as $field) {
            $snapshot[$field] = $product->getAttribute($field);
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after
     */
    private function write(User $actor, string $type, string $entityId, ?array $before, array $after, ?string $reason, string $entityType = 'product'): void
    {
        AuditEvent::create([
            'store_id' => $actor->store_id,
            'event_type' => $type,
            'actor_user_id' => $actor->id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_metadata' => $before,
            'after_metadata' => $after,
            'reason' => $reason,
            'request_id' => request()->attributes->get('request_id'),
        ]);
    }
}
