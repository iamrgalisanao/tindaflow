<?php

namespace App\Http\Resources;

use App\Models\XReading;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml XReading schema.
 *
 * @property XReading $resource
 */
class XReadingResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Everything from which the drawer's expected cash follows. An interim reading shows these only to someone with
     * REPORT_VIEW: a cashier who could pull one mid-shift would otherwise count against a figure they can see, which
     * is what a blind close prevents. The closing reading, produced when they declare their count, shows everything.
     */
    private const CASH_DERIVING = ['expected_cash', 'variance', 'cash_sales', 'refunds_total', 'cash_in_total', 'cash_out_total'];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'terminal_id' => $this->resource->terminal_id,
            'shift_id' => $this->resource->shift_id,
            'cashier_id' => $this->resource->cashier_id,
            'from_at' => $this->resource->from_at?->toJSON(),
            'to_at' => $this->resource->to_at?->toJSON(),
            'generated_at' => $this->resource->generated_at?->toJSON(),
            'generated_by' => $this->resource->generated_by,
            'is_closing_reading' => $this->resource->is_closing_reading,
            'totals_snapshot' => $this->totals($request),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function totals(Request $request): ?array
    {
        $totals = $this->resource->totals_snapshot;

        if ($totals === null || $this->resource->is_closing_reading || $request->user()?->can('REPORT_VIEW')) {
            return $totals;
        }

        foreach (self::CASH_DERIVING as $field) {
            $totals[$field] = null;
        }
        unset($totals['payment_breakdown']['CASH']);

        return $totals;
    }
}
