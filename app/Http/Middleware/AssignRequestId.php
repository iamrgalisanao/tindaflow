<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * api-design.md SS6 "Request correlation": every response carries a
 * request_id, echoing an inbound X-Request-ID if present and generating
 * one otherwise. Runs first in the global middleware stack so the value
 * is already on the request even when a later middleware or controller
 * throws before reaching this middleware's own post-response code.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->inboundId($request) ?? (string) Str::uuid();

        $request->attributes->set('request_id', $requestId);

        // Every log line written while serving this request carries the id the client also holds, so a
        // failed request can be found in the logs from the id in its response.
        Log::shareContext(['request_id' => $requestId]);

        $response = $next($request);

        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }

    /** An inbound id is trusted only if it looks like one: it is echoed in a header and written to the logs. */
    private function inboundId(Request $request): ?string
    {
        $id = $request->header('X-Request-ID');

        return is_string($id) && preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $id) === 1 ? $id : null;
    }
}
