<?php

namespace App\Http\Controllers;

use App\Domain\Exceptions\IdempotencyKeyRequiredException;
use App\Http\Controllers\Concerns\ParsesListFilters;
use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Requests\SaleVoidRequest;
use App\Http\Resources\RefundResultResource;
use App\Http\Resources\RefundSummaryResource;
use App\Models\Refund;
use App\Models\Sale;
use App\Services\Auth\PosRequestContext;
use App\Services\Sales\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml refundList / refundGet / refundApprove / refundReject -- symmetric with VoidController.
 * A rejection takes only a `reason`, the same body as a void's, so SaleVoidRequest is reused.
 */
class RefundController extends Controller
{
    use ParsesListFilters, RespondsWithPagination;

    public function list(Request $request): JsonResponse
    {
        $actor = Auth::guard('web')->user();
        $query = Refund::query()->whereIn('sale_id', Sale::where('store_id', $actor->store_id)->select('id'));

        if ($request->filled('status')) {
            $this->whereOneOf($query, 'status', (string) $request->query('status'), ['REQUESTED', 'APPROVED', 'REJECTED', 'COMPLETED']);
        }
        if ($request->filled('sale_id')) {
            $this->whereUuid($query, 'sale_id', (string) $request->query('sale_id'));
        }
        if ($from = $this->dateFilter($request->query('from'))) {
            $query->where('requested_at', '>=', $from->startOfDay());
        }
        if ($to = $this->dateFilter($request->query('to'))) {
            $query->where('requested_at', '<=', $to->endOfDay());
        }

        return $this->paginatedResponse(
            $query->orderByDesc('requested_at')->orderByDesc('id')
                ->paginate(perPage: $this->perPage($request), page: (int) $request->query('page', 1)),
            RefundSummaryResource::class,
        );
    }

    public function get(RefundService $service, string $refundId): JsonResponse
    {
        return $this->result($service, $service->find($refundId, Auth::guard('web')->user()->store_id));
    }

    public function approve(Request $request, RefundService $service, string $refundId): JsonResponse
    {
        /** @var PosRequestContext $context */
        $context = $request->attributes->get('pos_context');

        return $this->result($service, $service->approve($context->terminal, $context->user, $refundId, $this->idempotencyKey($request)));
    }

    public function reject(SaleVoidRequest $request, RefundService $service, string $refundId): JsonResponse
    {
        $this->idempotencyKey($request);

        return $this->result($service, $service->reject(Auth::guard('web')->user(), $refundId, $request->validated('reason')));
    }

    private function result(RefundService $service, Refund $refund): JsonResponse
    {
        return RefundResultResource::withRemaining($refund, $service->remainingRefundable($refund->sale))->response();
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
