<?php

namespace App\Services\License\Renewal;

/** How a renewal attempt ended. `message` is for the console and the status screen; it never carries a token or a key. */
final class RenewalResult
{
    public const RENEWED = 'RENEWED';

    public const UNCHANGED = 'UNCHANGED';

    public const REVOKED = 'REVOKED';

    public const SKIPPED = 'SKIPPED';

    public const FAILED = 'FAILED';

    /** A request file was made for a shop with no internet; `document` holds it. */
    public const REQUESTED = 'REQUESTED';

    public function __construct(public readonly string $outcome, public readonly string $message, public readonly ?string $document = null) {}

    public function failed(): bool
    {
        return $this->outcome === self::FAILED;
    }
}
