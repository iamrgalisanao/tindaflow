<?php

namespace App\Services\License\Contract;

/**
 * The vendor public keys a build trusts, by `kid`, and the `kid`s it has been told to distrust (ADR-018 decisions 1 and
 * 8). Keys are 32-byte Ed25519 public keys, base64-encoded as `tindaflow:license-keygen` prints them. An empty ring is
 * the signal that this build enforces nothing (state LEGACY). Part of the contract kernel: no framework imports.
 */
final class KeyRing
{
    /**
     * @param  array<string, string>  $publicKeys  kid => base64 public key
     * @param  list<string>  $revokedKids
     */
    public function __construct(private readonly array $publicKeys, private readonly array $revokedKids = []) {}

    public function isEmpty(): bool
    {
        return $this->publicKeys === [];
    }

    public function isRevoked(string $kid): bool
    {
        return in_array($kid, $this->revokedKids, true);
    }

    /** The raw 32-byte key, or null when the kid is unknown or its configured key is not a valid Ed25519 public key. */
    public function publicKey(string $kid): ?string
    {
        $encoded = $this->publicKeys[$kid] ?? null;
        $raw = is_string($encoded) ? base64_decode($encoded, true) : false;

        return $raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $raw : null;
    }
}
