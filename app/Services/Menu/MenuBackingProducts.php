<?php

namespace App\Services\Menu;

use App\Models\Product;
use Illuminate\Support\Str;

/**
 * The hidden backing products of ADR-016 section 3: one per meal and one per add-on option, so a sale line (slice 1.3) has a
 * product for its foreign key, tax class and product reports. They are created `menu_hidden` (never on the till grid, in
 * search, in the product list or CSV, nor editable through the product API), not tracked in inventory, priced 0.00 (the real
 * price is a menu_prices row), and created INACTIVE so that, until the checkout learns to resolve meals, a client that names
 * one directly is refused as PRODUCT_INACTIVE instead of selling it at 0.00.
 *
 * The tax class is NON_VAT, the registration type the canteen's calculator supports; which classification the client's meals
 * carry is open item 7 of docs/06-backend/canteen-build-plan.md.
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
            'tax_class' => 'NON_VAT',
            'track_inventory' => false,
            'reorder_level' => 0,
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
