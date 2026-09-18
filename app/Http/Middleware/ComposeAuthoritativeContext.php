<?php

namespace App\Http\Middleware;

use App\Domain\Exceptions\TerminalNotEnrolledException;
use App\Services\Auth\PosRequestContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A4 -- module-a-auth-terminal-initialization.md §14 Ruling 1: the
 * authenticated User and the authoritative Terminal (already resolved by
 * EnsureUserIsActive/ResolveTerminalContext, both of which must run
 * before this middleware) must belong to the same Store. This is a
 * separate boundary from Stage 6C's own OPEN-Shift/cashier-match check
 * -- neither substitutes for the other.
 *
 * A mismatch is deliberately reported as TERMINAL_NOT_ENROLLED (403),
 * not a distinct code: from this user's perspective, a terminal
 * belonging to another store is exactly as unusable as no terminal at
 * all, and the frozen contract's own non-enumeration convention (see
 * ENROLLMENT_TOKEN_INVALID) already establishes that a cross-store
 * terminal must never be confirmed to exist. §16 item 6 explicitly
 * forecloses inventing a new code for this composition step.
 */
class ComposeAuthoritativeContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();
        $terminal = $request->attributes->get('terminal');

        if ($user->store_id !== $terminal->store_id) {
            throw TerminalNotEnrolledException::make();
        }

        $request->attributes->set('pos_context', new PosRequestContext($user, $terminal));

        return $next($request);
    }
}
