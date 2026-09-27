<?php

namespace App\Http\Middleware;

use App\Services\Terminal\TerminalCredentialCookie;
use App\Services\Terminal\TerminalCredentialResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-011's trust boundary, applied to every POS_TERMINAL-classified
 * route: resolves the authoritative Terminal exclusively from the
 * `tindaflow_terminal` cookie and attaches it to the request. Throws
 * TerminalNotEnrolledException/TerminalRevokedException (both already
 * rendered via bootstrap/app.php's DomainException handler) when it
 * cannot.
 *
 * Deliberately narrow, matching EnsureUserIsActive's own scope
 * discipline: no User+Terminal+Store coherence check lives here -- that
 * composition belongs to A4.
 */
class ResolveTerminalContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $credential = $request->cookie(TerminalCredentialCookie::NAME);
        $terminal = app(TerminalCredentialResolver::class)->resolve($credential);

        $request->attributes->set('terminal', $terminal);

        $response = $next($request);

        // A credential that resolved is renewed on use, so the cookie lasts from the till's last use, not from enrollment
        // (browsers cap a cookie's lifetime; see TerminalCredentialCookie). Only a credential that resolved gets here.
        $response->headers->setCookie(TerminalCredentialCookie::make($credential));

        return $response;
    }
}
