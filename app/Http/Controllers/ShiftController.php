<?php

namespace App\Http\Controllers;

use App\Domain\Exceptions\IdempotencyKeyRequiredException;
use App\Domain\Exceptions\NoCurrentShiftException;
use App\Http\Requests\ShiftOpenRequest;
use App\Http\Resources\FiscalDayResource;
use App\Http\Resources\ShiftResource;
use App\Models\Shift;
use App\Services\Auth\PosRequestContext;
use App\Services\Shift\ShiftOpenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * openapi.yaml Shifts tag. terminal_id/cashier_id are always resolved
 * from PosRequestContext (A4), never the request body -- same
 * discipline as SaleController.
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
}
