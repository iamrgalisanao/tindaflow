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
        ];
    }
}
