<?php

namespace App\Http\Resources;

use App\Models\Terminal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml's TerminalSummary schema -- bare (no envelope).
 *
 * `revoked_at` is a FORWARD-COMMITTED addition to that schema, on the
 * condition module-a-auth-terminal-initialization.md §14 Ruling 9 set for
 * it: "deferred... Revisit as a Stage 4 amendment when back-office
 * terminal-management UI is built (Stage 7)." That UI now exists
 * (/admin/terminals), and without this field it cannot say which terminals
 * are revoked -- revocation writes `revoked_at` and never touches `status`
 * (Ruling 9 again), so no other field carries the fact. Additive and
 * backwards-compatible: no existing field changed meaning, so no consumer
 * breaks. The frozen `openapi.yaml` text is NOT edited here; this joins the
 * batched Stage 4 amendment recorded in that document's §14, which is
 * applied in one reconstruction pass on explicit owner instruction.
 *
 * `credential_hash` and `credential_issued_at` remain excluded:
 * `credential_hash` is a secret, and `credential_issued_at` has no consumer
 * -- Ruling 9's deferral stands for it until one exists.
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
            // null = not revoked. Never derived from `status`, which revocation deliberately leaves alone.
            'revoked_at' => $this->resource->revoked_at?->toJSON(),
        ];
    }
}
