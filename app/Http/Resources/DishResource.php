<?php

namespace App\Http\Resources;

use App\Models\Dish;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Dish schema (openapi.yaml).
 *
 * @property Dish $resource
 */
class DishResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'dish_category_id' => $this->resource->dish_category_id,
            'sort_order' => $this->resource->sort_order,
            'active' => $this->resource->active,
            'stock_mode' => $this->resource->stock_mode,
            // The ala carte products that draw from this dish (only on the dish list, where the screen needs to know which dishes have one).
            'ala_carte_products' => $this->whenLoaded('alaCarteProducts', fn () => $this->resource->alaCarteProducts->map(fn ($product) => [
                'id' => $product->id, 'name' => $product->name, 'sku' => $product->sku, 'selling_price' => $product->selling_price, 'active' => $product->active,
                'stock_portion' => $product->stock_portion, 'menu_group_id' => $product->menu_group_id,
            ])->values()),
        ];
    }
}
