<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** openapi.yaml VoidResult: VoidSummary plus the sale as it stands now. */
class VoidResultResource extends VoidSummaryResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return parent::toArray($request) + [
            'sale' => new SaleSummaryResource($this->resource->sale()->with('invoice')->first()),
        ];
    }
}
