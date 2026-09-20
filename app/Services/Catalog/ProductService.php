<?php

namespace App\Services\Catalog;

use App\Domain\Exceptions\ProductNotFoundException;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin surface for the `products` catalog (productCreate/Get/Update/Activate/Deactivate).
 *
 * Updates change current catalog data only. Historical `sale_items` carry their own immutable
 * snapshots (Stage 2), so nothing here ever touches them. Products are never deleted -- retirement
 * is `active = false`, which keeps every historical reference valid.
 */
final class ProductService
{
    public function __construct(private ProductAuditor $auditor) {}

    private const FIELDS = [
        'sku', 'barcode', 'name', 'description', 'category_id', 'brand_id', 'unit_of_measure',
        'cost', 'selling_price', 'tax_class', 'track_inventory', 'reorder_level',
    ];

    /** @param  array<string, mixed>  $data  the already-validated ProductInput */
    public function create(User $actor, array $data): Product
    {
        try {
            return DB::transaction(function () use ($actor, $data) {
                $product = Product::create(array_merge(
                    ['track_inventory' => true, 'reorder_level' => 0],
                    Arr::only($data, self::FIELDS),
                    ['store_id' => $actor->store_id, 'active' => true],
                ));
                $this->auditor->created($actor, $product);

                return $product;
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->duplicate($exception);
        }
    }

    public function find(string $storeId, string $productId): Product
    {
        $product = Product::where('store_id', $storeId)->find($productId);

        if ($product === null) {
            throw ProductNotFoundException::forId($productId);
        }

        return $product;
    }

    /** @param  array<string, mixed>  $data  the already-validated ProductInput; absent optional keys are left untouched */
    public function update(User $actor, string $productId, array $data): Product
    {
        try {
            return DB::transaction(function () use ($actor, $productId, $data) {
                $product = $this->lock($actor->store_id, $productId);
                $product->fill(Arr::only($data, self::FIELDS));
                [$before, $after] = $this->auditor->diff($product);
                $product->save();
                $this->auditor->changed($actor, $product, $before, $after);

                return $product;
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->duplicate($exception);
        }
    }

    /** Idempotent: activating an active product (or deactivating an inactive one) returns it unchanged. */
    public function setActive(User $actor, string $productId, bool $active): Product
    {
        return DB::transaction(function () use ($actor, $productId, $active) {
            $product = $this->lock($actor->store_id, $productId);

            if ($product->active !== $active) {
                $product->active = $active;
                [$before, $after] = $this->auditor->diff($product);
                $product->save();
                $this->auditor->changed($actor, $product, $before, $after);
            }

            return $product;
        });
    }

    private function lock(string $storeId, string $productId): Product
    {
        $product = Product::where('store_id', $storeId)->lockForUpdate()->find($productId);

        if ($product === null) {
            throw ProductNotFoundException::forId($productId);
        }

        return $product;
    }

    /** Two concurrent requests can both pass the FormRequest uniqueness check; the DB constraint is the backstop. */
    private function duplicate(UniqueConstraintViolationException $exception): ValidationException
    {
        return str_contains($exception->getMessage(), 'barcode')
            ? ValidationException::withMessages(['barcode' => 'This barcode is already assigned to another product.'])
            : ValidationException::withMessages(['sku' => 'This SKU is already used by another product.']);
    }
}
