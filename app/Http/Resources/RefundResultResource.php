<?php

namespace App\Http\Resources;

use App\Models\Refund;
use Illuminate\Http\Request;

/**
 * openapi.yaml RefundResult. Lines and settlements are real rows once the refund is COMPLETED. Before
 * that (REQUESTED, or REJECTED) they do not exist as rows -- invariant #72 -- so they are shown from
 * the recorded request with `id` and `processed_at` null: the approver needs to see what is being
 * asked for.
 */
class RefundResultResource extends RefundSummaryResource
{
    /** @var list<array<string, string>> */
    private array $remaining = [];

    /** @param  list<array<string, string>>  $remaining */
    public static function withRemaining(Refund $refund, array $remaining): self
    {
        $resource = new self($refund);
        $resource->remaining = $remaining;

        return $resource;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $refund = $this->resource;

        if ($refund->status === 'COMPLETED') {
            $items = $refund->items()->get()->map(fn ($item) => [
                'id' => $item->id,
                'sale_item_id' => $item->sale_item_id,
                'quantity_returned' => $item->quantity_returned,
                'disposition' => $item->disposition,
                'unit_refund_amount' => $item->unit_refund_amount,
            ])->all();
            $settlements = $refund->settlements()->get()->map(fn ($settlement) => [
                'id' => $settlement->id,
                'payment_method' => $settlement->payment_method,
                'amount' => $settlement->amount,
                'processed_at' => $settlement->processed_at?->toJSON(),
                'external_reference' => $settlement->external_reference,
            ])->all();
        } else {
            $requested = $refund->requestedPayload() ?? ['items' => [], 'settlements' => []];
            $items = array_map(fn (array $item) => ['id' => null] + $item, $requested['items']);
            $settlements = array_map(fn (array $settlement) => ['id' => null, 'processed_at' => null] + $settlement, $requested['settlements']);
        }

        return parent::toArray($request) + [
            'items' => $items,
            'settlements' => $settlements,
            'sale' => new SaleSummaryResource($refund->sale()->with('invoice')->first()),
            'remaining_refundable' => $this->remaining,
        ];
    }
}
