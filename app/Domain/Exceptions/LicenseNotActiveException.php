<?php

namespace App\Domain\Exceptions;

use App\Services\License\LicenseState;
use App\Services\License\RefusalReason;

/** error-catalog.md LICENSE_NOT_ACTIVE: the installation's activation does not allow this (ADR-018 decision 2). */
final class LicenseNotActiveException extends DomainException
{
    public static function make(RefusalReason $reason, LicenseState $state): self
    {
        return new self(
            match ($reason) {
                RefusalReason::NotActivated => 'This installation has not been activated. Ask your TindaFlow provider for an activation.',
                RefusalReason::Expired => 'The license has expired. Renew it, or ask your TindaFlow provider.',
                RefusalReason::ClockRollback => "This computer's clock is behind the last time TindaFlow recorded. Correct the clock, or ask your TindaFlow provider.",
                RefusalReason::FingerprintMismatch => 'This license belongs to different hardware. Ask your TindaFlow provider.',
                RefusalReason::Revoked => 'This license has been revoked. Ask your TindaFlow provider.',
                RefusalReason::ChainInvalid => 'The license record is inconsistent. Ask your TindaFlow provider.',
                RefusalReason::ShiftTooLong => 'This shift has been open too long for the current license. Close it, then renew the license.',
            },
            ['reason' => $reason->value, 'state' => $state->value],
        );
    }

    public function errorCode(): string
    {
        return 'LICENSE_NOT_ACTIVE';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
