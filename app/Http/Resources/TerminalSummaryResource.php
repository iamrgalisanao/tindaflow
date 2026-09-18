<?php

namespace App\Http\Resources;

use App\Models\Terminal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml's TerminalSummary schema -- bare (no envelope), matching
 * the frozen shape exactly. Deliberately excludes credential_hash,
 * credential_issued_at, and revoked_at, which exist on the model/table
 * but are not part of TerminalSummary (module-a-auth-terminal-
 * initialization.md §2: deferred to Stage 7).
 *
 * @property Terminal $resource
 */
class TerminalSummaryResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'terminal_code' => $this->resource->terminal_code,
            'status' => $this->resource->status,
            'activated_at' => $this->resource->activated_at?->toJSON(),
        ];
    }
}
