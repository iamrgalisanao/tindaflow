<?php

namespace App\Http\Controllers;

use App\Services\Auth\PosRequestContext;
use App\Services\StoreSetup\StoreSetupReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * New forward-committed StoreSetup tag (no prior openapi.yaml draft --
 * see docs/06-ui/stage-8-store-setup.md). Terminal-scoped like
 * shiftCurrentGet, but deliberately no x-capability -- a cashier on an
 * enrolled terminal needs to see this too (POS Checkout Blocked state),
 * not just an admin.
 */
class StoreSetupController extends Controller
{
    public function readiness(Request $request, StoreSetupReadinessService $service): JsonResponse
    {
        /** @var PosRequestContext $context */
        $context = $request->attributes->get('pos_context');

        return response()->json($service->forTerminal($context->store()->id, $context->terminal->id));
    }
}
