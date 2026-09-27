<?php

namespace App\Services\Terminal;

use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * The `tindaflow_terminal` credential cookie, built in ONE place so the cookie set at enrollment and the one renewed on use
 * always carry identical attributes (HttpOnly, SameSite, Secure outside local development, the same lifetime).
 *
 * It is renewed on every authenticated use of the terminal (ResolveTerminalContext), so the lifetime is measured from the
 * till's last use, not from enrollment. Without that, every till enrolled on go-live day would stop working on the same day
 * about 13 months later: browsers cap a cookie's lifetime (Chrome at 400 days), whatever the server asks for. A till only
 * lapses if it sits unused for the whole lifetime, and re-enrolling (an enrollment token for the same terminal) fixes it.
 */
final class TerminalCredentialCookie
{
    public const NAME = 'tindaflow_terminal';

    public static function make(string $plaintextCredential): SymfonyCookie
    {
        return Cookie::make(
            name: self::NAME,
            value: $plaintextCredential,
            minutes: (int) config('tindaflow.terminal_credential.lifetime_minutes'),
            secure: config('session.secure'),
            sameSite: config('session.same_site'),
        );
    }
}
