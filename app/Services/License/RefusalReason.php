<?php

namespace App\Services\License;

/** The `details.reason` values of the LICENSE_NOT_ACTIVE error (ADR-018 decision 2). The values are the wire strings. */
enum RefusalReason: string
{
    case NotActivated = 'NOT_ACTIVATED';
    case Expired = 'EXPIRED';
    case ClockRollback = 'CLOCK_ROLLBACK';
    case FingerprintMismatch = 'FINGERPRINT_MISMATCH';
    case Revoked = 'REVOKED';
    case ChainInvalid = 'CHAIN_INVALID';
    case ShiftTooLong = 'SHIFT_TOO_LONG';
}
