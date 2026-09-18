<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateEnrollmentTokenRequest;
use App\Http\Requests\EnrollTerminalRequest;
use App\Http\Resources\TerminalEnrollmentTokenResource;
use App\Http\Resources\TerminalSummaryResource;
use App\Models\Terminal;
use App\Services\Terminal\TerminalEnrollmentService;
use App\Services\Terminal\TerminalEnrollmentTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;

/**
 * openapi.yaml Terminal tag -- the enrollment lifecycle (ADR-011).
 * Management operations (create-enrollment-token/enroll/list/get/revoke)
 * are scoped to the authenticated actor's own store_id -- domain-model.md
 * SS2.1: "every table is store_id-scoped"; terminalList's own summary
 * ("List terminals for the store") confirms this applies to Terminal
 * management specifically, not just invented here.
 */
class TerminalController extends Controller
{
    private const CREDENTIAL_COOKIE = 'tindaflow_terminal';

    public function createEnrollmentToken(CreateEnrollmentTokenRequest $request, TerminalEnrollmentTokenService $service): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        $terminal = Terminal::where('store_id', $actor->store_id)
            ->findOrFail($request->validated('terminal_id'));

        $issued = $service->issue($terminal, $actor);

        return (new TerminalEnrollmentTokenResource($issued['model'], $issued['token']))
            ->response()
            ->setStatusCode(201);
    }

    public function enroll(EnrollTerminalRequest $request, TerminalEnrollmentService $service): JsonResponse
    {
        $result = $service->enroll($request->validated('token'));

        $response = (new TerminalSummaryResource($result['terminal']))->response();

        $response->headers->setCookie($this->credentialCookie($result['credential']));

        return $response;
    }

    public function current(Request $request): JsonResponse
    {
        return (new TerminalSummaryResource($request->attributes->get('terminal')))->response();
    }

    public function list(Request $request): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        $query = Terminal::where('store_id', $actor->store_id);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        $paginator = $query->paginate(
            perPage: (int) $request->query('per_page', 25),
            page: (int) $request->query('page', 1),
        );

        return response()->json([
            'data' => TerminalSummaryResource::collection($paginator->items()),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function get(Request $request, string $terminalId): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        $terminal = Terminal::where('store_id', $actor->store_id)->findOrFail($terminalId);

        return (new TerminalSummaryResource($terminal))->response();
    }

    public function revoke(Request $request, string $terminalId): JsonResponse
    {
        $actor = Auth::guard('web')->user();

        $terminal = Terminal::where('store_id', $actor->store_id)->findOrFail($terminalId);

        // Only revoked_at changes -- module-a-auth-terminal-initialization.md
        // §14 Ruling 3/9: revocation is independent of TerminalStatus, no
        // status transition is invented here.
        $terminal->update(['revoked_at' => now()]);

        return (new TerminalSummaryResource($terminal->refresh()))->response();
    }

    private function credentialCookie(string $plaintextCredential): \Symfony\Component\HttpFoundation\Cookie
    {
        return Cookie::make(
            name: self::CREDENTIAL_COOKIE,
            value: $plaintextCredential,
            minutes: 60 * 24 * 365 * 5, // "long-lived" (ADR-011) -- 5 years, deliberately longer than Cookie::forever()'s 400 days
            secure: config('session.secure'),
            sameSite: config('session.same_site'),
        );
    }
}
