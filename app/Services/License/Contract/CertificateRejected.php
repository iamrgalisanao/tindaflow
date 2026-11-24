<?php

namespace App\Services\License\Contract;

use RuntimeException;

/** A certificate that failed verification. `reason` is stable and machine-readable; the message is for logs. */
final class CertificateRejected extends RuntimeException
{
    public const MALFORMED = 'MALFORMED';

    public const UNKNOWN_KID = 'UNKNOWN_KID';

    public const REVOKED_KID = 'REVOKED_KID';

    public const BAD_SIGNATURE = 'BAD_SIGNATURE';

    public const NOT_CANONICAL = 'NOT_CANONICAL';

    public const BAD_FIELD = 'BAD_FIELD';

    public function __construct(public readonly string $reason, string $detail = '')
    {
        parent::__construct($detail === '' ? $reason : "{$reason}: {$detail}");
    }
}
