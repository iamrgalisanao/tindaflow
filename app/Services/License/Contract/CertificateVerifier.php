<?php

namespace App\Services\License\Contract;

use InvalidArgumentException;
use JsonException;

/**
 * Accepts an activation certificate only if a trusted, un-revoked key signed exactly its canonical bytes (ADR-018 decision 1).
 * Wire form: `base64url(JCS(payload)) . base64url(Ed25519 signature)`. Order matters: parse, pick the key, check the
 * signature, and only then look at the payload's shape, so nothing unsigned is trusted. Part of the contract kernel: no
 * framework imports.
 */
final class CertificateVerifier
{
    public function __construct(private readonly KeyRing $keys) {}

    /** @throws CertificateRejected */
    public function verify(string $token): Certificate
    {
        $parts = explode('.', $token);
        $payloadBytes = count($parts) === 2 ? Base64Url::decode($parts[0]) : null;
        $signature = count($parts) === 2 ? Base64Url::decode($parts[1]) : null;

        if ($payloadBytes === null || $signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new CertificateRejected(CertificateRejected::MALFORMED, 'not two base64url parts with a 64-byte signature');
        }

        try {
            $payload = json_decode($payloadBytes, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CertificateRejected(CertificateRejected::MALFORMED, 'payload is not JSON');
        }

        $kid = is_array($payload) ? ($payload['kid'] ?? null) : null;
        if (! is_string($kid)) {
            throw new CertificateRejected(CertificateRejected::MALFORMED, 'payload has no kid');
        }

        if ($this->keys->isRevoked($kid)) {
            throw new CertificateRejected(CertificateRejected::REVOKED_KID, $kid);
        }

        $publicKey = $this->keys->publicKey($kid);
        if ($publicKey === null) {
            throw new CertificateRejected(CertificateRejected::UNKNOWN_KID, $kid);
        }

        if (! sodium_crypto_sign_verify_detached($signature, $payloadBytes, $publicKey)) {
            throw new CertificateRejected(CertificateRejected::BAD_SIGNATURE);
        }

        try {
            $canonical = CanonicalJson::encode($payload);
        } catch (InvalidArgumentException $e) {
            throw new CertificateRejected(CertificateRejected::NOT_CANONICAL, $e->getMessage());
        }

        if ($canonical !== $payloadBytes) {
            throw new CertificateRejected(CertificateRejected::NOT_CANONICAL, 'signed bytes are not the canonical form');
        }

        return Certificate::fromPayload($payload);
    }
}
