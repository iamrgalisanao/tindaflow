<?php

namespace App\Console\Commands;

use App\Services\License\Entitlement;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * ADR-014: signs a license for one installation, on the vendor's machine. The license names the installation's
 * deployment code (shown in the admin footer and by `tindaflow:license-status`), so it will not verify on any other.
 */
class LicenseIssue extends Command
{
    protected $signature = 'tindaflow:license-issue
        {--private-key= : The private key file made by tindaflow:license-keygen}
        {--deployment= : The installation\'s deployment code (8 characters)}
        {--max-terminals= : How many active tills the shop may run}
        {--expires= : Optional last day of the license (YYYY-MM-DD); omit for none}
        {--out= : Write the license here instead of printing it}';

    protected $description = 'Sign a terminal license for one installation (vendor machine only)';

    public function handle(): int
    {
        $keyFile = (string) $this->option('private-key');
        $deployment = strtoupper(trim((string) $this->option('deployment')));
        $max = $this->option('max-terminals');

        if ($keyFile === '' || ! is_file($keyFile)) {
            $this->error('--private-key must point at the private key file.');

            return self::FAILURE;
        }
        if (! preg_match('/^[0-9A-F]{8}$/', $deployment)) {
            $this->error('--deployment must be the installation\'s 8-character code, for example 3F9A01BC.');

            return self::FAILURE;
        }
        if (! ctype_digit((string) $max) || (int) $max < 1 || (int) $max > 1000) {
            $this->error('--max-terminals must be a whole number from 1 to 1000.');

            return self::FAILURE;
        }

        $expires = null;
        if ($this->option('expires')) {
            try {
                $expires = CarbonImmutable::parse((string) $this->option('expires'))->endOfDay()->toIso8601String();
            } catch (Throwable) {
                $this->error('--expires must be a date, for example 2027-12-31.');

                return self::FAILURE;
            }
        }

        $secret = base64_decode(trim((string) file_get_contents($keyFile)), true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $this->error('That file is not a TindaFlow private key.');

            return self::FAILURE;
        }

        $payload = json_encode([
            'deployment' => $deployment,
            'max_terminals' => (int) $max,
            'issued_at' => CarbonImmutable::now()->toIso8601String(),
            'expires_at' => $expires,
        ], JSON_THROW_ON_ERROR);

        $license = Entitlement::encode($payload).'.'.Entitlement::encode(sodium_crypto_sign_detached($payload, $secret));

        if ($this->option('out')) {
            file_put_contents((string) $this->option('out'), $license."\n");
            $this->info("License written to {$this->option('out')}. Put it at storage/app/license.key on the client's server.");
        } else {
            $this->line($license);
        }

        return self::SUCCESS;
    }
}
