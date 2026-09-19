<?php

namespace App\Http\Controllers;

use App\Domain\Exceptions\IdempotencyKeyRequiredException;
use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Requests\StockAdjustmentRequest;
use App\Http\Requests\StockReceiptRequest;
use App\Http\Resources\StockBalanceResource;
use App\Http\Resources\StockMovementResource;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Services\Auth\PosRequestContext;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

/**
 * openapi.yaml Inventory tag. The three reads need only the session (operation-inventory.md) and are
 * scoped to the actor's store through the product. The two writes are terminal-scoped and
 * STOCK_ADJUST-gated (ADMIN and MANAGER): identity comes from PosRequestContext, never the body.
 * Unknown filter values match nothing rather than being ignored, so a typo never widens a listing.
 */
class InventoryController extends Controller
{
    use RespondsWithPagination;

    /** On-hand balances of tracked products; a product that does not track inventory has no stock to report. */
    public function stock(Request $request): JsonResponse
    {
        return $this->paginatedResponse(
            $this->balancesQuery($request)->paginate(perPage: $this->perPage($request), page: (int) $request->query('page', 1)),
            StockBalanceResource::class,
        );
    }

    /** Per-location balances at or below the product's reorder level. */
    public function lowStock(Request $request): JsonResponse
    {
        $query = $this->balancesQuery($request)
            ->whereColumn('stock_balances.quantity_on_hand', '<=', 'products.reorder_level');

        return $this->paginatedResponse(
            $query->paginate(perPage: $this->perPage($request), page: (int) $request->query('page', 1)),
            StockBalanceResource::class,
        );
    }

    public function movements(Request $request): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        $query = StockMovement::query()
            ->join('products', 'products.id', '=', 'stock_movements.product_id')
            ->where('products.store_id', $actor->store_id)
            ->select('stock_movements.*');

        if ($request->filled('product_id')) {
            $this->whereUuid($query, 'stock_movements.product_id', (string) $request->query('product_id'));
        }
        if ($request->filled('movement_type')) {
            $type = (string) $request->query('movement_type');
            in_array($type, [...StockLedger::INFLOW, ...StockLedger::OUTFLOW], true)
                ? $query->where('stock_movements.movement_type', $type)
                : $query->whereRaw('1 = 0');
        }
        if ($from = $this->date($request->query('from'))) {
            $query->where('stock_movements.occurred_at', '>=', $from->startOfDay());
        }
        if ($to = $this->date($request->query('to'))) {
            $query->where('stock_movements.occurred_at', '<=', $to->endOfDay());
        }

        return $this->paginatedResponse(
            $query->orderByDesc('stock_movements.occurred_at')->orderByDesc('stock_movements.id')
                ->paginate(perPage: $this->perPage($request), page: (int) $request->query('page', 1)),
            StockMovementResource::class,
        );
    }

    public function receive(StockReceiptRequest $request, StockService $service): JsonResponse
    {
        $context = $this->context($request);

        return (new StockMovementResource(
            $service->receive($context->terminal, $context->user, $this->idempotencyKey($request), $request->validated()),
        ))->response()->setStatusCode(201);
    }

    public function adjust(StockAdjustmentRequest $request, StockService $service): JsonResponse
    {
        $context = $this->context($request);

        return (new StockMovementResource(
            $service->adjust($context->terminal, $context->user, $this->idempotencyKey($request), $request->validated()),
        ))->response()->setStatusCode(201);
    }

    private function balancesQuery(Request $request): Builder
    {
        $actor = Auth::guard('web')->user();

        $query = StockBalance::query()
            ->join('products', 'products.id', '=', 'stock_balances.product_id')
            ->where('products.store_id', $actor->store_id)
            ->where('products.track_inventory', true)
            ->select('stock_balances.*')
            ->orderBy('products.name')->orderBy('stock_balances.product_id')->orderBy('stock_balances.location_id');

        if ($request->filled('product_id')) {
            $this->whereUuid($query, 'stock_balances.product_id', (string) $request->query('product_id'));
        }

        return $query;
    }

    /** A value that is not a UUID can match no row; comparing it to a uuid column would be a database error. */
    private function whereUuid(Builder $query, string $column, string $value): void
    {
        Str::isUuid($value) ? $query->where($column, $value) : $query->whereRaw('1 = 0');
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value, config('app.timezone'));
        } catch (Throwable) {
            return null;
        }
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
