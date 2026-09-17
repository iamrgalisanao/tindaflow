<?php

namespace App\Services\Auth;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Centralizes POST /auth/login's throttle key and limit (Module A
 * Decision Register SS14 Ruling 6: RATE_LIMITED, 429) so the value isn't
 * duplicated as magic numbers across the service provider registration
 * and tests. Keyed by email+IP, not IP alone (one abusive IP must not be
 * able to lock out every other user sharing it) and not email alone (an
 * attacker must not be able to lock a specific victim out from every
 * location) -- derived from the raw submitted value, never from a User
 * lookup, so unknown-email and wrong-password attempts participate in
 * throttling identically and the public response never reveals whether
 * the account exists.
 */
final class LoginRateLimiter
{
    public static function key(Request $request): string
    {
        return Str::lower((string) $request->input('email')).'|'.$request->ip();
    }

    public static function limit(): Limit
    {
        return Limit::perMinutes(
            (int) config('tindaflow.login_throttle.decay_minutes'),
            (int) config('tindaflow.login_throttle.max_attempts'),
        );
    }
}
