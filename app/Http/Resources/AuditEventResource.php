<?php

namespace App\Http\Resources;

use App\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml AuditEvent. The log is append-only and read-only: no create, update or delete operation
 * exists for it.
 *
 * @property AuditEvent $resource
 */
class AuditEventResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'event_type' => $this->resource->event_type,
            'actor_user_id' => $this->resource->actor_user_id,
            'terminal_id' => $this->resource->terminal_id,
            'entity_type' => $this->resource->entity_type,
            'entity_id' => $this->resource->entity_id,
            'before_metadata' => $this->object($this->resource->before_metadata),
            'after_metadata' => $this->object($this->resource->after_metadata),
            'reason' => $this->resource->reason,
            'request_id' => $this->resource->request_id,
            'occurred_at' => $this->resource->occurred_at?->toJSON(),
        ];
    }

    /** The schema says `object`: an empty array must not serialize as `[]`. */
    private function object(?array $metadata): ?object
    {
        return $metadata === null ? null : (object) $metadata;
    }
}
