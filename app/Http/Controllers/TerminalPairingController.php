<?php

namespace App\Http\Controllers;

use App\Http\Resources\TerminalSummaryResource;
use App\Services\Terminal\TerminalCredentialCookie;
use App\Services\Terminal\TerminalPairingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * openapi.yaml Terminal tag -- remote till approval (ADR-015). The requester side (create, claim) needs only a signed-in user, of any
 * role, at the un-enrolled browser; the administrator side (list, approve, decline) needs TERMINAL_MANAGE and may be anywhere.
 */
class TerminalPairingController extends Controller
{
    public function create(Request $request, TerminalPairingService $service): JsonResponse
    {
        $result = $service->request(Auth::guard('web')->user(), $request->ip(), $request->userAgent());

        return response()->json([
            'id' => $result['request']->id,
            'code' => $result['code'],
            'secret' => $result['secret'],
            'expires_at' => $result['request']->expires_at->toIso8601String(),
        ], 201);
    }

    public function claim(Request $request, TerminalPairingService $service, string $requestId): JsonResponse
    {
        $validated = $request->validate(['secret' => ['required', 'string', 'max:200']]);

        $result = $service->claim(Auth::guard('web')->user(), $requestId, $validated['secret']);

        if ($result === null) {
            return response()->json(['status' => 'PENDING'], 202);
        }

        $response = (new TerminalSummaryResource($result['terminal']))->response();
        $response->headers->setCookie(TerminalCredentialCookie::make($result['credential']));

        return $response;
    }

    public function list(TerminalPairingService $service): JsonResponse
    {
        $pending = $service->pending(Auth::guard('web')->user()->store_id);

        return response()->json(['data' => $pending->map(fn ($pairing) => [
            'id' => $pairing->id,
            'requested_by' => ['id' => $pairing->requester?->id, 'name' => $pairing->requester?->name, 'role' => $pairing->requester?->role],
            'ip_address' => $pairing->ip_address,
            'user_agent' => $pairing->user_agent,
            'requested_at' => $pairing->created_at?->toIso8601String(),
            'expires_at' => $pairing->expires_at->toIso8601String(),
        ])->values()]);
    }

    public function approve(Request $request, TerminalPairingService $service, string $requestId): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'terminal_id' => ['required', 'uuid'],
        ]);

        $approved = $service->approve(Auth::guard('web')->user(), $requestId, $validated['code'], $validated['terminal_id']);

        return response()->json(['id' => $approved->id, 'status' => $approved->status, 'terminal_id' => $approved->terminal_id]);
    }

    public function reject(TerminalPairingService $service, string $requestId): JsonResponse
    {
        $rejected = $service->reject(Auth::guard('web')->user(), $requestId);

        return response()->json(['id' => $rejected->id, 'status' => $rejected->status]);
    }
}
