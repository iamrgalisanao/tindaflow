<?php

namespace App\Services\Catalog;

use App\Domain\Exceptions\ProductNotFoundException;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alternate barcodes (`product_barcodes`): the extra codes a product can be scanned by, for example another
 * supplier's packaging. docs/06-backend/stage-26-alternate-barcodes.md.
 *
 * A barcode identifies at most one product per store, whether it is a product's own `products.barcode` or an
 * alternate. The two live in different tables, so the database cannot enforce that across them (each table has
 * only its own unique index). Every writer of either therefore goes through {@see claim()}, which takes a
 * per-store advisory lock for the rest of its transaction and then checks the barcode is free; that makes
 * "check, then write" one step even when an alternate is added while another product is being given the same code.
 */
final class ProductBarcodeService
{
    public function __construct(private readonly ProductAuditor $auditor) {}

    /** @return Collection<int, ProductBarcode> the product's alternate barcodes, oldest first */
    public function list(string $storeId, string $productId): Collection
    {
        $this->product($storeId, $productId);

        return ProductBarcode::where('product_id', $productId)->orderBy('created_at')->orderBy('name')->orderBy('barcode')->get();
    }

    /**
     * Adds a packaging: a barcode the product can be scanned by, a named pack that says how many single units one
     * holds, or both. A barcode alone is an alias of the single unit (1 unit per pack); a pack without a barcode is
     * a case you receive by count.
     *
     * @param  array{barcode?: ?string, name?: ?string, units_per_base?: ?string, can_receive?: ?bool}  $attributes
     *
     * @throws ValidationException the barcode is already in use in this store (field `barcode`), or the product already has a pack of that name (field `name`)
     */
    public function add(User $actor, string $productId, array $attributes): ProductBarcode
    {
        $barcode = ($attributes['barcode'] ?? null) === '' ? null : ($attributes['barcode'] ?? null);
        $name = isset($attributes['name']) ? trim((string) $attributes['name']) : null;
        $name = $name === '' ? null : $name;

        try {
            return DB::transaction(function () use ($actor, $productId, $barcode, $name, $attributes) {
                $product = $this->product($actor->store_id, $productId, lock: true);
                $this->claim($actor->store_id, $barcode, $product->id);

                if ($barcode !== null && $product->barcode === $barcode) {
                    throw ValidationException::withMessages(['barcode' => "This is already the product's main barcode."]);
                }
                if ($barcode !== null && ProductBarcode::where('product_id', $product->id)->where('barcode', $barcode)->exists()) {
                    throw ValidationException::withMessages(['barcode' => 'This product already has this barcode.']);
                }
                if ($name !== null && ProductBarcode::where('product_id', $product->id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->exists()) {
                    throw ValidationException::withMessages(['name' => 'This product already has a pack with this name.']);
                }

                $created = ProductBarcode::create([
                    'product_id' => $product->id,
                    'store_id' => $actor->store_id,
                    'barcode' => $barcode,
                    'name' => $name,
                    'units_per_base' => bcadd((string) ($attributes['units_per_base'] ?? '1'), '0', 3),
                    'can_receive' => (bool) ($attributes['can_receive'] ?? true),
                    'is_primary' => false,
                ]);
                $this->auditor->barcodeAdded($actor, $product, $created);

                return $created;
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw str_contains($exception->getMessage(), 'product_name_unique')
                ? ValidationException::withMessages(['name' => 'This product already has a pack with this name.'])
                : ValidationException::withMessages(['barcode' => 'This barcode is already assigned to another product.']);
        }
    }

    /** Removing a packaging that is not there is not an error: the product simply is not scanned or received by it. */
    public function remove(User $actor, string $productId, string $barcodeId): void
    {
        DB::transaction(function () use ($actor, $productId, $barcodeId) {
            $product = $this->product($actor->store_id, $productId, lock: true);

            // Scoped to this product, so an id that belongs to another product can never be removed through this one.
            $row = ProductBarcode::where('product_id', $product->id)->whereKey($barcodeId)->first();
            if ($row === null) {
                return;
            }

            $row->delete();
            $this->auditor->barcodeRemoved($actor, $product, $row);
        });
    }

    /**
     * Takes the store's barcode lock and checks that `$barcode` is not held by any OTHER product, as its own
     * barcode or as an alternate. Must run inside a transaction: the lock is released when it ends. A null or empty
     * barcode needs nothing.
     *
     * @throws ValidationException
     */
    public function claim(string $storeId, ?string $barcode, ?string $exceptProductId): void
    {
        if ($barcode === null || $barcode === '') {
            return;
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['product-barcode:'.$storeId]);
        }

        $ownBarcodeElsewhere = Product::where('store_id', $storeId)->where('barcode', $barcode)
            ->when($exceptProductId, fn ($query) => $query->where('id', '!=', $exceptProductId))
            ->exists();
        $alternateElsewhere = ProductBarcode::where('store_id', $storeId)->where('barcode', $barcode)
            ->when($exceptProductId, fn ($query) => $query->where('product_id', '!=', $exceptProductId))
            ->exists();

        if ($ownBarcodeElsewhere || $alternateElsewhere) {
            throw ValidationException::withMessages(['barcode' => 'This barcode is already assigned to another product.']);
        }
    }

    /**
     * When a product's main barcode is changed to one of its own alternates, that code is now the main one, so the
     * alternate row is dropped instead of leaving the same code in both places.
     */
    public function dropAlternateEqualToMain(Product $product): void
    {
        if ($product->barcode === null || ! $product->wasChanged('barcode')) {
            return;
        }

        ProductBarcode::where('product_id', $product->id)->where('barcode', $product->barcode)->delete();
    }

    private function product(string $storeId, string $productId, bool $lock = false): Product
    {
        $product = Product::where('store_id', $storeId)->when($lock, fn ($query) => $query->lockForUpdate())->find($productId);

        if ($product === null) {
            throw ProductNotFoundException::forId($productId);
        }

        return $product;
    }
}
