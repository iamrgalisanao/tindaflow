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

        $this->write(json_encode($identity, JSON_THROW_ON_ERROR));

        return $identity;
    }

    /**
     * Writes the file so that it is readable by its owner only from the moment it exists (ADR-019 defect 7): the umask restricts what
     * is created, and `x` refuses to reuse a file already there, which would keep its old permissions. It is then swapped into place.
     */
    private function write(string $contents): void
    {
        $directory = dirname($this->path());
        $temporary = $this->path().'.tmp';
        $umask = umask(0077);

        try {
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new RuntimeException("Cannot create {$directory}.");
            }

            @unlink($temporary);
            $handle = @fopen($temporary, 'x');
            if ($handle === false || fwrite($handle, $contents) !== strlen($contents) || ! fclose($handle)) {
                throw new RuntimeException("Cannot write {$temporary}.");
            }
        } finally {
            umask($umask);
        }

        $this->handToStorageOwner([$directory, $temporary]);

        if (! rename($temporary, $this->path())) {
            throw new RuntimeException("Cannot write {$this->path()}.");
        }
    }

    /**
     * `docker compose exec app php artisan ...` runs as root, while the web workers and the scheduler run as www-data. A file only
     * root can read would leave them with no identity, so when root writes it, it is given to whoever owns the folder above.
     *
     * @param  list<string>  $paths
     */
    private function handToStorageOwner(array $paths): void
    {
        if (! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return;
        }

        $above = dirname($this->path(), 2);
        $owner = fileowner($above);
        $group = filegroup($above);

        foreach ($paths as $path) {
            if ($owner === false || $group === false || ! chown($path, $owner) || ! chgrp($path, $group)) {
                throw new RuntimeException("Cannot give {$path} to the application's user.");
            }
        }
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
