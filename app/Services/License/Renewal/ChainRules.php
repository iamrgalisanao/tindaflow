<?php

namespace App\Services\License\Renewal;

use App\Services\License\Contract\Certificate;
use DateTimeImmutable;

/**
 * What the client insists on before it replaces the certificate it holds with one the control plane just returned (ADR-018
 * decisions 1 and 5). A signature alone is not enough: an old, validly signed certificate replayed by anyone on the path must
 * not rewind the installation, move it to another store, or hand it back time it already used.
 *
 * Pure: no framework, no database, no clock of its own.
 */
final class ChainRules
{
    /** @return string|null why the candidate must be refused, or null when it may replace the current certificate */
    public static function refusal(Certificate $current, Certificate $candidate, DateTimeImmutable $now): ?string
    {
        if ($candidate->installation !== $current->installation || $candidate->store !== $current->store || $candidate->deployment !== $current->deployment) {
            return 'the certificate is for a different installation, store or deployment';
        }

        if ($candidate->seq < $current->seq) {
            return 'the certificate is older than the one held (seq went backwards)';
        }

        if ($candidate->seq === $current->seq && $candidate->jti !== $current->jti) {
            return 'a different certificate with the same seq (the chain has forked)';
        }

        if ($candidate->exp <= $now) {
            return 'the certificate has already expired';
        }

        return null;
    }

    /** Whether the candidate is the very certificate held (the provider's "still current" answer within 24 hours). */
    public static function isSame(Certificate $current, Certificate $candidate): bool
    {
        return $candidate->seq === $current->seq && $candidate->jti === $current->jti;
    }

    /**
     * Whether the candidate links to the certificate it replaces: the next seq, with `prev` the SHA-256 of the previous token.
     * A gap is allowed (a provider re-chain, runbook RB-7, or a restored control plane) but is audited by the caller.
     */
    public static function linksCleanly(string $currentToken, Certificate $current, Certificate $candidate): bool
    {
        return $candidate->seq === $current->seq + 1 && $candidate->prev === hash('sha256', $currentToken);
    }
}
