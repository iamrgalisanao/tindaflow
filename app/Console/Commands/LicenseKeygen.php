<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * ADR-014: makes the vendor's Ed25519 signing key pair. Run once, by the vendor, on the vendor's own machine. The private
 * key file never goes on a client's server; the public key is pasted into config/tindaflow.php (license.public_key) and
 * shipped in the image.
 */
class LicenseKeygen extends Command
{
    protected $signature = 'tindaflow:license-keygen
        {--private-out= : Where to write the private key (required; keep it off every client server and back it up)}
        {--force : Overwrite an existing private key file}';

    protected $description = 'Generate the vendor key pair used to sign terminal licenses';

    public function handle(): int
    {
        $path = (string) $this->option('private-out');

        if ($path === '') {
            $this->error('Say where to write the private key with --private-out=/path/to/license-private.key (outside this repository).');

            return self::FAILURE;
        }
        if (is_file($path) && ! $this->option('force')) {
            $this->error("'{$path}' already exists. Overwriting it would orphan every license signed with it; pass --force only if you mean to.");

            return self::FAILURE;
        }

        $pair = sodium_crypto_sign_keypair();
        file_put_contents($path, base64_encode(sodium_crypto_sign_secretkey($pair)));
        chmod($path, 0600);

        $this->info("Private key written to {$path} (mode 600). Back it up; it cannot be recovered.");
        $this->line('Public key, for config/tindaflow.php  license.public_key:');
        $this->line(base64_encode(sodium_crypto_sign_publickey($pair)));

        return self::SUCCESS;
    }
}
