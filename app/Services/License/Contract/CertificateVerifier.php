<?php

namespace App\Services\License\Contract;

/**
 * Accepts an activation certificate only if a trusted, un-revoked key signed exactly its canonical bytes (ADR-018 decision 1).
 * The envelope checks are {@see SignedEnvelope}'s; this adds the certificate's strict field validation. Part of the contract
 * kernel: no framework imports.
 */
final class CertificateVerifier
{
    public function __construct(private readonly KeyRing $keys) {}

    /** @throws CertificateRejected */
    public function verify(string $token): Certificate
    {
        return Certificate::fromPayload(SignedEnvelope::open($token, $this->keys));
    }
}
