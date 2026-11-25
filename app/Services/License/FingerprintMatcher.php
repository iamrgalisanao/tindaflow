<?php

namespace App\Services\License;

use App\Services\License\Contract\Certificate;

/** ADR-018 decision 4: the host is accepted when at least 2 of the 3 hardware hashes match the registration. */
final class FingerprintMatcher
{
    public const REQUIRED_MATCHES = 2;

    /**
     * @param  array<string, string>  $registered  the certificate's `fp`
     * @param  array<string, string>  $observed  what the host helper reported now; a missing component is not a match
     */
    public function matchedCount(array $registered, array $observed): int
    {
        $matched = 0;

        foreach (Certificate::FINGERPRINT_COMPONENTS as $component) {
            if (isset($registered[$component], $observed[$component]) && hash_equals($registered[$component], $observed[$component])) {
                $matched++;
            }
        }

        return $matched;
    }

    /**
     * A short name for the hardware as it is now, which the shop reads to the provider so that changed hardware can be approved
     * (ADR-019 slice 3). The provider's service computes the same one from what a renewal reports. It gives away no component.
     *
     * @param  array<string, string>  $observed
     */
    public static function reference(array $observed): string
    {
        ksort($observed);

        return substr(hash('sha256', (string) json_encode($observed)), 0, 16);
    }

    public function isAcceptable(int $matched): bool
    {
        return $matched >= self::REQUIRED_MATCHES;
    }
}
