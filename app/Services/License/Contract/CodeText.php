<?php

namespace App\Services\License\Contract;

/**
 * How a signed offline code is written for people (ADR-018 decision 6): the 87 bytes (23 of payload, 64 of signature) as 140
 * Crockford base32 characters, then Crockford's check symbol (the whole value modulo 37, from the 32 digits plus `*~$=U`), in
 * groups of five joined by hyphens: 169 characters. Reading is forgiving of how a code survives being copied (case, spaces, line
 * breaks, hyphens, and I and L for 1, O for 0), and the check symbol catches a mistyped or dropped character before any signature
 * is looked at. Part of the contract kernel: no framework imports.
 */
final class CodeText
{
    public const SIGNED_BYTES = OfflineCode::PAYLOAD_BYTES + 64;

    private const DIGITS = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const CHECK_DIGITS = '0123456789ABCDEFGHJKMNPQRSTVWXYZ*~$=U';

    private const SYMBOLS = 140;

    public static function encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $text = '';
        foreach (str_split(str_pad($bits, self::SYMBOLS * 5, '0'), 5) as $group) {
            $text .= self::DIGITS[bindec($group)];
        }

        return implode('-', str_split($text.self::checkSymbol($bytes), 5));
    }

    /** The 87 bytes, or null when the text is not a whole, correctly copied code. */
    public static function decode(string $text): ?string
    {
        $clean = strtr(strtoupper((string) preg_replace('/[\s-]+/', '', $text)), ['I' => '1', 'L' => '1', 'O' => '0']);
        if (strlen($clean) !== self::SYMBOLS + 1) {
            return null;
        }

        $bits = '';
        for ($i = 0; $i < self::SYMBOLS; $i++) {
            $value = strpos(self::DIGITS, $clean[$i]);
            if ($value === false) {
                return null;
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }

        // The bits past the last byte are padding and must be zero, so each code has exactly one spelling.
        if (trim(substr($bits, self::SIGNED_BYTES * 8), '0') !== '') {
            return null;
        }

        $bytes = '';
        foreach (str_split(substr($bits, 0, self::SIGNED_BYTES * 8), 8) as $group) {
            $bytes .= chr(bindec($group));
        }

        return $clean[self::SYMBOLS] === self::checkSymbol($bytes) ? $bytes : null;
    }

    private static function checkSymbol(string $bytes): string
    {
        $remainder = 0;
        foreach (str_split($bytes) as $byte) {
            $remainder = ($remainder * 256 + ord($byte)) % 37;
        }

        return self::CHECK_DIGITS[$remainder];
    }
}
