<?php

namespace App\Http\Controllers;

use App\Domain\Exceptions\IdempotencyKeyRequiredException;
use App\Http\Controllers\Concerns\ParsesListFilters;
use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Requests\SaleVoidRequest;
use App\Http\Resources\VoidResultResource;
use App\Http\Resources\VoidSummaryResource;
use App\Models\Sale;
use App\Models\SaleVoid;
use App\Services\Auth\PosRequestContext;
use App\Services\Sales\VoidService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml voidList / voidGet / voidApprove / voidReject. The reads mirror saleGet: any session in
 * the store. Approve is the fiscal execution point and needs an enrolled terminal; reject is a human
 * decision and does not (VoidService::reject()).
 */
class VoidController extends Controller
{
    use ParsesListFilters, RespondsWithPagination;

    public function list(Request $request): JsonResponse
    {
        $actor = Auth::guard('web')->user();
        $query = SaleVoid::query()->whereIn('sale_id', Sale::where('store_id', $actor->store_id)->select('id'));

        if ($request->filled('status')) {
            $this->whereOneOf($query, 'status', (string) $request->query('status'), ['REQUESTED', 'APPROVED', 'REJECTED', 'VOIDED']);
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
            VoidSummaryResource::class,
        );
    }

    public function get(VoidService $service, string $voidId): JsonResponse
    {
        $void = $service->find($voidId, Auth::guard('web')->user()->store_id);

        return (new VoidResultResource($void))->response();
    }

    public function approve(Request $request, VoidService $service, string $voidId): JsonResponse
    {
        /** @var PosRequestContext $context */
        $context = $request->attributes->get('pos_context');

        $void = $service->approve($context->terminal, $context->user, $voidId, $this->idempotencyKey($request));

        return (new VoidResultResource($void))->response();
    }

    public function reject(SaleVoidRequest $request, VoidService $service, string $voidId): JsonResponse
    {
        $this->idempotencyKey($request);

        $void = $service->reject(Auth::guard('web')->user(), $voidId, $request->validated('reason'));

        return (new VoidResultResource($void))->response();
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
