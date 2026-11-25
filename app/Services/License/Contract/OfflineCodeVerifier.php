<?php

namespace App\Services\License\Contract;

/**
 * Accepts an offline code only if a trusted, un-revoked CODE key signed exactly its payload (ADR-018 decisions 1 and 6). The code
 * keys are a second family, separate from the certificate keys, so that each key serves one purpose: a certificate key cannot make
 * a code, nor the reverse. They are numbered 1 to 255 because the payload has one byte for the number. Order matters: read the
 * text, pick the key, check the signature, and only then read the payload's fields. Part of the contract kernel: no framework imports.
 */
final class OfflineCodeVerifier
{
    /**
     * @param  array<int, string>  $publicKeys  key id => base64 of a 32-byte Ed25519 public key
     * @param  list<int>  $revokedKeyIds
     */
    public function __construct(private readonly array $publicKeys, private readonly array $revokedKeyIds = []) {}

    /** @throws CertificateRejected */
    public function verify(string $text): OfflineCode
    {
        $bytes = CodeText::decode($text);
        if ($bytes === null) {
            throw new CertificateRejected(CertificateRejected::MALFORMED, 'the code is incomplete or mistyped');
        }

        $payload = substr($bytes, 0, OfflineCode::PAYLOAD_BYTES);
        $signature = substr($bytes, OfflineCode::PAYLOAD_BYTES);
        $keyId = ord($payload[1]);

        if (in_array($keyId, $this->revokedKeyIds, true)) {
            throw new CertificateRejected(CertificateRejected::REVOKED_KID, (string) $keyId);
        }

        $encoded = $this->publicKeys[$keyId] ?? null;
        $key = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new CertificateRejected(CertificateRejected::UNKNOWN_KID, (string) $keyId);
        }

        if (! sodium_crypto_sign_verify_detached($signature, $payload, $key)) {
            throw new CertificateRejected(CertificateRejected::BAD_SIGNATURE);
        }

        return OfflineCode::fromBytes($payload);
    }
}
