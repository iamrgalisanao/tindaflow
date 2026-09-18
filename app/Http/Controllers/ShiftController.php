<?php

namespace App\Http\Controllers;

use App\Domain\Exceptions\IdempotencyKeyRequiredException;
use App\Domain\Exceptions\NoCurrentShiftException;
use App\Http\Requests\CreateCashMovementRequest;
use App\Http\Requests\ShiftCloseRequest;
use App\Http\Requests\ShiftOpenRequest;
use App\Http\Resources\CashMovementResource;
use App\Http\Resources\FiscalDayResource;
use App\Http\Resources\ShiftResource;
use App\Http\Resources\XReadingResource;
use App\Models\Shift;
use App\Models\XReading;
use App\Services\Auth\PosRequestContext;
use App\Services\Shift\CashMovementService;
use App\Services\Shift\ShiftCloseService;
use App\Services\Shift\ShiftOpenService;
use App\Services\Shift\ShiftXReadingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * openapi.yaml Shifts tag. terminal_id/cashier_id are always resolved
 * from PosRequestContext (A4), never the request body -- same
 * discipline as SaleController. shiftGet/shiftList are deliberately not
 * implemented in this pass -- pure historical browsing, a natural fit
 * for a future Reports module (see docs/06-backend/stage-9-shift-close-fiscal-day-close.md).
 */
class ShiftController extends Controller
{
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

    public function listXReadings(Request $request, string $shiftId): JsonResponse
    {
        /** @var PosRequestContext $context */
        $context = $request->attributes->get('pos_context');

        $readings = XReading::where('shift_id', $shiftId)->where('terminal_id', $context->terminal->id)->orderBy('generated_at')->get();

        return response()->json(XReadingResource::collection($readings));
    }

    public function createXReading(Request $request, ShiftXReadingService $service, string $shiftId): JsonResponse
    {
        /** @var PosRequestContext $context */
        $context = $request->attributes->get('pos_context');

        $reading = $service->generate($shiftId, $context->terminal->id, $context->user->id);

        return (new XReadingResource($reading))->response()->setStatusCode(201);
    }
}
