<?php

namespace App\Http\Resources;

use App\Models\InventoryLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property InventoryLocation $resource
 */
class InventoryLocationResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'is_default' => $this->resource->is_default,
        ];
    }
}
