<?php

namespace App\Http\Resources;

use App\Models\TerminalFiscalInstallation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property TerminalFiscalInstallation $resource
 */
class TerminalFiscalInstallationResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'terminal_id' => $this->resource->terminal_id,
            'fiscal_installation_id' => $this->resource->fiscal_installation_id,
            'effective_from' => $this->resource->effective_from?->toJSON(),
            'effective_to' => $this->resource->effective_to?->toJSON(),
        ];
    }
}
