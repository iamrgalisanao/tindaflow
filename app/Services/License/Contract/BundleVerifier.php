<?php

namespace App\Services\License\Contract;

/** Accepts an activation bundle only if a trusted, un-revoked vendor key signed exactly its canonical bytes (ADR-018 decision 9). */
final class BundleVerifier
{
    public function __construct(private readonly KeyRing $keys) {}

    /** @throws CertificateRejected */
    public function verify(string $token): ActivationBundle
    {
        return ActivationBundle::fromPayload(SignedEnvelope::open($token, $this->keys));
    }
}
