<?php

namespace App\Http\Resources;

use App\Models\DishCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * DishCategory schema (openapi.yaml).
 *
 * @property DishCategory $resource
 */
class DishCategoryResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'code' => $this->resource->code,
            'name' => $this->resource->name,
            'sort_order' => $this->resource->sort_order,
            'active' => $this->resource->active,
        ];
    }
}
