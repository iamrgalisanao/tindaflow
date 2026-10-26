<?php

namespace App\Services\Catalog;

use App\Domain\Exceptions\ProductNotFoundException;
use App\Domain\Exceptions\StockModeChangeBlockedException;
use App\Models\Dish;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\StockRules;
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
    public function __construct(private ProductAuditor $auditor, private ProductBarcodeService $barcodes) {}

    private const FIELDS = [
        'sku', 'barcode', 'name', 'description', 'category_id', 'brand_id', 'menu_group_id', 'unit_of_measure',
        'cost', 'selling_price', 'tax_class', 'track_inventory', 'reorder_level', 'stock_mode', 'dish_id', 'stock_portion',
    ];

    /** @param  array<string, mixed>  $data  the already-validated ProductInput */
    public function create(User $actor, array $data): Product
    {
        try {
            return DB::transaction(function () use ($actor, $data) {
                // The barcode lock makes "is this code free?" and the write one step (see ProductBarcodeService).
                $this->barcodes->claim($actor->store_id, $data['barcode'] ?? null, null);
                $attributes = Arr::only($data, self::FIELDS);
                if (isset($attributes['stock_mode'])) {
                    unset($attributes['track_inventory']); // the mode decides it (Product::booted)
                }
                if (array_key_exists('stock_portion', $attributes) && $attributes['stock_portion'] === null) {
                    unset($attributes['stock_portion']);
                }
                $product = new Product(array_merge(
                    ['reorder_level' => null],
                    isset($attributes['stock_mode']) ? [] : ['track_inventory' => true],
                    $attributes,
                    ['store_id' => $actor->store_id, 'active' => true],
                ));
                $this->assertDishLink($actor->store_id, $product);
                $product->save();
                $this->auditor->created($actor, $product);

                return $product;
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw $this->duplicate($exception);
        }
    }

    public function find(string $storeId, string $productId): Product
    {
        $product = Product::where('store_id', $storeId)->where('menu_hidden', false)->find($productId);

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
                $attributes = Arr::only($data, self::FIELDS);
                if (isset($attributes['stock_mode'])) {
                    unset($attributes['track_inventory']); // the mode decides it (Product::booted)
                }
                if (array_key_exists('stock_portion', $attributes) && $attributes['stock_portion'] === null) {
                    unset($attributes['stock_portion']);
                }
                $product->fill($attributes);
                if ($product->isDirty('stock_mode') || $product->isDirty('track_inventory')) {
                    $this->assertNoStockToOrphan($product);
                }
                if ($product->stock_mode !== 'DAILY' && $product->dish_id !== null) {
                    $product->dish_id = null; // only a daily product can draw from a dish
                }
                $this->assertDishLink($actor->store_id, $product);
                if ($product->isDirty('barcode')) {
                    $this->barcodes->claim($actor->store_id, $product->barcode, $product->id);
                }
                [$before, $after] = $this->auditor->diff($product);
                $product->save();
                $this->barcodes->dropAlternateEqualToMain($product);
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

    /**
     * ADR-017 section 3: a product cannot change between running and daily stock, or stop counting stock, while it still holds some, so stock is
     * never silently orphaned (STOCK_MODE_CHANGE_BLOCKED). The position is the running balance, or today's remaining of a daily product.
     */
    private function assertNoStockToOrphan(Product $product): void
    {
        $before = $product->getOriginal('stock_mode') ?? ($product->getOriginal('track_inventory') ? 'RUNNING' : 'NONE');
        $after = $product->stock_mode ?? ($product->track_inventory ? 'RUNNING' : 'NONE');
        if ($before === $after || $before === 'NONE') {
            return;
        }

        if ($before === 'RUNNING') {
            $position = (string) (DB::table('stock_balances')->where('product_id', $product->id)->sum('quantity_on_hand') ?? '0');
        } else {
            $stockDate = StockRules::forStore($product->store_id)->stockDateAt(now());
            $position = (string) (DB::table('daily_stock_balances')->where('product_id', $product->id)->where('stock_date', $stockDate)->sum('quantity_on_hand') ?? '0');
        }

        if (bccomp(bcadd($position, '0', 3), '0', 3) !== 0) {
            throw StockModeChangeBlockedException::because(
                'This product still has stock ('.bcadd($position, '0', 3).'). Adjust it down to zero before changing how its stock is counted.',
                ['product_id' => $product->id, 'from' => $before, 'to' => $after, 'position' => bcadd($position, '0', 3)],
            );
        }
    }

    /** The ala carte link (ADR-017 section 3): a dish of this store that counts daily stock, on a DAILY product, with portions above zero. */
    private function assertDishLink(string $storeId, Product $product): void
    {
        if ($product->dish_id === null) {
            return;
        }
        if ($product->stock_mode !== 'DAILY') {
            throw ValidationException::withMessages(['dish_id' => 'Only a product that counts daily stock can draw from a dish. Set its stock to Daily.']);
        }
        $dish = Dish::where('store_id', $storeId)->find($product->dish_id);
        if ($dish === null || $dish->stock_mode !== 'DAILY') {
            throw ValidationException::withMessages(['dish_id' => 'Choose a dish that counts daily stock.']);
        }
    }

    private function lock(string $storeId, string $productId): Product
    {
        $product = Product::where('store_id', $storeId)->where('menu_hidden', false)->lockForUpdate()->find($productId);

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
