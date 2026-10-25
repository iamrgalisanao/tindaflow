<?php

namespace App\Http\Controllers;

use App\Domain\Money;
use App\Http\Requests\CreditOverrideGrantRequest;
use App\Http\Requests\OverrideCodeResetRequest;
use App\Http\Requests\OverrideCodeSetRequest;
use App\Services\Auth\PosRequestContext;
use App\Services\Overrides\CreditOverrideService;
use App\Services\Overrides\OverrideCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml CreditOverride operations (ADR-016 section 6, slice 3.3): the supervisor override of the credit limit and nothing else.
 *   creditOverrideApproverList  any signed-in till user: the names of the approvers who hold a code (no ids of anything else, no codes);
 *   creditOverrideGrant         terminal-scoped: a named approver's code -> a single-use grant and its token (shown once);
 *   creditOverrideCodeGet/Set   CREDIT_LIMIT_OVERRIDE: an approver's own code, set after re-entering the account password;
 *   creditOverrideCodeList/Reset  USER_MANAGE: which users hold a code; clear one (the approver then sets a new one).
 * The terminal and the cashier come from the authoritative context, never the body. A code, a password or a token is never written to a
 * log or to the audit log, and the code is never returned by anything.
 */
class CreditOverrideController extends Controller
{
    public function approvers(OverrideCodeService $codes): JsonResponse
    {
        return response()->json(['data' => $codes->approversFor(Auth::guard('web')->user())->values()]);
    }

    public function grant(CreditOverrideGrantRequest $request, CreditOverrideService $overrides): JsonResponse
    {
        /** @var PosRequestContext $context */
        $context = $request->attributes->get('pos_context');
        $validated = $request->validated();

        $result = $overrides->grant(
            $context->user, $context->terminal, $validated['approver_id'], $validated['code'], $validated['employee_id'], Money::fromApiString($validated['amount']),
        );

        return response()->json([
            'grant_id' => $result['grant']->id,
            'token' => $result['token'],
            'expires_at' => $result['grant']->expires_at->toIso8601String(),
            'max_amount' => $result['grant']->max_amount,
            'employee_id' => $result['grant']->employee_id,
            'approver' => ['id' => $result['approver']->id, 'name' => $result['approver']->name],
        ], 201);
    }

    public function codeGet(OverrideCodeService $codes): JsonResponse
    {
        return response()->json($codes->statusOf(Auth::guard('web')->user()));
    }

    public function codeSet(OverrideCodeSetRequest $request, OverrideCodeService $codes): JsonResponse
    {
        $validated = $request->validated();

        return response()->json($codes->setOwn(Auth::guard('web')->user(), $validated['password'], $validated['code']));
    }

    public function codeList(OverrideCodeService $codes): JsonResponse
    {
        return response()->json(['data' => $codes->statusesFor(Auth::guard('web')->user())->values()]);
    }

    public function codeReset(OverrideCodeResetRequest $request, OverrideCodeService $codes, string $userId): JsonResponse
    {
        return response()->json($codes->resetByAdmin(Auth::guard('web')->user(), $request->validated()['password'], $userId));
    }
}
