<?php

namespace App\Services\License;

use App\Support\DeploymentId;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * ADR-014: the vendor-signed entitlement that caps how many tills this installation may have.
 *
 * A license is `base64url(payload).base64url(signature)`, an Ed25519 signature over the payload bytes. The payload is
 * JSON: {"deployment": "<DeploymentId>", "max_terminals": 3, "issued_at": "...", "expires_at": null|"..."}. The public
 * key is a constant in config/tindaflow.php, deliberately NOT an environment variable: a shop that could swap the key
 * could sign its own license. The private key never leaves the vendor (`tindaflow:license-keygen` / `license-issue`).
 *
 * Enforcement is opt-in by the vendor setting a public key. With no key configured the cap is off (null) and the
 * installation behaves exactly as before. With a key configured, a missing, forged, expired, or other-deployment license
 * allows no NEW till: the cap only ever blocks adding terminals, never selling on the ones already enrolled.
 */
final class Entitlement
{
    /** The cap on active terminals: null = not enforced, 0 = enforced and no valid license, n = n tills. */
    public function maxTerminals(): ?int
    {
        $publicKey = (string) config('tindaflow.license.public_key');

        if ($publicKey === '') {
            return null;
        }

        $payload = $this->verifiedPayload($publicKey);

        return $payload === null ? 0 : max(0, (int) $payload['max_terminals']);
    }

    /** @return array{deployment: string, max_terminals: int, issued_at?: string, expires_at?: ?string}|null */
    public function verifiedPayload(string $publicKey): ?array
    {
        try {
            $token = $this->token();
            if ($token === null || substr_count($token, '.') !== 1) {
                return null;
            }

            [$encodedPayload, $encodedSignature] = explode('.', $token);
            $payloadBytes = self::decode($encodedPayload);
            $signature = self::decode($encodedSignature);
            $key = base64_decode($publicKey, true);

            if ($payloadBytes === null || $signature === null || $key === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
                || ! sodium_crypto_sign_verify_detached($signature, $payloadBytes, $key)) {
                return null;
            }

            $payload = json_decode($payloadBytes, true, 4, JSON_THROW_ON_ERROR);

            if (! is_array($payload) || ! is_int($payload['max_terminals'] ?? null) || ! is_string($payload['deployment'] ?? null)) {
                return null;
            }
            if ($payload['deployment'] !== DeploymentId::current()) {
                return null;
            }
            if (isset($payload['expires_at']) && CarbonImmutable::parse($payload['expires_at'])->isPast()) {
                return null;
            }

            return $payload;
        } catch (Throwable) {
            return null;
        }
    }

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $text): ?string
    {
        $decoded = base64_decode(strtr($text, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    private function token(): ?string
    {
        $inline = trim((string) config('tindaflow.license.token'));
        if ($inline !== '') {
            return $inline;
        }

        $path = (string) config('tindaflow.license.path');

        return $path !== '' && is_file($path) ? trim((string) file_get_contents($path)) : null;
    }
}
