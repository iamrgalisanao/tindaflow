<?php

namespace Tests\Concerns;

use App\Models\Store;

/**
 * Makes this test installation the one every license test certificate is issued for (the `installation` and `store` of
 * SignsTestCertificates::payload()): writes an identity file to a temporary path and creates that store if it is missing.
 * The license gate counts a stored certificate only when both agree (ADR-019 defect 3).
 */
trait FakesLicensedInstallation
{
    protected static function licensedInstallationId(): string
    {
        return '7f3c9e2a-4b1d-4e8f-9a06-2d5b8c1e7f40';
    }

    protected static function licensedStoreId(): string
    {
        return '0b8f6f0e-2f0a-4d27-9a3e-0c1b6a5d4e11';
    }

    /** @return string the identity file's path */
    protected function fakeLicensedInstallation(?string $installation = null): string
    {
        if (Store::find(self::licensedStoreId()) === null) {
            (new Store)->forceFill(['id' => self::licensedStoreId(), 'name' => 'TSC Canteen'])->save();
        }

        return $this->fakeIdentityFile($installation ?? self::licensedInstallationId());
    }

    /** @return string the identity file's path */
    protected function fakeIdentityFile(string $installation): string
    {
        $pair = sodium_crypto_sign_keypair();
        $path = sys_get_temp_dir().'/tindaflow-test-identity-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode([
            'installation' => $installation,
            'public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'secret_key' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        ]));
        config(['tindaflow.license.installation_file' => $path]);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }
}
