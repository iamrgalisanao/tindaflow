<?php

namespace App\Http\Controllers;

use App\Domain\Exceptions\FiscalDayNotFoundException;
use App\Domain\Exceptions\IdempotencyKeyRequiredException;
use App\Http\Controllers\Concerns\ParsesListFilters;
use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Resources\FiscalDayResource;
use App\Http\Resources\ZReadingResource;
use App\Models\FiscalDay;
use App\Models\ZReading;
use App\Services\Auth\PosRequestContext;
use App\Services\FiscalDay\FiscalDayCloseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml FiscalDay tag. fiscalDayClose is terminal-scoped. fiscalDayList/fiscalDayGet/
 * fiscalDayZReadingGet are session-only reads (operation-inventory.md) scoped to the actor's store, like
 * saleList/saleGet; a fiscal day of another store is FISCAL_DAY_NOT_FOUND
 * (docs/06-backend/stage-22-shift-fiscal-day-history.md).
 *
 * fiscalDayCurrentGet is deliberately still not implemented: its frozen 404 is labelled FISCAL_DAY_NOT_OPEN,
 * which error-catalog.md registers as a 409, so building it would mint a new code for an already-frozen
 * response (the NO_CURRENT_SHIFT situation). The frontend obtains the fiscal_day_id it needs to close from the
 * shift lifecycle it already holds in state (shiftOpen/shiftCurrentGet both return it inline).
 */
class FiscalDayController extends Controller
{
    use ParsesListFilters, RespondsWithPagination;

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

    /**
     * openapi.yaml fiscalDayList: history, most recent business date first. `from`/`to` bound the business date
     * (inclusive).
     */
    public function list(Request $request): JsonResponse
    {
        $query = FiscalDay::where('store_id', Auth::guard('web')->user()->store_id)
            ->orderByDesc('business_date')->orderByDesc('opened_at')->orderByDesc('id');

        if ($request->filled('terminal_id')) {
            $this->whereUuid($query, 'terminal_id', (string) $request->query('terminal_id'));
        }
        if ($from = $this->dateFilter($request->query('from'))) {
            $query->where('business_date', '>=', $from->toDateString());
        }
        if ($to = $this->dateFilter($request->query('to'))) {
            $query->where('business_date', '<=', $to->toDateString());
        }

        return $this->paginatedResponse(
            $query->paginate(perPage: $this->perPage($request), page: (int) $request->query('page', 1)),
            FiscalDayResource::class,
        );
    }

    public function get(string $fiscalDayId): JsonResponse
    {
        return (new FiscalDayResource($this->findInStore($fiscalDayId)))->response();
    }

    public function getZReading(string $fiscalDayId): JsonResponse
    {
        $fiscalDay = FiscalDay::where('store_id', Auth::guard('web')->user()->store_id)->find($fiscalDayId);
        $zReading = $fiscalDay === null ? null : ZReading::where('fiscal_day_id', $fiscalDay->id)->first();

        if ($zReading === null) {
            throw FiscalDayNotFoundException::forId($fiscalDayId);
        }

        return (new ZReadingResource($zReading))->response();
    }

    private function findInStore(string $fiscalDayId): FiscalDay
    {
        return FiscalDay::where('store_id', Auth::guard('web')->user()->store_id)->find($fiscalDayId)
            ?? throw FiscalDayNotFoundException::forId($fiscalDayId);
    }
}
