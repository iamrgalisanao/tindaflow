<?php

namespace App\Http\Controllers;

use App\Services\Inventory\StockAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml stockAvailabilityGet (ADR-017 section 8, slice A): what is left of every active tracked product, plus the store's stock rules,
 * for the till's tiles and cart. Any signed-in user of the store (a session, no capability): quantities and flags only, never a cost. Advisory;
 * checkout is the authority.
 */
class StockAvailabilityController extends Controller
{
    public function show(StockAvailabilityService $availability): JsonResponse
    {
        return response()->json($availability->forTill(Auth::guard('web')->user()->store_id));
    }
}
