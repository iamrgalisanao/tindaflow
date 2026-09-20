<?php

namespace App\Http\Controllers;

use App\Domain\Exceptions\IdempotencyKeyRequiredException;
use App\Domain\Exceptions\StockTransferNotFoundException;
use App\Http\Controllers\Concerns\ParsesListFilters;
use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Requests\CreateStockTransferRequest;
use App\Http\Resources\StockTransferResource;
use App\Models\StockTransfer;
use App\Services\Auth\PosRequestContext;
use App\Services\Inventory\StockTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * openapi.yaml Inventory tag, stock transfers between locations of one store (stage 25). STOCK_ADJUST and
 * store-scoped throughout. Reading is session-only; creating writes the ledger, so it also needs an enrolled
 * terminal and an Idempotency-Key. There is no update or delete: a wrong transfer is corrected by transferring back.
 */
class StockTransferController extends Controller
{
    use ParsesListFilters, RespondsWithPagination;

    public function list(Request $request): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        $query = StockTransfer::query()->where('stock_transfers.store_id', $actor->store_id)->with(['fromLocation', 'toLocation'])->withCount('lines');

        if ($request->filled('location_id')) {
            $locationId = (string) $request->query('location_id');
            Str::isUuid($locationId)
                ? $query->where(fn ($either) => $either->where('from_location_id', $locationId)->orWhere('to_location_id', $locationId))
                : $query->whereRaw('1 = 0');
        }
        if ($request->filled('product_id')) {
            $productId = (string) $request->query('product_id');
            Str::isUuid($productId)
                ? $query->whereHas('lines', fn ($lines) => $lines->where('product_id', $productId))
                : $query->whereRaw('1 = 0');
        }
        if ($from = $this->dateFilter($request->query('from'))) {
            $query->where('created_at', '>=', $from->startOfDay());
        }
        if ($to = $this->dateFilter($request->query('to'))) {
            $query->where('created_at', '<=', $to->endOfDay());
        }

        return $this->paginatedResponse(
            $query->orderByDesc('created_at')->orderByDesc('id')
                ->paginate(perPage: $this->perPage($request), page: (int) $request->query('page', 1)),
            StockTransferResource::class,
        );
    }

    public function get(string $stockTransferId): JsonResponse
    {
        return $this->detail(Auth::guard('web')->user()->store_id, $stockTransferId)->response();
    }

    public function create(CreateStockTransferRequest $request, StockTransferService $service): JsonResponse
    {
        /** @var PosRequestContext $context */
        $context = $request->attributes->get('pos_context');
        $key = $request->header('Idempotency-Key');
        if (! $key) {
            throw IdempotencyKeyRequiredException::make();
        }

        $transfer = $service->create($context->terminal, $context->user, $key, $request->validated());

        return $this->detail($context->terminal->store_id, $transfer->id)->response()->setStatusCode(201);
    }

    private function detail(string $storeId, string $stockTransferId): StockTransferResource
    {
        $transfer = StockTransfer::where('store_id', $storeId)->with(['fromLocation', 'toLocation', 'lines.product'])->withCount('lines')->find($stockTransferId);
        if ($transfer === null) {
            throw StockTransferNotFoundException::forId($stockTransferId);
        }

        return new StockTransferResource($transfer);
    }
}
