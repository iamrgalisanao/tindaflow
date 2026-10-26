<?php

namespace App\Http\Controllers;

use App\Domain\Exceptions\IdempotencyKeyRequiredException;
use App\Http\Controllers\Concerns\ParsesListFilters;
use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Requests\StockAdjustmentRequest;
use App\Http\Requests\StockReceiptRequest;
use App\Http\Resources\StockBalanceResource;
use App\Http\Resources\StockMovementResource;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Services\Auth\PosRequestContext;
use App\Services\Inventory\StockFreshness;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockRules;
use App\Services\Inventory\StockService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml Inventory tag. The three reads need only the session (operation-inventory.md) and are
 * scoped to the actor's store through the product. The two writes are terminal-scoped and
 * STOCK_ADJUST-gated (ADMIN and MANAGER): identity comes from PosRequestContext, never the body.
 * Unknown filter values match nothing rather than being ignored, so a typo never widens a listing.
 */
class InventoryController extends Controller
{
    use ParsesListFilters, RespondsWithPagination;

    /** On-hand balances of RUNNING-stock products; a product that does not track inventory, or counts daily stock, has none to report here. */
    public function stock(Request $request): JsonResponse
    {
        return $this->paginatedResponse(
            $this->balancesQuery($request)->paginate(perPage: $this->perPage($request), page: (int) $request->query('page', 1)),
            StockBalanceResource::class,
        );
    }

    /**
     * Per-location balances at or below the product's low-stock threshold: its own reorder level when one was set, else the store's default
     * (ADR-017 section 2; the same number the till's "N left" warning uses).
     */
    public function lowStock(Request $request): JsonResponse
    {
        $query = $this->balancesQuery($request)
            ->whereRaw('stock_balances.quantity_on_hand <= COALESCE(products.reorder_level, ?)', [StockRules::forStore(Auth::guard('web')->user()->store_id)->defaultThreshold]);

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
        if ($from = $this->dateFilter($request->query('from'))) {
            $query->where('stock_movements.occurred_at', '>=', $from->startOfDay());
        }
        if ($to = $this->dateFilter($request->query('to'))) {
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
            ->where('products.stock_mode', 'RUNNING') // daily items and the dishes' hidden pools have their own date-keyed balances, not running stock
            ->select('stock_balances.*')
            // The effective low-stock threshold travels with the row, so the screen never has to know the store default.
            ->selectRaw('COALESCE(products.reorder_level, ?) AS low_stock_threshold', [StockRules::forStore($actor->store_id)->defaultThreshold])
            ->orderBy('products.name')->orderBy('stock_balances.product_id')->orderBy('stock_balances.location_id');

        // ADR-017 slice C: the rough shelf life and the age of the latest receipt travel with the row (one correlated subquery, no N+1), so the
        // resource can say "Check freshness" without another read. `?check_freshness=true` narrows to the flagged rows.
        $query->addSelect('products.shelf_life_days');
        StockFreshness::select($query);
        if ($request->boolean('check_freshness')) {
            StockFreshness::whereFlagged($query, 'stock_balances.quantity_on_hand');
        }

        if ($request->filled('product_id')) {
            $this->whereUuid($query, 'stock_balances.product_id', (string) $request->query('product_id'));
        }

        return $query;
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
