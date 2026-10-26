<?php

namespace App\Services\Menu;

use App\Models\AuditEvent;
use App\Models\Dish;
use App\Models\MenuGroup;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\ProductAuditor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Create ala carte items for dishes" (ADR-017 slice B, client decision 2, 2026-10-27): every dish can be sold alone, so each DAILY dish gets one
 * ordinary, visible product linked to it (`products.dish_id`, stock mode DAILY, `stock_portion` 2.0 by default) that draws from the SAME daily pool as the
 * dish's meals. The manager sets the prices; the system assumes none.
 *
 *   name   "<Dish> (ala carte)" unless the manager typed one;       sku  ALC-<code from the dish name>, unique per store (suffix -2, -3 ... on a clash)
 *   place  the menu group the manager picked (optional);            tax  the store's current class, like the meal backing products
 *
 * IDEMPOTENT: a dish that already has a linked product (active or not) is reported as skipped (ALREADY_EXISTS) and left alone, so running the action twice, or two
 * managers running it at once (the dish rows are locked in id order), creates each item once. A dish that does not count daily stock is skipped
 * (DISH_NOT_DAILY): it has no pool to draw from. Audited: one PRODUCT_CREATED per product (the catalog's own event) plus one ALA_CARTE_ITEMS_CREATED summary.
 * CATALOG_MANAGE.
 */
final class DishAlaCarteService
{
    public const DEFAULT_PORTION = '2.000';

    public function __construct(private ?ProductAuditor $productAuditor = null, private ?MenuBackingProducts $backing = null)
    {
        $this->productAuditor ??= new ProductAuditor;
        $this->backing ??= new MenuBackingProducts;
    }

    /**
     * @param  array{menu_group_id?: ?string, stock_portion?: string, category_prices?: array<string, ?string>, items: list<array{dish_id: string, price?: ?string, name?: ?string}>}  $data
     * @return array{created: list<array<string, mixed>>, skipped: list<array<string, mixed>>}
     */
    public function create(User $actor, array $data): array
    {
        $portion = bcadd((string) ($data['stock_portion'] ?? self::DEFAULT_PORTION), '0', 3);
        $group = isset($data['menu_group_id']) ? MenuGroup::where('store_id', $actor->store_id)->find($data['menu_group_id']) : null;
        $categoryPrices = $data['category_prices'] ?? [];

        try {
            return DB::transaction(function () use ($actor, $data, $portion, $group, $categoryPrices): array {
                $ids = array_column($data['items'], 'dish_id');
                sort($ids, SORT_STRING);
                $dishes = Dish::where('store_id', $actor->store_id)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

                $created = [];
                $skipped = [];
                foreach ($data['items'] as $index => $item) {
                    $dish = $dishes->get($item['dish_id']);
                    if ($dish === null) {
                        throw ValidationException::withMessages(["items.{$index}.dish_id" => 'Choose a dish of this store.']);
                    }
                    if ($dish->stock_mode !== Dish::MODE_DAILY) {
                        $skipped[] = ['dish_id' => $dish->id, 'dish_name' => $dish->name, 'reason' => 'DISH_NOT_DAILY'];

                        continue;
                    }
                    $existing = Product::where('store_id', $actor->store_id)->where('dish_id', $dish->id)->orderBy('created_at')->first();
                    if ($existing !== null) {
                        $skipped[] = ['dish_id' => $dish->id, 'dish_name' => $dish->name, 'reason' => 'ALREADY_EXISTS', 'product_id' => $existing->id];

                        continue;
                    }

                    $price = $item['price'] ?? $categoryPrices[$dish->dish_category_id] ?? null;
                    if ($price === null || $price === '') {
                        throw ValidationException::withMessages(["items.{$index}.price" => "Set a price for {$dish->name}, or a price for its dish category."]);
                    }

                    $name = trim((string) ($item['name'] ?? '')) !== '' ? trim($item['name']) : $dish->name.' (ala carte)';
                    $product = Product::create([
                        'store_id' => $actor->store_id,
                        'sku' => $this->uniqueSku($actor->store_id, $dish->name),
                        'barcode' => null,
                        'name' => $name,
                        'unit_of_measure' => 'order',
                        'cost' => null,
                        'selling_price' => $price,
                        'tax_class' => $this->backing->taxClassFor($actor->store_id),
                        'stock_mode' => Product::MODE_DAILY,
                        'dish_id' => $dish->id,
                        'stock_portion' => $portion,
                        'reorder_level' => null,
                        'active' => true,
                        'menu_group_id' => $group?->id,
                    ]);
                    $this->productAuditor->created($actor, $product);
                    $created[] = ['product_id' => $product->id, 'sku' => $product->sku, 'name' => $product->name, 'dish_id' => $dish->id, 'selling_price' => $price, 'stock_portion' => $portion];
                }

                if ($created !== [] || $skipped !== []) {
                    AuditEvent::create([
                        'store_id' => $actor->store_id,
                        'event_type' => 'ALA_CARTE_ITEMS_CREATED',
                        'actor_user_id' => $actor->id,
                        'entity_type' => 'dish',
                        'entity_id' => $actor->store_id,
                        'after_metadata' => ['menu_group_id' => $group?->id, 'stock_portion' => $portion, 'created' => $created, 'skipped' => $skipped],
                        'request_id' => request()->attributes->get('request_id'),
                    ]);
                }

                return ['created' => $created, 'skipped' => $skipped];
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['items' => 'An item with this SKU or name already exists. Try again.']);
        }
    }

    private function uniqueSku(string $storeId, string $dishName): string
    {
        $code = strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', '-', Str::ascii($dishName)) ?? '', '-')) ?: 'DISH';
        $base = 'ALC-'.substr($code, 0, 40);
        $sku = $base;
        for ($n = 2; Product::where('store_id', $storeId)->where('sku', $sku)->exists(); $n++) {
            $sku = "{$base}-{$n}";
        }

        return $sku;
    }
}
