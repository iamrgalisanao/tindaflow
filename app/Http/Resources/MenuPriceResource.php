<?php

namespace App\Http\Resources;

use App\Models\MenuPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * MenuPrice schema (openapi.yaml): one row of the price history. The names are today's names of the category and add-on; the
 * row itself is keyed by id, so renaming never changes it.
 *
 * @property MenuPrice $resource
 */
class MenuPriceResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'meal_id' => $this->resource->meal_id,
            'dish_category_id' => $this->resource->dish_category_id,
            'dish_category_name' => $this->resource->dishCategory?->name,
            'meal_option_id' => $this->resource->meal_option_id,
            'meal_option_label' => $this->resource->mealOption?->label,
            'amount' => $this->resource->amount,
            'effective_from' => $this->resource->effective_from->toJSON(),
            'changed_by' => $this->resource->changed_by,
            'changed_by_name' => $this->resource->changedBy?->name,
            'reason' => $this->resource->reason,
            'created_at' => $this->resource->created_at?->toJSON(),
        ];
    }
}
