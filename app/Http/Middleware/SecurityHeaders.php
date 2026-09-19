<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response headers that harden the browser side of a same-origin SPA + JSON API
 * (docs/06-backend/stage-23-production-readiness.md).
 *
 * The Content-Security-Policy is deliberately strict: scripts and fonts only from this origin (the build
 * self-hosts its font), nothing framed, no plugins, no other origin to talk to. `style-src` allows inline
 * styles because the server-rendered invoice is shown in a srcdoc frame that inherits this policy and carries
 * its own <style>; scripts stay locked to 'self', and that frame is sandboxed with no script permission anyway.
 * It is skipped only in a local environment while the Vite dev server is running (public/hot), which needs
 * inline scripts and a websocket; a production host never has that file.
 */
class SecurityHeaders
{
    private const CONTENT_SECURITY_POLICY = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    /** The dev server needs inline scripts and a websocket; that only ever applies to a local checkout. */
    private function viteDevServerIsRunning(): bool
    {
        return app()->environment('local') && file_exists(public_path('hot'));
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if (! $this->viteDevServerIsRunning()) {
            $response->headers->set('Content-Security-Policy', self::CONTENT_SECURITY_POLICY);
        }

        // Only ever over HTTPS: a browser ignores it on plain HTTP, and pinning a LAN host that has no
        // certificate yet would lock a store out of its own till.
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
