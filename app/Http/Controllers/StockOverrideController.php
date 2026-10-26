<?php

namespace App\Http\Controllers;

use App\Http\Requests\StockOverrideGrantRequest;
use App\Services\Auth\PosRequestContext;
use App\Services\Overrides\OverrideCodeService;
use App\Services\Overrides\StockOverrideService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml StockOverride operations (ADR-017 section 9, slice A): the manager override of a blocked sale and nothing else.
 *   stockOverrideApproverList  any signed-in till user: the names of the approvers who hold STOCK_SALE_OVERRIDE and a code;
 *   stockOverrideGrant         terminal-scoped: a named approver's code + the cart -> a single-use grant bound to that cart's shortfall.
 * The approver's own code is set and reset with the existing override-code operations (it is one code for every override). The terminal and
 * the cashier come from the authoritative context, never the body. A code or a token is never written to a log or to the audit log.
 */
class StockOverrideController extends Controller
{
    public function approvers(OverrideCodeService $codes): JsonResponse
    {
        return response()->json(['data' => $codes->approversFor(Auth::guard('web')->user(), StockOverrideService::CAPABILITY)->values()]);
    }

    public function grant(StockOverrideGrantRequest $request, StockOverrideService $overrides): JsonResponse
    {
        /** @var PosRequestContext $context */
        $context = $request->attributes->get('pos_context');
        $validated = $request->validated();

        $result = $overrides->grantForCart($context->user, $context->terminal, $validated['approver_id'], $validated['code'], $validated['items']);

        return response()->json([
            'grant_id' => $result['grant']->id,
            'token' => $result['token'],
            'expires_at' => $result['grant']->expires_at->toIso8601String(),
            'approver' => ['id' => $result['approver']->id, 'name' => $result['approver']->name],
            'shortfalls' => $result['grant']->shortfalls,
        ], 201);
    }
}
