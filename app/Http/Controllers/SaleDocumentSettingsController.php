<?php

namespace App\Http\Controllers;

use App\Enums\SaleDocumentMode;
use App\Http\Requests\SaleDocumentModeRequest;
use App\Http\Requests\SlipFooterRequest;
use App\Services\StoreSetup\SaleDocumentSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml SaleDocumentSettings tag (ADR-016 section 7). saleDocumentSettingsGet needs only a session (the till reads the mode
 * from the sale it just made; the settings screen reads it here). saleDocumentModeSet needs FISCAL_CONFIGURATION_MANAGE and is
 * refused while a shift or business day is open; slipFooterSet needs STORE_SETTINGS_MANAGE. All act on the actor's own store.
 */
class SaleDocumentSettingsController extends Controller
{
    public function get(SaleDocumentSettingsService $service): JsonResponse
    {
        return response()->json($service->current(Auth::guard('web')->user()->store_id));
    }

    public function setMode(SaleDocumentModeRequest $request, SaleDocumentSettingsService $service): JsonResponse
    {
        return response()->json($service->changeMode(Auth::guard('web')->user(), SaleDocumentMode::from($request->validated('sale_document_mode'))));
    }

    public function setSlipFooter(SlipFooterRequest $request, SaleDocumentSettingsService $service): JsonResponse
    {
        $footer = $request->validated('slip_footer');

        return response()->json($service->setSlipFooter(Auth::guard('web')->user(), $footer === null ? null : trim($footer)));
    }
}
