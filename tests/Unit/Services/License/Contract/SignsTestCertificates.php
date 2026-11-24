<?php

namespace Tests\Unit\Services\License\Contract;

use App\Services\License\Contract\Base64Url;
use App\Services\License\Contract\CanonicalJson;

/**
 * Builds and signs activation certificates for tests with a throwaway key pair derived from a fixed seed. TEST ONLY:
 * the seed is public in this repository, so nothing signed with it may ever be trusted by a real build. The production
 * vendor key never appears in git (ADR-018 decision 8).
 */
trait SignsTestCertificates
{
    private const TEST_KEY_LABEL = 'tindaflow-license-test-only';

    protected static function testKeyPair(string $label = self::TEST_KEY_LABEL): string
    {
        return sodium_crypto_sign_seed_keypair(hash('sha256', $label, true));
    }

    protected static function testPublicKeyBase64(string $label = self::TEST_KEY_LABEL): string
    {
        return base64_encode(sodium_crypto_sign_publickey(self::testKeyPair($label)));
    }

    /**
     * A valid v2 payload (ADR-018 decision 1). `exp` is 30 days after the renewal at 2026-11-24 09:00 +08:00, rounded up to 05:00.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected static function payload(array $overrides = []): array
    {
        return array_replace([
            'v' => 2,
            'typ' => 'tindaflow.activation',
            'kid' => 'test-2026-01',
            'deployment' => 'A1B2C3D4',
            'store' => '0b8f6f0e-2f0a-4d27-9a3e-0c1b6a5d4e11',
            'installation' => '7f3c9e2a-4b1d-4e8f-9a06-2d5b8c1e7f40',
            'fp' => [
                'smbios_uuid' => hash('sha256', 'tindaflow-fp-v1:smbios_uuid:TEST-UUID'),
                'board_serial' => hash('sha256', 'tindaflow-fp-v1:board_serial:TEST-BOARD'),
                'bios_serial' => hash('sha256', 'tindaflow-fp-v1:bios_serial:TEST-BIOS'),
            ],
            'seats' => 1,
            'mode' => 'enforce',
            'seq' => 42,
            'prev' => hash('sha256', 'certificate 41'),
            'iat' => '2026-11-24T09:00:00+08:00',
            'exp' => '2026-12-25T05:00:00+08:00',
            'jti' => '0123456789abcdef0123456789abcdef',
        ], $overrides);
    }

    /**
     * A valid v1 activation bundle (ADR-018 decision 9) around a valid certificate; `installation_key` is the all-`k` test key.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected static function bundlePayload(array $overrides = []): array
    {
        return array_replace([
            'v' => 1,
            'typ' => 'tindaflow.bundle',
            'kid' => 'test-2026-01',
            'store_id' => '0b8f6f0e-2f0a-4d27-9a3e-0c1b6a5d4e11',
            'deployment' => '6A5D4E11',
            'installation' => '7f3c9e2a-4b1d-4e8f-9a06-2d5b8c1e7f40',
            'installation_key' => base64_encode(str_repeat('k', 32)),
            'store_name' => 'TSC Canteen',
            'sale_document_mode' => 'SLIP',
            'slip_footer' => 'THIS DOCUMENT IS NOT A BIR-REGISTERED INVOICE AND IS NOT VALID FOR CLAIM OF INPUT TAX',
            'default_location' => 'Main stockroom',
            'till_code' => 'TILL-1',
            'certificate' => self::sign(self::payload()),
            'control_plane' => ['https://licensing.example.ph', 'https://licensing-standby.example.ph'],
            'iat' => '2026-11-24T09:00:00+08:00',
        ], $overrides);
    }

    /** @param  array<string, mixed>  $payload */
    protected static function sign(array $payload, string $label = self::TEST_KEY_LABEL): string
    {
        return self::signBytes(CanonicalJson::encode($payload), $label);
    }

    /** Signs exactly these bytes, canonical or not, so tests can present a validly signed but non-canonical payload. */
    protected static function signBytes(string $payloadBytes, string $label = self::TEST_KEY_LABEL): string
    {
        $signature = sodium_crypto_sign_detached($payloadBytes, sodium_crypto_sign_secretkey(self::testKeyPair($label)));

        return Base64Url::encode($payloadBytes).'.'.Base64Url::encode($signature);
    }
}
