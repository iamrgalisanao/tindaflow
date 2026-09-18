<?php

namespace App\Http\Controllers;

use App\Domain\Exceptions\FiscalDayNotFoundException;
use App\Domain\Exceptions\IdempotencyKeyRequiredException;
use App\Http\Resources\FiscalDayResource;
use App\Http\Resources\ZReadingResource;
use App\Models\FiscalDay;
use App\Models\ZReading;
use App\Services\Auth\PosRequestContext;
use App\Services\FiscalDay\FiscalDayCloseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * openapi.yaml FiscalDay tag. fiscalDayCurrentGet/fiscalDayList/
 * fiscalDayGet are deliberately not implemented in this pass -- pure
 * historical browsing, a natural fit for a future Reports module (see
 * docs/06-backend/stage-9-shift-close-fiscal-day-close.md). The
 * frontend obtains the fiscal_day_id it needs to close from the shift
 * lifecycle it already holds in state (shiftOpen/shiftCurrentGet both
 * return it inline), so fiscalDayCurrentGet is not required to reach a
 * working close flow.
 */
class FiscalDayController extends Controller
{
    public function close(Request $request, FiscalDayCloseService $service, string $fiscalDayId): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');
        if (! $idempotencyKey) {
            throw IdempotencyKeyRequiredException::make();
        }

        /** @var PosRequestContext $context */
        $context = $request->attributes->get('pos_context');

        $result = $service->close($fiscalDayId, $context->terminal->id, $context->user->id, $idempotencyKey);

        return response()->json([
            'fiscal_day' => new FiscalDayResource($result['fiscal_day']),
            'z_reading' => new ZReadingResource($result['z_reading']),
        ]);
    }

    public function getZReading(Request $request, string $fiscalDayId): JsonResponse
    {
        /** @var PosRequestContext $context */
        $context = $request->attributes->get('pos_context');

        $fiscalDay = FiscalDay::where('terminal_id', $context->terminal->id)->find($fiscalDayId);
        $zReading = $fiscalDay === null ? null : ZReading::where('fiscal_day_id', $fiscalDay->id)->first();

        if ($zReading === null) {
            throw FiscalDayNotFoundException::forId($fiscalDayId);
        }

        return (new ZReadingResource($zReading))->response();
    }
}
