<?php

namespace App\Console\Commands;

use App\Services\License\Entitlement;
use App\Services\License\HostHardware;
use App\Services\License\LicenseGate;
use App\Services\License\OfflineCodeService;
use App\Services\License\Time\TrustedClock;
use App\Services\Terminal\TerminalSeats;
use App\Support\DeploymentId;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** ADR-014: what the license on this installation allows, and how much of it is used. Also how the vendor learns the deployment code to sign for. */
class LicenseStatus extends Command
{
    protected $signature = 'tindaflow:license-status';

    protected $description = "Show this installation's deployment code, till limit and tills in use";

    public function handle(TerminalSeats $seats, Entitlement $entitlement, TrustedClock $clock, LicenseGate $gate, HostHardware $hardware): int
    {
        $this->line('Deployment code: '.(DeploymentId::current() ?? '(no store yet)'));

        $publicKey = (string) config('tindaflow.license.public_key');
        if ($publicKey === '') {
            $this->line('Till limit: not enforced (this build carries no license key).');
            $this->line('Tills in use: '.$seats->inUse());
            $this->reportActivation($clock, $gate, $hardware);

            return self::SUCCESS;
        }

        $payload = $entitlement->verifiedPayload($publicKey);
        if ($payload === null) {
            $this->warn('License: none, invalid, expired, or issued for another installation. No new till can be added.');
        } else {
            $this->line("License: valid, {$payload['max_terminals']} till(s)".(($payload['expires_at'] ?? null) ? ", expires {$payload['expires_at']}" : ', no expiry').'.');
        }
        $this->line('Tills in use: '.$seats->inUse());
        $this->reportActivation($clock, $gate, $hardware);

        return $payload === null ? self::FAILURE : self::SUCCESS;
    }

    /** ADR-018: the activation state, the trusted-time mark and the host helper's file. Touches no table while the build carries no vendor key. */
    private function reportActivation(TrustedClock $clock, LicenseGate $gate, HostHardware $hardware): void
    {
        if ($gate->keyRing()->isEmpty()) {
            $this->line('Activation: not enforced (this build carries no vendor key; legacy mode).');

            return;
        }

        $mark = $clock->highWaterMark();
        $state = $gate->check()->verdict;
        $reading = $hardware->read();

        $this->line('Activation: '.$state->state->value.($state->enforcing ? '' : ' (report mode: nothing is refused)').'.');

        // The provider needs the certificate number to make an offline code.
        $row = DB::table('license_state')->where('id', 1)->first();
        if ($row !== null && $row->certificate_seq !== null) {
            $this->line("Certificate: {$row->certificate_seq}.");
            if ($row->extended_until !== null && (int) $row->extension_base_seq === (int) $row->certificate_seq) {
                $this->line('Extended offline until '.(new DateTimeImmutable($row->extended_until))->setTimezone(new DateTimeZone((string) config('app.timezone')))->format('Y-m-d H:i')." ({$row->extensions_in_a_row} of ".OfflineCodeService::MAX_EXTENSIONS_IN_A_ROW.' in a row).');
            }
        }
        $this->line('Trusted time mark: '.($mark?->format('Y-m-d H:i:sP') ?? '(none yet; the heartbeat has not run)'));
        $this->line('Hardware file: '.strtolower($reading->status).($reading->generatedAt !== null ? ', written '.$reading->generatedAt->format('Y-m-d H:i:sP') : '').($reading->isFresh() ? '.' : ' (activation and renewal need a file under 26 hours old).'));
    }
}
