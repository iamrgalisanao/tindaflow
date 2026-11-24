<?php

namespace Tests\Unit\Services\License\Contract;

use App\Services\License\Contract\BundleVerifier;
use App\Services\License\Contract\CertificateRejected;
use App\Services\License\Contract\KeyRing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ADR-018 decision 9: the activation bundle is vendor-signed and strictly shaped, like the certificate it carries. */
class BundleVerifierTest extends TestCase
{
    use SignsTestCertificates;

    private function verifier(): BundleVerifier
    {
        return new BundleVerifier(new KeyRing(['test-2026-01' => self::testPublicKeyBase64()]));
    }

    public function test_a_valid_bundle_is_accepted_and_read_field_by_field(): void
    {
        $bundle = $this->verifier()->verify(self::sign(self::bundlePayload()));

        $this->assertSame('0b8f6f0e-2f0a-4d27-9a3e-0c1b6a5d4e11', $bundle->storeId);
        $this->assertSame('TSC Canteen', $bundle->storeName);
        $this->assertSame('SLIP', $bundle->saleDocumentMode);
        $this->assertSame('TILL-1', $bundle->tillCode);
        $this->assertSame(['https://licensing.example.ph', 'https://licensing-standby.example.ph'], $bundle->controlPlane);
        $this->assertSame(self::sign(self::payload()), $bundle->certificate);
    }

    public function test_a_footer_may_be_absent(): void
    {
        $this->assertNull($this->verifier()->verify(self::sign(self::bundlePayload(['slip_footer' => null])))->slipFooter);
    }

    public function test_a_bundle_signed_by_an_unknown_key_is_refused_before_its_fields_are_read(): void
    {
        $this->expectException(CertificateRejected::class);
        $this->expectExceptionMessage(CertificateRejected::BAD_SIGNATURE);

        $this->verifier()->verify(self::sign(self::bundlePayload(), 'a-different-label'));
    }

    public function test_a_certificate_token_is_not_a_bundle(): void
    {
        $this->expectException(CertificateRejected::class);
        $this->expectExceptionMessage(CertificateRejected::BAD_FIELD);

        $this->verifier()->verify(self::sign(self::payload()));
    }

    public function test_an_extra_field_is_refused(): void
    {
        $this->expectExceptionMessage(CertificateRejected::BAD_FIELD);

        $this->verifier()->verify(self::sign(self::bundlePayload() + ['extra' => 'x']));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badFields(): array
    {
        return [
            'wrong type' => [['typ' => 'tindaflow.activation']],
            'wrong version' => [['v' => 2]],
            'store id not a uuid' => [['store_id' => 'nope']],
            'deployment not 8 upper-case hex' => [['deployment' => '6a5d4e11']],
            'installation key too short' => [['installation_key' => base64_encode('short')]],
            'empty store name' => [['store_name' => '']],
            'store name with a control character' => [['store_name' => "A\nB"]],
            'unknown document mode' => [['sale_document_mode' => 'RECEIPT']],
            'footer over 200 characters' => [['slip_footer' => str_repeat('x', 201)]],
            'no till code' => [['till_code' => '']],
            'no certificate' => [['certificate' => '']],
            'no control plane url' => [['control_plane' => []]],
            'control plane over plain http' => [['control_plane' => ['http://licensing.example.ph']]],
            'five control plane urls' => [['control_plane' => array_fill(0, 5, 'https://a.example.ph')]],
            'control plane as an object' => [['control_plane' => ['primary' => 'https://a.example.ph']]],
            'iat not an offset time' => [['iat' => '2026-11-24 09:00:00']],
        ];
    }

    /** @param  array<string, mixed>  $override */
    #[DataProvider('badFields')]
    public function test_each_malformed_field_is_refused(array $override): void
    {
        try {
            $this->verifier()->verify(self::sign(self::bundlePayload($override)));
            $this->fail('must be rejected');
        } catch (CertificateRejected $e) {
            $this->assertSame(CertificateRejected::BAD_FIELD, $e->reason);
        }
    }
}
