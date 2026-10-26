<?php

namespace App\Services\Menu;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The hidden backing products of ADR-016 section 3: one per meal and one per add-on option, so a sale line (slice 1.3) has a
 * product for its foreign key, tax class and product reports. They are created `menu_hidden` (never on the till grid, in
 * search, in the product list or CSV, nor editable through the product API), not tracked in inventory, priced 0.00 (the real
 * price is a menu_prices row), and created INACTIVE so that, until the checkout learns to resolve meals, a client that names
 * one directly is refused as PRODUCT_INACTIVE instead of selling it at 0.00.
 *
 * Slice B (ADR-017) adds a second kind of hidden product: a dish's daily POOL product ({@see createDishPool()}), `menu_hidden`, inactive, TRACKED with stock
 * mode DAILY. The documented assumption "hidden products are untracked" therefore no longer holds: every inventory query that excludes `menu_hidden`
 * products, or filters on `track_inventory`, was reviewed in that slice (running stock views filter on stock mode RUNNING).
 *
 * The tax class follows the store's tax registration on the day the product is created (ADR-016 section 3: "tax class from the
 * store"): VATABLE for a VAT-registered store, NON_VAT for a NON_VAT one or when none is on file yet. The calculator refuses a
 * NON_VAT line in a VAT store (TAX-NV-002/003), so a fixed NON_VAT would make every meal unsellable there. Which classification
 * the client's meals finally carry is still open item 7 of docs/06-backend/canteen-build-plan.md; a store that changes its
 * registration type must re-classify these products like any other product.
 */
final class MenuBackingProducts
{
    public function create(string $storeId, string $name, string $skuPrefix): Product
    {
        return Product::create([
            'store_id' => $storeId,
            'sku' => $this->uniqueSku($storeId, $skuPrefix, $name),
            'barcode' => null,
            'name' => $name,
            'unit_of_measure' => 'order',
            'cost' => null,
            'selling_price' => '0.00',
            'tax_class' => $this->taxClassFor($storeId),
            'track_inventory' => false,
            'reorder_level' => null,
            'active' => false,
            'menu_hidden' => true,
        ]);
    }

    /**
     * A dish's daily-stock pool (ADR-017 section 3): the hidden product every daily stock row of the dish is keyed by. Not sellable (inactive and
     * hidden: naming it in a sale is refused like any hidden product), never on the till, in lists or in CSV; tracked and DAILY so the ledger,
     * the daily balances and the reports treat it like any daily product. Priced 0.00; it is only a key.
     */
    public function createDishPool(string $storeId, string $name): Product
    {
        return Product::create([
            'store_id' => $storeId,
            'sku' => $this->uniqueSku($storeId, 'DISH', $name),
            'barcode' => null,
            'name' => $name,
            'unit_of_measure' => 'portion',
            'cost' => null,
            'selling_price' => '0.00',
            'tax_class' => $this->taxClassFor($storeId),
            'stock_mode' => Product::MODE_DAILY,
            'reorder_level' => null,
            'active' => false,
            'menu_hidden' => true,
        ]);
    }

    /** Keeps the product's name equal to the meal's or add-on's, which is what a sale line snapshots. */
    public function rename(int|string|null $productId, string $name): void
    {
        if ($productId !== null) {
            Product::whereKey($productId)->update(['name' => $name]);
        }
    }

    /** The tax class a new product of the store gets today (see the class comment). */
    public function taxClassFor(string $storeId): string
    {
        $today = now()->toDateString();
        $types = DB::table('tax_registrations')->where('store_id', $storeId)
            ->where('effective_from', '<=', $today)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->pluck('registration_type');

        return $types->count() === 1 && $types->first() === 'VAT' ? 'VATABLE' : 'NON_VAT';
    }

    private function uniqueSku(string $storeId, string $prefix, string $name): string
    {
        $slug = strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', '-', Str::ascii($name)) ?? '', '-')) ?: 'ITEM';
        $base = $prefix.'-'.substr($slug, 0, 40);
        $sku = $base;
        for ($n = 2; Product::where('store_id', $storeId)->where('sku', $sku)->exists(); $n++) {
            $sku = "{$base}-{$n}";
        }

        return $sku;
    }
}
