<?php

namespace App\Http\Resources;

use App\Models\ElectronicJournalEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml ElectronicJournalEntry: a projection of an authoritative domain event (ADR-005), never a
 * second ledger. `payload_json` is a snapshot sufficient to reconstruct the entry without joining back
 * to mutable tables.
 *
 * @property ElectronicJournalEntry $resource
 */
class ElectronicJournalEntryResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'store_id' => $this->resource->store_id,
            'terminal_id' => $this->resource->terminal_id,
            'event_type' => $this->resource->event_type,
            'source_type' => $this->resource->source_type,
            'source_id' => $this->resource->source_id,
            'audit_event_id' => $this->resource->audit_event_id,
            'payload_json' => (object) ($this->resource->payload_json ?? []),
            'occurred_at' => $this->resource->occurred_at?->toJSON(),
        ];
    }
}
