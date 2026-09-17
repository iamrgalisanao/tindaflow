<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\Auth\RoleCapabilityCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml's UserSummary schema -- the single source of truth for
 * this shape, used identically by authLogin and authMe so the two
 * responses can never drift apart.
 *
 * @property User $resource
 */
class UserSummaryResource extends JsonResource
{
    // openapi.yaml declares UserSummary as the bare response body (no
    // envelope) for both authLogin (200) and authMe (200) -- Laravel's
    // default "data"-wrapping would violate that frozen shape.
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'role' => $this->resource->role,
            'capabilities' => RoleCapabilityCatalog::forRole($this->resource->role),
            'active' => $this->resource->active,
        ];
    }
}
