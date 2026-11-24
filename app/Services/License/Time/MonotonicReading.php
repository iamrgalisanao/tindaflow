<?php

namespace App\Services\License\Time;

/** One reading of the host's monotonic clock: which boot it belongs to and whole seconds since that boot. */
final class MonotonicReading
{
    public function __construct(public readonly string $bootId, public readonly int $uptimeSeconds) {}
}
