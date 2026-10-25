<?php

namespace App\Http\Resources;

use App\Models\MenuGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * MenuGroup schema (openapi.yaml): one node of the till's navigation tree.
 *
 * @property MenuGroup $resource
 */
class MenuGroupResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'parent_id' => $this->resource->parent_id,
            'name' => $this->resource->name,
            'sort_order' => $this->resource->sort_order,
            'active' => $this->resource->active,
        ];
    }
}
