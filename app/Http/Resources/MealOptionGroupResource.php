<?php

namespace App\Http\Resources;

use App\Models\MealOptionGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * MealOptionGroup schema (openapi.yaml), with its options.
 *
 * @property MealOptionGroup $resource
 */
class MealOptionGroupResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'meal_id' => $this->resource->meal_id,
            'name' => $this->resource->name,
            'kind' => $this->resource->kind,
            'min_select' => $this->resource->min_select,
            'max_select' => $this->resource->max_select,
            'prices_the_meal' => $this->resource->prices_the_meal,
            'popup_after_choice' => $this->resource->popup_after_choice,
            'step_order' => $this->resource->step_order,
            'active' => $this->resource->active,
            'options' => MealOptionResource::collection($this->resource->options()->with('dish')->get()),
        ];
    }
}
