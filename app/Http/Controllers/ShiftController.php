<?php

namespace App\Http\Controllers;

use App\Domain\Exceptions\IdempotencyKeyRequiredException;
use App\Domain\Exceptions\NoCurrentShiftException;
use App\Domain\Exceptions\ShiftNotFoundException;
use App\Http\Controllers\Concerns\ParsesListFilters;
use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Requests\CreateCashMovementRequest;
use App\Http\Requests\ShiftCloseRequest;
use App\Http\Requests\ShiftOpenRequest;
use App\Http\Resources\CashMovementResource;
use App\Http\Resources\FiscalDayResource;
use App\Http\Resources\ShiftResource;
use App\Http\Resources\XReadingResource;
use App\Models\Shift;
use App\Models\Terminal;
use App\Models\XReading;
use App\Services\Auth\PosRequestContext;
use App\Services\Shift\CashMovementService;
use App\Services\Shift\ShiftCloseService;
use App\Services\Shift\ShiftOpenService;
use App\Services\Shift\ShiftXReadingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml Shifts tag. terminal_id/cashier_id are always resolved
 * from PosRequestContext (A4), never the request body -- same
 * discipline as SaleController.
 *
 * shiftList/shiftGet/shiftXReadingList are session-only reads (operation-inventory.md: not terminal
 * enrolled, no capability) scoped to the actor's store, like saleList/saleGet. A shift has no store_id of
 * its own; it belongs to its terminal's store. One in another store is SHIFT_NOT_FOUND
 * (docs/06-backend/stage-22-shift-fiscal-day-history.md). A user without REPORT_VIEW (a cashier) reads only the
 * shifts they worked: someone else's is SHIFT_NOT_FOUND too, so a till never reveals another cashier's drawer
 * (docs/06-backend/stage-24-owner-decisions.md, decision 3).
 */
class ShiftController extends Controller
{
    use ParsesListFilters, RespondsWithPagination;

    public function open(ShiftOpenRequest $request, ShiftOpenService $service): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! $idempotencyKey) {
            throw IdempotencyKeyRequiredException::make();
        }

        /** @var PosRequestContext $context */
        $context = $request->attributes->get('pos_context');

        $result = $service->open(
            $context->terminal->id,
            $context->user->id,
            $idempotencyKey,
            $request->validated(),
        );

        return response()->json([
            'shift' => new ShiftResource($result['shift']),
            'fiscal_day' => new FiscalDayResource($result['shift']->fiscalDay),
            'fiscal_day_was_opened' => $result['fiscal_day_was_opened'],
        ], 201);
    }

    public function current(Request $request): JsonResponse
    {
        /** @var PosRequestContext $context */
        $context = $request->attributes->get('pos_context');

        $shift = Shift::where('terminal_id', $context->terminal->id)->where('status', 'OPEN')->first();

        if ($shift === null) {
            throw NoCurrentShiftException::forTerminal($context->terminal->id);
        }

        return (new ShiftResource($shift))->response();
    }

    public function close(ShiftCloseRequest $request, ShiftCloseService $service, string $shiftId): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! $idempotencyKey) {
            throw IdempotencyKeyRequiredException::make();
        }

        /** @var PosRequestContext $context */
        $context = $request->attributes->get('pos_context');

        $result = $service->close($shiftId, $context->terminal->id, $context->user->id, $idempotencyKey, $request->validated());

        return response()->json([
            'shift' => new ShiftResource($result['shift']),
            'x_reading' => new XReadingResource($result['x_reading']),
        ]);
    }

    public function createCashMovement(CreateCashMovementRequest $request, CashMovementService $service, string $shiftId): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! $idempotencyKey) {
            throw IdempotencyKeyRequiredException::make();
        }

        /** @var PosRequestContext $context */
        $context = $request->attributes->get('pos_context');

        $movement = $service->create($shiftId, $context->terminal->id, $context->user, $idempotencyKey, $request->validated());

        return (new CashMovementResource($movement))->response()->setStatusCode(201);
    }

    /**
     * openapi.yaml shiftList: history, newest first. `from`/`to` bound the day the shift was opened (inclusive,
     * application timezone). `fiscal_day_id` is an additive filter so a fiscal day can list its own shifts.
     */
    public function list(Request $request): JsonResponse
    {
        $query = $this->storeShifts()->orderByDesc('opened_at')->orderByDesc('id');

        foreach (['terminal_id', 'cashier_id', 'fiscal_day_id'] as $column) {
            if ($request->filled($column)) {
                $this->whereUuid($query, $column, (string) $request->query($column));
            }
        }
        if ($from = $this->dateFilter($request->query('from'))) {
            $query->where('opened_at', '>=', $from->startOfDay());
        }
        if ($to = $this->dateFilter($request->query('to'))) {
            $query->where('opened_at', '<=', $to->endOfDay());
        }

        return $this->paginatedResponse(
            $query->paginate(perPage: $this->perPage($request), page: (int) $request->query('page', 1)),
            ShiftResource::class,
        );
    }

    public function get(string $shiftId): JsonResponse
    {
        return (new ShiftResource($this->findInStore($shiftId)))->response();
    }

    public function listXReadings(string $shiftId): JsonResponse
    {
        $shift = $this->findInStore($shiftId);

        return response()->json(XReadingResource::collection(XReading::where('shift_id', $shift->id)->orderBy('generated_at')->orderBy('id')->get()));
    }

    /** @return Builder<Shift> */
    private function storeShifts(): Builder
    {
        $actor = Auth::guard('web')->user();

        return Shift::query()
            ->whereIn('terminal_id', Terminal::where('store_id', $actor->store_id)->select('id'))
            ->when(! $actor->can('REPORT_VIEW'), fn (Builder $shifts) => $shifts->where('cashier_id', $actor->id));
    }

    private function findInStore(string $shiftId): Shift
    {
        return $this->storeShifts()->find($shiftId) ?? throw ShiftNotFoundException::forId($shiftId);
    }

    public function createXReading(Request $request, ShiftXReadingService $service, string $shiftId): JsonResponse
    {
        /** @var PosRequestContext $context */
        $context = $request->attributes->get('pos_context');

        $reading = $service->generate($shiftId, $context->terminal->id, $context->user->id);

        return (new XReadingResource($reading))->response()->setStatusCode(201);
    }
}
