<?php

namespace Tests\Unit\Services\License\Contract;

use App\Services\License\Contract\CertificateRejected;
use App\Services\License\Contract\KeyRing;
use App\Services\License\Contract\Revocation;
use App\Services\License\Contract\SignedEnvelope;
use PHPUnit\Framework\TestCase;

/** ADR-018 decision 7: only a vendor-signed revocation changes client state. */
class RevocationTest extends TestCase
{
    use SignsTestCertificates;

    private function payload2(array $overrides = []): array
    {
        return array_replace([
            'v' => 1, 'typ' => 'tindaflow.revocation', 'kid' => 'test-2026-01',
            'store' => '0b8f6f0e-2f0a-4d27-9a3e-0c1b6a5d4e11', 'installation' => '7f3c9e2a-4b1d-4e8f-9a06-2d5b8c1e7f40',
            'iat' => '2026-11-24T09:00:00+08:00',
        ], $overrides);
    }

    private function open(string $token): Revocation
    {
        return Revocation::fromPayload(SignedEnvelope::open($token, new KeyRing(['test-2026-01' => self::testPublicKeyBase64()])));
    }

    public function test_a_signed_revocation_is_read(): void
    {
        $revocation = $this->open(self::sign($this->payload2()));

        $this->assertSame('7f3c9e2a-4b1d-4e8f-9a06-2d5b8c1e7f40', $revocation->installation);
        $this->assertSame('0b8f6f0e-2f0a-4d27-9a3e-0c1b6a5d4e11', $revocation->store);
    }

    public function test_one_signed_by_another_key_is_refused(): void
    {
        $this->expectExceptionMessage(CertificateRejected::BAD_SIGNATURE);

        $this->open(self::sign($this->payload2(), 'someone-else'));
    }

    public function test_a_certificate_is_not_a_revocation(): void
    {
        $this->expectExceptionMessage(CertificateRejected::BAD_FIELD);

        $this->open(self::sign(self::payload()));
    }

    public function test_an_extra_field_or_a_wrong_type_is_refused(): void
    {
        foreach ([$this->payload2(['extra' => 1]), $this->payload2(['typ' => 'tindaflow.activation']), $this->payload2(['iat' => 'yesterday'])] as $payload) {
            try {
                $this->open(self::sign($payload));
                $this->fail('must be rejected');
            } catch (CertificateRejected $e) {
                $this->assertSame(CertificateRejected::BAD_FIELD, $e->reason);
            }
        }
    }
}
