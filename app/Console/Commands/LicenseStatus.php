<?php

namespace App\Console\Commands;

use App\Services\License\Contract\KeyRing;
use App\Services\License\Entitlement;
use App\Services\License\LicenseEvaluator;
use App\Services\License\LocalFacts;
use App\Services\License\Time\TrustedClock;
use App\Services\Terminal\TerminalSeats;
use App\Support\DeploymentId;
use DateTimeImmutable;
use Illuminate\Console\Command;

/** ADR-014: what the license on this installation allows, and how much of it is used. Also how the vendor learns the deployment code to sign for. */
class LicenseStatus extends Command
{
    protected $signature = 'tindaflow:license-status';

    protected $description = "Show this installation's deployment code, till limit and tills in use";

    public function handle(TerminalSeats $seats, Entitlement $entitlement, TrustedClock $clock, LicenseEvaluator $evaluator): int
    {
        $this->line('Deployment code: '.(DeploymentId::current() ?? '(no store yet)'));

        $publicKey = (string) config('tindaflow.license.public_key');
        if ($publicKey === '') {
            $this->line('Till limit: not enforced (this build carries no license key).');
            $this->line('Tills in use: '.$seats->inUse());
            $this->reportActivation($clock, $evaluator, false);

            return self::SUCCESS;
        }

        $payload = $entitlement->verifiedPayload($publicKey);
        if ($payload === null) {
            $this->warn('License: none, invalid, expired, or issued for another installation. No new till can be added.');
        } else {
            $this->line("License: valid, {$payload['max_terminals']} till(s)".(($payload['expires_at'] ?? null) ? ", expires {$payload['expires_at']}" : ', no expiry').'.');
        }
        $this->line('Tills in use: '.$seats->inUse());
        $this->reportActivation($clock, $evaluator, $payload !== null);

        return $payload === null ? self::FAILURE : self::SUCCESS;
    }

    /** ADR-018: the activation state and the trusted-time mark. Touches no table while the build carries no vendor key. */
    private function reportActivation(TrustedClock $clock, LicenseEvaluator $evaluator, bool $legacyLicensePresent): void
    {
        $ring = new KeyRing((array) config('tindaflow.license.vendor_keys'), (array) config('tindaflow.license.revoked_kids'));
        if ($ring->isEmpty()) {
            $this->line('Activation: not enforced (this build carries no vendor key; legacy mode).');

            return;
        }

        $mark = $clock->highWaterMark();
        $verdict = $evaluator->evaluate(null, new LocalFacts(new DateTimeImmutable, $mark), true, $legacyLicensePresent);

        $this->line('Activation: '.$verdict->state->value.'.');
        $this->line('Trusted time mark: '.($mark?->format('Y-m-d H:i:sP') ?? '(none yet; the heartbeat has not run)'));
    }
}
