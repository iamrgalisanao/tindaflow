<?php

namespace App\Http\Controllers;

use App\Domain\Exceptions\IdempotencyKeyRequiredException;
use App\Domain\Exceptions\StockCountNotFoundException;
use App\Http\Controllers\Concerns\ParsesListFilters;
use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Requests\RecordStockCountLinesRequest;
use App\Http\Requests\StartStockCountRequest;
use App\Http\Resources\StockCountResource;
use App\Models\StockCount;
use App\Services\Auth\PosRequestContext;
use App\Services\Inventory\StockCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml Inventory tag, stock counts (stage 25). All of it needs STOCK_ADJUST and is scoped to the
 * actor's store; someone else's count is STOCK_COUNT_NOT_FOUND. Drafting a count is session-only; posting
 * writes the ledger, so it also needs an enrolled terminal and an Idempotency-Key.
 */
class StockCountController extends Controller
{
    use ParsesListFilters, RespondsWithPagination;

    public function list(Request $request): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        $query = StockCount::query()->where('store_id', $actor->store_id)->with('location')->withCount('lines');
        if ($request->filled('status')) {
            $this->whereOneOf($query, 'status', (string) $request->query('status'), [StockCount::OPEN, StockCount::POSTED, StockCount::CANCELLED]);
        }
        if ($request->filled('location_id')) {
            $this->whereUuid($query, 'location_id', (string) $request->query('location_id'));
        }

        return $this->paginatedResponse(
            $query->orderByDesc('created_at')->orderByDesc('id')
                ->paginate(perPage: $this->perPage($request), page: (int) $request->query('page', 1)),
            StockCountResource::class,
        );
    }

    public function create(StartStockCountRequest $request, StockCountService $service): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        $count = $service->start($actor, $request->validated('location_id'), $request->validated('note'));

        return $this->detail($actor->store_id, $count->id)->response()->setStatusCode(201);
    }

    public function get(string $stockCountId): JsonResponse
    {
        return $this->detail(Auth::guard('web')->user()->store_id, $stockCountId)->response();
    }

    public function recordLines(RecordStockCountLinesRequest $request, StockCountService $service, string $stockCountId): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        $service->recordLines($actor, $stockCountId, $request->validated('lines'));

        return $this->detail($actor->store_id, $stockCountId)->response();
    }

    public function removeLine(StockCountService $service, string $stockCountId, string $productId): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        $service->removeLine($actor, $stockCountId, $productId);

        return $this->detail($actor->store_id, $stockCountId)->response();
    }

    public function cancel(StockCountService $service, string $stockCountId): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        $service->cancel($actor, $stockCountId);

        return $this->detail($actor->store_id, $stockCountId)->response();
    }

    public function post(Request $request, StockCountService $service, string $stockCountId): JsonResponse
    {
        /** @var PosRequestContext $context */
        $context = $request->attributes->get('pos_context');
        $key = $request->header('Idempotency-Key');
        if (! $key) {
            throw IdempotencyKeyRequiredException::make();
        }

        $service->post($context->terminal, $context->user, $key, $stockCountId);

        return $this->detail($context->terminal->store_id, $stockCountId)->response();
    }

    private function detail(string $storeId, string $stockCountId): StockCountResource
    {
        $count = StockCount::where('store_id', $storeId)->with(['location', 'lines.product'])->withCount('lines')->find($stockCountId);
        if ($count === null) {
            throw StockCountNotFoundException::forId($stockCountId);
        }

        return new StockCountResource($count);
    }
}
