<?php

namespace App\Http\Controllers;

use App\Http\Requests\StockRulesRequest;
use App\Services\StoreSetup\StockRulesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml StockRules operations (ADR-017 section 1): the store's stock rules and the pre-flight count. Both need STOCK_ADJUST
 * (MANAGER and ADMIN: ADR-017 slice B, client decision 8, moved them from ADMIN-only STORE_SETTINGS_MANAGE); both act on the actor's own store, which is never a request field.
 */
class StockRulesController extends Controller
{
    public function get(StockRulesService $service): JsonResponse
    {
        return response()->json($service->current(Auth::guard('web')->user()->store_id));
    }

    public function update(StockRulesRequest $request, StockRulesService $service): JsonResponse
    {
        return response()->json($service->update(Auth::guard('web')->user(), $request->validated()));
    }
}
