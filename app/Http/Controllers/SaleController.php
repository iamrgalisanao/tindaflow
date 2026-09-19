<?php

namespace App\Http\Controllers;

use App\Domain\Exceptions\IdempotencyKeyRequiredException;
use App\Domain\Exceptions\SaleNotFoundException;
use App\Http\Controllers\Concerns\ParsesListFilters;
use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Requests\SaleFinalizeRequest;
use App\Http\Requests\SaleRefundRequest;
use App\Http\Requests\SaleVoidRequest;
use App\Http\Resources\RefundResultResource;
use App\Http\Resources\SaleDetailResource;
use App\Http\Resources\SaleSummaryResource;
use App\Http\Resources\VoidResultResource;
use App\Models\Sale;
use App\Services\Auth\PosRequestContext;
use App\Services\Checkout\CheckoutService;
use App\Services\Sales\RefundService;
use App\Services\Sales\VoidService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * openapi.yaml Sales tag -- A6: wires A4's PosRequestContext into
 * CheckoutService::finalize() exactly as stage-6c-sale-finalization.md's
 * "Authentication boundary" section specifies. terminal_id/cashier_id
 * are NEVER read from the request body or route parameters -- only from
 * the already-coherence-checked PosRequestContext ComposeAuthoritativeContext
 * attaches to the request.
 *
 * saleList/saleGet are session-only reads scoped to the actor's store (a sale of another store is
 * SALE_NOT_FOUND). saleVoid/saleRefund are terminal-scoped like checkout.
 */
class SaleController extends Controller
{
    use ParsesListFilters, RespondsWithPagination;

    private const SORTS = [
        'sold_at' => ['sold_at', 'asc'],
        '-sold_at' => ['sold_at', 'desc'],
        'grand_total' => ['grand_total', 'asc'],
        '-grand_total' => ['grand_total', 'desc'],
    ];

    public function finalize(SaleFinalizeRequest $request, CheckoutService $service): JsonResponse
    {
        $context = $this->context($request);

        $sale = $service->finalize(
            $context->terminal->id,
            $context->user->id,
            $this->idempotencyKey($request),
            $request->validated(),
        );

        return (new SaleDetailResource($sale))->response()->setStatusCode(201);
    }

    public function list(Request $request): JsonResponse
    {
        $actor = Auth::guard('web')->user();
        $query = Sale::query()->where('store_id', $actor->store_id)->with('invoice');

        if ($request->filled('transaction_number')) {
            $query->where('transaction_number', (string) $request->query('transaction_number'));
        }
        if ($request->filled('invoice_number')) {
            $query->whereHas('invoice', fn (Builder $invoice) => $invoice->where('invoice_number', (string) $request->query('invoice_number')));
        }
        foreach (['cashier_id', 'terminal_id'] as $column) {
            if ($request->filled($column)) {
                $this->whereUuid($query, $column, (string) $request->query($column));
            }
        }
        if ($request->filled('product_id')) {
            $productId = (string) $request->query('product_id');
            Str::isUuid($productId)
                ? $query->whereHas('items', fn (Builder $items) => $items->where('product_id', $productId))
                : $query->whereRaw('1 = 0');
        }
        if ($request->filled('payment_method')) {
            $method = (string) $request->query('payment_method');
            in_array($method, ['CASH', 'GCASH', 'MAYA', 'CARD', 'OTHER'], true)
                ? $query->whereHas('payments', fn (Builder $payments) => $payments->where('method', $method))
                : $query->whereRaw('1 = 0');
        }
        if ($request->filled('status')) {
            $this->whereOneOf($query, 'status', (string) $request->query('status'), ['COMPLETED', 'VOIDED', 'PARTIALLY_REFUNDED', 'REFUNDED']);
        }
        if ($from = $this->dateFilter($request->query('from'))) {
            $query->where('sold_at', '>=', $from->startOfDay());
        }
        if ($to = $this->dateFilter($request->query('to'))) {
            $query->where('sold_at', '<=', $to->endOfDay());
        }

        [$column, $direction] = self::SORTS[(string) $request->query('sort')] ?? self::SORTS['-sold_at'];

        return $this->paginatedResponse(
            $query->orderBy($column, $direction)->orderBy('id', $direction)
                ->paginate(perPage: $this->perPage($request), page: (int) $request->query('page', 1)),
            SaleSummaryResource::class,
        );
    }

    public function get(string $saleId): JsonResponse
    {
        $actor = Auth::guard('web')->user();
        $sale = Sale::where('store_id', $actor->store_id)->with(['items', 'payments', 'invoice'])->find($saleId)
            ?? throw SaleNotFoundException::forId($saleId);

        return (new SaleDetailResource($sale))->response();
    }

    public function void(SaleVoidRequest $request, VoidService $service, string $saleId): JsonResponse
    {
        $context = $this->context($request);

        $void = $service->request($context->terminal, $context->user, $saleId, $this->idempotencyKey($request), $request->validated('reason'));

        return (new VoidResultResource($void))->response()->setStatusCode(201);
    }

    public function refund(SaleRefundRequest $request, RefundService $service, string $saleId): JsonResponse
    {
        $context = $this->context($request);

        $refund = $service->request($context->terminal, $context->user, $saleId, $this->idempotencyKey($request), $request->validated());

        return RefundResultResource::withRemaining($refund, $service->remainingRefundable($refund->sale))->response()->setStatusCode(201);
    }

    private function context(Request $request): PosRequestContext
    {
        /** @var PosRequestContext */
        return $request->attributes->get('pos_context');
    }

    private function idempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        if (! $key) {
            throw IdempotencyKeyRequiredException::make();
        }

        return $key;
    }
}
