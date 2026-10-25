<?php

namespace App\Http\Resources;

use App\Models\Meal;
use App\Services\Menu\MenuPriceService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Meal schema (openapi.yaml): the meal, its option groups with their options, and `pricing`, the ids the admin still has to
 * price (dish categories offered by an active dish option, and active add-ons, with no price in force now).
 *
 * @property Meal $resource
 */
class MealResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'menu_group_id' => $this->resource->menu_group_id,
            'backing_product_id' => $this->resource->backing_product_id,
            'sort_order' => $this->resource->sort_order,
            'active' => $this->resource->active,
            'option_groups' => MealOptionGroupResource::collection($this->resource->optionGroups()->get()),
            'pricing' => app(MenuPriceService::class)->status($this->resource),
        ];
    }
}
