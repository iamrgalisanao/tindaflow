<?php

namespace App\Services\License\Contract;

use InvalidArgumentException;
use JsonException;

/**
 * The wire form every vendor-signed document shares (ADR-018 decisions 1 and 9): `base64url(JCS(payload)) . base64url(Ed25519
 * signature)`. Opening one checks, in this order, that it parses, that a trusted un-revoked key (`kid`) signed exactly its
 * bytes, and that those bytes are the canonical form; only then is the payload handed back, so nothing unsigned is ever
 * trusted. What the payload MEANS (its fields) is the caller's strict validation. Part of the contract kernel: no framework imports.
 */
final class SignedEnvelope
{
    /**
     * @return array<string, mixed> the verified payload
     *
     * @throws CertificateRejected
     */
    public static function open(string $token, KeyRing $keys): array
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

        if ($keys->isRevoked($kid)) {
            throw new CertificateRejected(CertificateRejected::REVOKED_KID, $kid);
        }

        $publicKey = $keys->publicKey($kid);
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

        return $payload;
    }
}
