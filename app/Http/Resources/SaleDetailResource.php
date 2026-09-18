<?php

namespace App\Http\Resources;

use App\Domain\Money;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * openapi.yaml SaleDetail schema (SaleSummary + full detail fields).
 * `amount_tendered`/`change` are presentation-only derived values --
 * Sale never persists them -- computed here from the already-immutable,
 * COMPLETED sale's own payments (stage-6c-sale-finalization.md: "change
 * computed server-side").
 *
 * @property Sale $resource
 */
class SaleDetailResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $amountTendered = $this->resource->payments->reduce(
            fn (Money $carry, $payment) => $carry->add(Money::fromApiString((string) $payment->amount)),
            Money::zero(),
        );
        $grandTotal = Money::fromApiString((string) $this->resource->grand_total);
        $change = $amountTendered->greaterThan($grandTotal) ? $amountTendered->subtract($grandTotal) : Money::zero();

        return [
            'id' => $this->resource->id,
            'uuid' => $this->resource->id,
            'transaction_number' => $this->resource->transaction_number,
            'invoice_number' => $this->resource->invoice?->invoice_number,
            'store_id' => $this->resource->store_id,
            'terminal_id' => $this->resource->terminal_id,
            'fiscal_day_id' => $this->resource->fiscal_day_id,
            'shift_id' => $this->resource->shift_id,
            'cashier_id' => $this->resource->cashier_id,
            'sold_at' => $this->resource->sold_at?->toJSON(),
            'grand_total' => $this->resource->grand_total,
            'status' => $this->resource->status,
            'subtotal' => $this->resource->subtotal,
            'order_level_discount_amount' => $this->resource->order_level_discount_amount,
            'discount_total' => $this->resource->discount_total,
            'tax_summary' => [
                'taxable_sales' => $this->resource->taxable_sales,
                'vat_exempt_sales' => $this->resource->vat_exempt_sales,
                'zero_rated_sales' => $this->resource->zero_rated_sales,
                'vat_amount' => $this->resource->vat_amount,
                'non_vat_sales' => $this->resource->non_vat_sales,
            ],
            'idempotency_key' => $this->resource->idempotency_key,
            'buyer_name' => $this->resource->buyer_name,
            'buyer_address' => $this->resource->buyer_address,
            'buyer_tin' => $this->resource->buyer_tin,
            'buyer_business_style' => $this->resource->buyer_business_style,
            'items' => SaleItemResource::collection($this->resource->items),
            'payments' => PaymentResource::collection($this->resource->payments),
            'amount_tendered' => $amountTendered->toApiString(),
            'change' => $change->toApiString(),
            'invoice' => $this->resource->invoice ? new InvoiceSummaryResource($this->resource->invoice) : null,
            'void' => null,
            'refunds' => [],
        ];
    }
}
