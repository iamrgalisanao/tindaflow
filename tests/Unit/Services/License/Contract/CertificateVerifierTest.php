<?php

namespace Tests\Unit\Services\License\Contract;

use App\Services\License\Contract\Base64Url;
use App\Services\License\Contract\CanonicalJson;
use App\Services\License\Contract\Certificate;
use App\Services\License\Contract\CertificateRejected;
use App\Services\License\Contract\CertificateVerifier;
use App\Services\License\Contract\KeyRing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ADR-018 decision 1: a certificate is accepted only if a trusted, un-revoked key signed exactly its canonical bytes. */
class CertificateVerifierTest extends TestCase
{
    use SignsTestCertificates;

    private function verifier(array $revoked = []): CertificateVerifier
    {
        return new CertificateVerifier(new KeyRing(['test-2026-01' => self::testPublicKeyBase64()], $revoked));
    }

    private function assertRejected(string $reason, string $token, ?CertificateVerifier $verifier = null): void
    {
        try {
            ($verifier ?? $this->verifier())->verify($token);
            $this->fail("Expected the certificate to be rejected as {$reason}.");
        } catch (CertificateRejected $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function test_a_validly_signed_certificate_is_accepted_and_read_into_typed_fields(): void
    {
        $certificate = $this->verifier()->verify(self::sign(self::payload()));

        $this->assertInstanceOf(Certificate::class, $certificate);
        $this->assertSame('test-2026-01', $certificate->kid);
        $this->assertSame('A1B2C3D4', $certificate->deployment);
        $this->assertSame(1, $certificate->seats);
        $this->assertSame('enforce', $certificate->mode);
        $this->assertSame(42, $certificate->seq);
        $this->assertSame('2026-12-25T05:00:00+08:00', $certificate->exp->format('c'));
        $expectedFingerprint = self::payload()['fp'];
        ksort($expectedFingerprint); // the canonical form sorts object keys; the order carries no meaning
        $this->assertSame($expectedFingerprint, $certificate->fingerprint);
    }

    public function test_a_certificate_for_the_first_link_of_a_chain_has_no_previous_hash(): void
    {
        $certificate = $this->verifier()->verify(self::sign(self::payload(['seq' => 1, 'prev' => null])));

        $this->assertNull($certificate->prev);
    }

    public function test_the_payload_cannot_be_changed_under_the_same_signature(): void
    {
        $original = explode('.', self::sign(self::payload(['seats' => 1])));
        $forged = Base64Url::encode(CanonicalJson::encode(self::payload(['seats' => 50]))).'.'.$original[1];

        $this->assertRejected(CertificateRejected::BAD_SIGNATURE, $forged);
    }

    public function test_a_certificate_signed_by_another_key_is_refused_even_under_a_trusted_kid(): void
    {
        $this->assertRejected(CertificateRejected::BAD_SIGNATURE, self::sign(self::payload(), 'some-other-key'));
    }

    public function test_an_unknown_kid_is_refused(): void
    {
        $this->assertRejected(CertificateRejected::UNKNOWN_KID, self::sign(self::payload(['kid' => 'unknown-kid'])));
    }

    public function test_an_empty_key_ring_trusts_nothing(): void
    {
        $this->assertRejected(CertificateRejected::UNKNOWN_KID, self::sign(self::payload()), new CertificateVerifier(new KeyRing([])));
    }

    public function test_a_revoked_kid_is_refused_even_though_its_key_is_still_in_the_ring(): void
    {
        $this->assertRejected(CertificateRejected::REVOKED_KID, self::sign(self::payload()), $this->verifier(['test-2026-01']));
    }

    public function test_a_truncated_signature_is_malformed(): void
    {
        [$payload, $signature] = explode('.', self::sign(self::payload()));

        $this->assertRejected(CertificateRejected::MALFORMED, $payload.'.'.substr($signature, 0, 40));
    }

    #[DataProvider('malformedTokens')]
    public function test_something_that_is_not_two_base64url_parts_is_malformed(string $token): void
    {
        $this->assertRejected(CertificateRejected::MALFORMED, $token);
    }

    /** @return array<string, array{0: string}> */
    public static function malformedTokens(): array
    {
        return [
            'empty' => [''],
            'no dot' => ['abcdef'],
            'three parts' => ['a.b.c'],
            'padding characters' => ['e30=.e30='],
            'standard base64 characters' => ['e+/=.e30'],
            'not json' => [Base64Url::encode('not json').'.'.Base64Url::encode(str_repeat('x', 64))],
        ];
    }

    public function test_a_validly_signed_payload_that_is_not_in_canonical_form_is_refused(): void
    {
        $spaced = json_encode(self::payload());

        $this->assertRejected(CertificateRejected::NOT_CANONICAL, self::signBytes($spaced === false ? '' : str_replace(',"', ', "', $spaced)));
    }

    public function test_a_validly_signed_payload_with_a_float_is_refused(): void
    {
        $bytes = str_replace('"seats":1,', '"seats":1.0,', CanonicalJson::encode(self::payload()));

        $this->assertRejected(CertificateRejected::NOT_CANONICAL, self::signBytes($bytes));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badFields(): array
    {
        return [
            'wrong version' => [['v' => 1]],
            'wrong type' => [['typ' => 'tindaflow.extension']],
            'deployment not 8 upper-case hex' => [['deployment' => 'a1b2c3d4']],
            'store not a uuid' => [['store' => 'not-a-uuid']],
            'installation not a uuid' => [['installation' => '1234']],
            'fingerprint hash too short' => [['fp' => ['smbios_uuid' => 'ab', 'board_serial' => str_repeat('a', 64), 'bios_serial' => str_repeat('b', 64)]]],
            'fingerprint component missing' => [['fp' => ['smbios_uuid' => str_repeat('a', 64), 'board_serial' => str_repeat('b', 64)]]],
            'negative seats' => [['seats' => -1]],
            'seats as a string' => [['seats' => '1']],
            'unknown mode' => [['mode' => 'maybe']],
            'seq as a string' => [['seq' => '42']],
            'previous hash malformed' => [['prev' => 'xyz']],
            'issued-at without an offset' => [['iat' => '2026-11-24 09:00:00']],
            'expiry before issue' => [['exp' => '2026-11-23T05:00:00+08:00']],
            'jti not 128-bit hex' => [['jti' => 'abc']],
            'unknown extra field' => [['extra' => 'x']],
        ];
    }

    /** @param  array<string, mixed>  $overrides */
    #[DataProvider('badFields')]
    public function test_a_validly_signed_certificate_with_a_bad_field_is_refused(array $overrides): void
    {
        $this->assertRejected(CertificateRejected::BAD_FIELD, self::sign(self::payload($overrides)));
    }

    public function test_a_validly_signed_certificate_missing_a_field_is_refused(): void
    {
        $payload = self::payload();
        unset($payload['jti']);

        $this->assertRejected(CertificateRejected::BAD_FIELD, self::sign($payload));
    }
}
