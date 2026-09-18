<?php

namespace App\Http\Resources;

use App\Models\TerminalEnrollmentToken;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml's TerminalEnrollmentToken schema -- bare (no envelope).
 * The plaintext token is passed in explicitly via the constructor (not
 * JsonResource::additional(), which forces "data"-wrapping back on even
 * when $wrap is null) since it is never persisted on the model itself
 * (only token_hash is).
 *
 * @property TerminalEnrollmentToken $resource
 */
class TerminalEnrollmentTokenResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(TerminalEnrollmentToken $resource, private readonly string $plaintextToken)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'token' => $this->plaintextToken,
            'terminal_id' => $this->resource->terminal_id,
            'expires_at' => $this->resource->expires_at->toJSON(),
        ];
    }
}
