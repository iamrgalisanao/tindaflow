<?php

namespace App\Console\Commands;

use App\Services\License\Entitlement;
use App\Services\Terminal\TerminalSeats;
use App\Support\DeploymentId;
use Illuminate\Console\Command;

/** ADR-014: what the license on this installation allows, and how much of it is used. Also how the vendor learns the deployment code to sign for. */
class LicenseStatus extends Command
{
    protected $signature = 'tindaflow:license-status';

    protected $description = "Show this installation's deployment code, till limit and tills in use";

    public function handle(TerminalSeats $seats, Entitlement $entitlement): int
    {
        $this->line('Deployment code: '.(DeploymentId::current() ?? '(no store yet)'));

        $publicKey = (string) config('tindaflow.license.public_key');
        if ($publicKey === '') {
            $this->line('Till limit: not enforced (this build carries no license key).');
            $this->line('Tills in use: '.$seats->inUse());

            return self::SUCCESS;
        }

        $payload = $entitlement->verifiedPayload($publicKey);
        if ($payload === null) {
            $this->warn('License: none, invalid, expired, or issued for another installation. No new till can be added.');
        } else {
            $this->line("License: valid, {$payload['max_terminals']} till(s)".(($payload['expires_at'] ?? null) ? ", expires {$payload['expires_at']}" : ', no expiry').'.');
        }
        $this->line('Tills in use: '.$seats->inUse());

        return $payload === null ? self::FAILURE : self::SUCCESS;
    }
}
