<?php

namespace App\Http\Resources;

use App\Models\FiscalInstallation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml's FiscalInstallation schema.
 *
 * @property FiscalInstallation $resource
 */
class FiscalInstallationResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currentAccreditation = $this->resource->relationLoaded('accreditations')
            ? $this->resource->accreditations->firstWhere('effective_to', null)
            : $this->resource->currentAccreditation()->first();

        $currentPermitToUse = $this->resource->relationLoaded('permitsToUse')
            ? $this->resource->permitsToUse->firstWhere('effective_to', null)
            : $this->resource->currentPermitToUse()->first();

        return [
            'id' => $this->resource->id,
            'store_id' => $this->resource->store_id,
            'deployment_model' => $this->resource->deployment_model,
            'machine_serial_number' => $this->resource->machine_serial_number,
            'software_version' => $this->resource->software_version,
            'installed_at' => $this->resource->installed_at?->toJSON(),
            'superseded_at' => $this->resource->superseded_at?->toJSON(),
            'accreditation' => $currentAccreditation === null ? null : [
                'number' => $currentAccreditation->number,
                'date' => $currentAccreditation->date?->toDateString(),
                'effective_from' => $currentAccreditation->effective_from?->toDateString(),
                'effective_to' => $currentAccreditation->effective_to?->toDateString(),
            ],
            'permit_to_use' => $currentPermitToUse === null ? null : [
                'number' => $currentPermitToUse->number,
                'min' => $currentPermitToUse->min,
                'date' => $currentPermitToUse->date?->toDateString(),
                'effective_from' => $currentPermitToUse->effective_from?->toDateString(),
                'effective_to' => $currentPermitToUse->effective_to?->toDateString(),
            ],
            'terminals' => $this->resource->terminals->pluck('id')->values(),
        ];
    }
}
