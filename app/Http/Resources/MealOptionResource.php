<?php

namespace App\Http\Resources;

use App\Models\MealOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * MealOption schema (openapi.yaml). A dish option carries the dish's current name and category; an add-on option carries its
 * label. These are the live configuration; a sale line never reads them back (MENU-001).
 *
 * @property MealOption $resource
 */
class MealOptionResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $dish = $this->resource->dish_id === null ? null : $this->resource->dish;

        return [
            'id' => $this->resource->id,
            'meal_option_group_id' => $this->resource->meal_option_group_id,
            'dish_id' => $this->resource->dish_id,
            'dish_name' => $dish?->name,
            'dish_category_id' => $dish?->dish_category_id,
            'label' => $this->resource->label,
            'sort_order' => $this->resource->sort_order,
            'active' => $this->resource->active,
        ];
    }
}
