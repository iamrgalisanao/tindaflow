<?php

namespace App\Services\License\Time;

/** Seconds that pass whatever the wall clock says (ADR-018 decision 3). A seam so tests, and hosts without /proc, need no real kernel. */
interface MonotonicSource
{
    /** Null when the host offers no monotonic clock; the heartbeat then relies on the wall clock alone. */
    public function reading(): ?MonotonicReading;
}
