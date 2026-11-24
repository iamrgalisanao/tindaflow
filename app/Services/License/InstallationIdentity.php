<?php

namespace App\Services\License;

use RuntimeException;

/**
 * This installation's own identity before any store exists (ADR-018 decisions 8 and 9): a random UUID and an Ed25519 key
 * pair made on the PC, kept in one file on the persistent app volume. The provider registers the public half; the control
 * plane stores no shared secret. The file is written once and never silently replaced, because replacing it would orphan
 * the activation the provider already bound to the old key. A host administrator can copy it (accepted, ADR-018 residual risks).
 */
final class InstallationIdentity
{
    public function path(): string
    {
        return (string) config('tindaflow.license.installation_file');
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /** @return array{installation: string, public_key: string, secret_key: string} */
    public function load(): array
    {
        $data = is_file($this->path()) ? json_decode((string) file_get_contents($this->path()), true) : null;

        if (! is_array($data) || ! is_string($data['installation'] ?? null) || ! is_string($data['public_key'] ?? null) || ! is_string($data['secret_key'] ?? null)) {
            throw new RuntimeException('This installation has no identity yet. Run tindaflow:activate --request first.');
        }

        return ['installation' => $data['installation'], 'public_key' => $data['public_key'], 'secret_key' => $data['secret_key']];
    }

    /** Creates the identity if there is none, and returns it either way. @return array{installation: string, public_key: string, secret_key: string} */
    public function ensure(): array
    {
        if ($this->exists()) {
            return $this->load();
        }

        $pair = sodium_crypto_sign_keypair();
        $identity = [
            'installation' => self::uuid(),
            'public_key' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'secret_key' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        ];

        $directory = dirname($this->path());
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create {$directory}.");
        }

        $temporary = $this->path().'.tmp';
        file_put_contents($temporary, json_encode($identity, JSON_THROW_ON_ERROR), LOCK_EX);
        @chmod($temporary, 0600);
        rename($temporary, $this->path());

        return $identity;
    }

    /**
     * What the provider needs to register this PC. `fingerprint` is the host helper's three hashes (ADR-018 decision 4), or null when
     * there is no fresh hardware file, in which case the provider will refuse the request.
     *
     * @param  array<string, string>|null  $fingerprint
     * @return array<string, mixed>
     */
    public function request(?array $fingerprint = null): array
    {
        $identity = $this->ensure();

        return [
            'v' => 1,
            'typ' => 'tindaflow.activation-request',
            'installation' => $identity['installation'],
            'installation_key' => $identity['public_key'],
            'fingerprint' => $fingerprint,
        ];
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
