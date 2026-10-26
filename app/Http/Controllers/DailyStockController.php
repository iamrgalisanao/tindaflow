<?php

namespace App\Http\Controllers;

use App\Domain\Exceptions\IdempotencyKeyRequiredException;
use App\Http\Requests\DailyStockApprovalRequest;
use App\Http\Requests\DailyStockEntryRequest;
use App\Services\Auth\PosRequestContext;
use App\Services\Inventory\DailyStockService;
use App\Services\Overrides\OverrideCodeService;
use App\Services\Overrides\StockEntryApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml DailyStock operations (ADR-017 slice B):
 *   dailyStockGet            any signed-in user: today's grid (cooked, sold, remaining, yesterday's cooked). Quantities only.
 *   dailyStockApproverList   any signed-in user: the managers a cashier can name (names only).
 *   dailyStockApprovalCreate terminal-scoped: a named manager's code -> a single-use token bound to the terminal, the cashier and today's stock date.
 *   dailyStockEntryCreate    terminal-scoped, idempotent: save the grid. A user holding STOCK_ADJUST enters directly; a cashier presents the token.
 * The actor and the terminal come from the authoritative context, never the body. A code or a token is never written to a log or to the audit log.
 */
class DailyStockController extends Controller
{
    public function grid(DailyStockService $service): JsonResponse
    {
        return response()->json($service->grid(Auth::guard('web')->user()));
    }

    public function approvers(OverrideCodeService $codes): JsonResponse
    {
        return response()->json(['data' => $codes->approversFor(Auth::guard('web')->user(), StockEntryApprovalService::CAPABILITY)->values()]);
    }

    public function approve(DailyStockApprovalRequest $request, StockEntryApprovalService $approvals): JsonResponse
    {
        /** @var PosRequestContext $context */
        $context = $request->attributes->get('pos_context');
        $validated = $request->validated();

        $result = $approvals->grant($context->user, $context->terminal, $validated['approver_id'], $validated['code']);

        return response()->json([
            'grant_id' => $result['grant']->id,
            'token' => $result['token'],
            'stock_date' => $result['grant']->stock_date->toDateString(),
            'expires_at' => $result['grant']->expires_at->toIso8601String(),
            'approver' => ['id' => $result['approver']->id, 'name' => $result['approver']->name],
        ], 201);
    }

    public function enter(DailyStockEntryRequest $request, DailyStockService $service): JsonResponse
    {
        /** @var PosRequestContext $context */
        $context = $request->attributes->get('pos_context');

        $entry = $service->enter($context->terminal, $context->user, $this->idempotencyKey($request), $request->validated());

        return response()->json($entry + ['grid' => $service->grid($context->user)], 201);
    }

    private function idempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        if (! $key) {
            throw IdempotencyKeyRequiredException::make();
        }

        return $key;
    }
}
