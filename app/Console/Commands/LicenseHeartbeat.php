<?php

namespace App\Console\Commands;

use App\Services\License\Time\TrustedClock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * ADR-018 decision 3: the one-minute heartbeat that keeps the trusted-time high-water mark moving. Inert in a build that carries
 * no vendor key (LEGACY): it touches no table, so an existing installation behaves exactly as before.
 */
class LicenseHeartbeat extends Command
{
    protected $signature = 'tindaflow:license-heartbeat';

    protected $description = 'Advance the licence trusted-time mark (run every minute by the scheduler)';

    public function handle(TrustedClock $clock): int
    {
        if (config('tindaflow.license.vendor_keys') === []) {
            $this->line('No vendor key in this build: nothing to do.', verbosity: 'v');

            return self::SUCCESS;
        }

        $mark = $clock->heartbeat(CarbonImmutable::now());
        $this->line('Trusted time mark: '.$mark->format('Y-m-d H:i:sP'), verbosity: 'v');

        return self::SUCCESS;
    }
}
