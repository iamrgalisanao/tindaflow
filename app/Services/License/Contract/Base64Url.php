<?php

namespace App\Services\License\Contract;

/** URL-safe base64 without padding (RFC 4648 section 5), strict on decode. Part of the license contract kernel: no framework imports. */
final class Base64Url
{
    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** Null for anything that is not unpadded base64url, so a token cannot hide behind a lenient decoder. */
    public static function decode(string $text): ?string
    {
        if (preg_match('/\A[A-Za-z0-9_-]+\z/', $text) !== 1) {
            return null;
        }

        $standard = strtr($text, '-_', '+/');
        $decoded = base64_decode($standard.str_repeat('=', (4 - strlen($standard) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }
}
