<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module A Decision Register (module-a-auth-terminal-initialization.md
 * SS14, Ruling 2): a session alone is not sufficient evidence that the
 * authenticated principal remains authorized to authenticate -- `active`
 * is revalidated on every protected request, not just at login. A user
 * who has become inactive since their session was established is
 * invalidated on the very next protected request (forced logout), not
 * merely rejected for that one endpoint while the session stays alive.
 *
 * Deliberately narrow: no capability/RBAC checks live here (A2's
 * concern), only the authentication-boundary "is this principal still
 * allowed to be authenticated at all" question.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if ($user !== null && ! $user->active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new AuthenticationException('This account is no longer active.');
        }

        return $next($request);
    }
}
