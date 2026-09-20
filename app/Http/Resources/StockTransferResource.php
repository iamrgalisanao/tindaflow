<?php

namespace App\Http\Resources;

use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml StockTransfer. The list carries `lines_count`; the detail carries the lines (ordered by product name).
 *
 * @property StockTransfer $resource
 */
class StockTransferResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $transfer = $this->resource;

        return [
            'id' => $transfer->id,
            'from_location_id' => $transfer->from_location_id,
            'from_location_name' => $transfer->relationLoaded('fromLocation') ? $transfer->fromLocation?->name : null,
            'to_location_id' => $transfer->to_location_id,
            'to_location_name' => $transfer->relationLoaded('toLocation') ? $transfer->toLocation?->name : null,
            'note' => $transfer->note,
            'terminal_id' => $transfer->terminal_id,
            'created_by' => $transfer->created_by,
            'created_at' => $transfer->created_at?->toIso8601String(),
            'lines_count' => $transfer->lines_count ?? ($transfer->relationLoaded('lines') ? $transfer->lines->count() : 0),
            $this->mergeWhen($transfer->relationLoaded('lines'), fn () => [
                'lines' => $transfer->lines
                    ->sortBy(fn (StockTransferLine $line) => mb_strtolower((string) $line->product?->name).'|'.$line->product_id)
                    ->values()
                    ->map(fn (StockTransferLine $line) => [
                        'product_id' => $line->product_id,
                        'sku' => $line->product?->sku,
                        'product_name' => $line->product?->name,
                        'quantity' => $line->quantity,
                    ])->all(),
            ]),
        ];
    }
}
