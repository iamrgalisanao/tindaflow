<?php

namespace App\Console\Commands;

use App\Services\License\Renewal\RenewalService;
use Illuminate\Console\Command;

/**
 * ADR-018 decision 1: asks the provider for the next certificate. The scheduler runs it hourly with a random delay (so a fleet
 * does not call in on the same second); a person runs it as "check the license now". Safe to run any time: it does nothing in a
 * build with no vendor key or on an installation that is not activated, and a failed attempt changes nothing but the recorded error.
 */
class LicenseRenew extends Command
{
    protected $signature = 'tindaflow:license-renew {--jitter=0 : Wait a random 0 to N seconds first (the scheduler uses this)}';

    protected $description = 'Ask the provider for the next license certificate';

    public function handle(RenewalService $renewals): int
    {
        $jitter = max(0, (int) $this->option('jitter'));
        if ($jitter > 0) {
            sleep(random_int(0, $jitter));
        }

        $result = $renewals->renew();

        $result->failed() ? $this->error($result->message) : $this->line($result->message);

        return $result->failed() ? self::FAILURE : self::SUCCESS;
    }
}
