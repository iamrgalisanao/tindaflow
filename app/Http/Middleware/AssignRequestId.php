<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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
        $requestId = $request->header('X-Request-ID') ?: (string) Str::uuid();

        $request->attributes->set('request_id', $requestId);

        $response = $next($request);

        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
