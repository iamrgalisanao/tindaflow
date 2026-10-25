<?php

namespace App\Services\Overrides;

/**
 * What an approver's override code may be (ADR-016 section 6): 6 to 8 digits, and not trivially guessable. The ADR also requires that it
 * differs from the account password; that needs the password and is checked by OverrideCodeService.
 *
 * Refused as trivial: every digit the same (111111), a straight run up or down, wrapping past 9 (123456, 654321, 890123) and a short
 * block repeated (121212, 123123, 12341234). A 6-digit code has only a million values whatever is chosen; the real protection is the
 * named approver, the slow hash and the lockout, so this rule only removes the codes an onlooker would try first.
 */
final class OverrideCodePolicy
{
    public const MIN_LENGTH = 6;

    public const MAX_LENGTH = 8;

    /** The reason this code is not acceptable, in words for the person choosing it, or null when it is fine. */
    public static function violation(string $code): ?string
    {
        if (preg_match('/^\d{'.self::MIN_LENGTH.','.self::MAX_LENGTH.'}$/D', $code) !== 1) {
            return 'Use '.self::MIN_LENGTH.' to '.self::MAX_LENGTH.' digits, numbers only.';
        }

        $digits = array_map('intval', str_split($code));
        $steps = [];
        for ($i = 1; $i < count($digits); $i++) {
            $steps[] = ($digits[$i] - $digits[$i - 1] + 10) % 10;
        }
        if (count(array_unique($steps)) === 1 && in_array($steps[0], [0, 1, 9], true)) {
            return 'That code is too easy to guess (the same digit, or a run such as 123456). Choose another.';
        }

        $length = strlen($code);
        for ($block = 2; $block <= intdiv($length, 2); $block++) {
            if ($length % $block === 0 && str_repeat(substr($code, 0, $block), intdiv($length, $block)) === $code) {
                return 'That code is too easy to guess (a short pattern repeated). Choose another.';
            }
        }

        return null;
    }
}
