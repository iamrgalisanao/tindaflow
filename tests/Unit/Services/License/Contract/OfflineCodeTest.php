<?php

namespace Tests\Unit\Services\License\Contract;

use App\Services\License\Contract\CertificateRejected;
use App\Services\License\Contract\CodeText;
use App\Services\License\Contract\OfflineCode;
use App\Services\License\Contract\OfflineCodeVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** ADR-018 decision 6: the offline code's 23 bytes, its written form, and who may sign it. */
class OfflineCodeTest extends TestCase
{
    use SignsTestCertificates;

    private function verifier(): OfflineCodeVerifier
    {
        return new OfflineCodeVerifier([1 => self::testCodePublicKeyBase64()]);
    }

    private function reason(string $text, ?OfflineCodeVerifier $verifier = null): ?string
    {
        try {
            ($verifier ?? $this->verifier())->verify($text);

            return null;
        } catch (CertificateRejected $e) {
            return $e->reason;
        }
    }

    public function test_the_payload_is_23_bytes_and_the_written_code_is_169_characters_in_groups_of_five(): void
    {
        $code = self::offlineCode();
        $text = self::signCode($code);

        $this->assertSame(23, strlen($code->toBytes()));
        $this->assertSame(169, strlen($text));
        $this->assertSame(1, preg_match('/\A([0-9A-HJKMNP-TV-Z]{5}-){28}[0-9A-HJKMNP-TV-Z*~$=U]\z/', $text), $text);
    }

    public function test_a_signed_code_reads_back_field_for_field(): void
    {
        $read = $this->verifier()->verify(self::signCode(self::offlineCode(['baseSeq' => 4294967295, 'ordinal' => 65535])));

        $this->assertSame(OfflineCode::EXTENSION, $read->type);
        $this->assertSame('A1B2C3D4', $read->deployment);
        $this->assertSame(OfflineCode::digestOf('7f3c9e2a-4b1d-4e8f-9a06-2d5b8c1e7f40'), $read->installationDigest);
        $this->assertSame(4294967295, $read->baseSeq);
        $this->assertSame(30, $read->days);
        $this->assertSame(65535, $read->ordinal);
        $this->assertSame(strtotime('2026-11-24T09:00:00+08:00'), $read->iat->getTimestamp());
    }

    public function test_every_golden_code_is_reproduced_and_accepted(): void
    {
        $vectors = json_decode((string) file_get_contents(__DIR__.'/../../../../Fixtures/license-contract/vectors.v1.json'), true, 32, JSON_THROW_ON_ERROR)['offline_codes'];
        $this->assertSame(self::testCodePublicKeyBase64(), $vectors['public_key_base64']);

        foreach ($vectors['accepted'] as $case) {
            $code = OfflineCode::make($case['type'], $case['key_id'], $case['deployment'], OfflineCode::digestOf($case['installation']), $case['base_seq'], $case['days'], $case['ordinal'], new \DateTimeImmutable('@'.$case['iat']));
            $text = self::signCode($code);

            $this->assertSame($case['payload_hex'], bin2hex($code->toBytes()), $case['name']);
            $this->assertSame($case['code_sha256'], hash('sha256', $text), $case['name']);
            $this->assertSame($case['ordinal'], (new OfflineCodeVerifier([$case['key_id'] => $vectors['public_key_base64']]))->verify($text)->ordinal);
        }
    }

    public function test_a_code_survives_being_copied_carelessly(): void
    {
        $text = self::signCode(self::offlineCode());
        $mangled = ' '.strtolower(str_replace('-', " \n", $text)).' ';

        $this->assertNull($this->reason($mangled));
        $this->assertNull($this->reason(strtr($text, ['1' => 'I', '0' => 'O'])), 'I for 1 and O for 0 are read as meant');
    }

    public function test_every_single_mistyped_character_is_caught_as_mistyped(): void
    {
        $text = str_replace('-', '', self::signCode(self::offlineCode()));

        // Every position but the last two symbols' padding: one wrong digit must never get as far as a signature check.
        for ($i = 0; $i < 139; $i++) {
            $wrong = $text;
            $wrong[$i] = $text[$i] === '2' ? '3' : '2';

            $this->assertSame(CertificateRejected::MALFORMED, $this->reason($wrong), "position {$i}");
        }
    }

    public function test_a_missing_or_added_character_is_caught(): void
    {
        $text = self::signCode(self::offlineCode());

        $this->assertSame(CertificateRejected::MALFORMED, $this->reason(substr($text, 0, -1)));
        $this->assertSame(CertificateRejected::MALFORMED, $this->reason($text.'A'));
        $this->assertSame(CertificateRejected::MALFORMED, $this->reason(''));
    }

    public function test_a_certificate_key_cannot_sign_a_code(): void
    {
        $this->assertSame(CertificateRejected::BAD_SIGNATURE, $this->reason(self::signCode(self::offlineCode(), 'tindaflow-license-test-only')));
    }

    public function test_an_unknown_or_revoked_code_key_is_refused(): void
    {
        $text = self::signCode(self::offlineCode(['keyId' => 7]));

        $this->assertSame(CertificateRejected::UNKNOWN_KID, $this->reason($text));
        $this->assertSame(CertificateRejected::REVOKED_KID, $this->reason($text, new OfflineCodeVerifier([7 => self::testCodePublicKeyBase64()], [7])));
    }

    public function test_the_type_is_inside_what_is_signed(): void
    {
        $bytes = CodeText::decode(self::signCode(self::offlineCode()));
        $bytes[0] = chr(OfflineCode::TIME_RESET);

        $this->assertSame(CertificateRejected::BAD_SIGNATURE, $this->reason(CodeText::encode($bytes)));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function badFields(): array
    {
        return [
            'unknown type' => [['type' => 3]],
            'key id zero' => [['keyId' => 0]],
            'deployment not 8 hex' => [['deployment' => 'abc']],
            'base seq zero' => [['baseSeq' => 0]],
            'an extension with no days' => [['days' => 0]],
            'more than 30 days' => [['days' => 31]],
            'a time reset with days' => [['type' => OfflineCode::TIME_RESET, 'days' => 30]],
            'ordinal zero' => [['ordinal' => 0]],
            'ordinal past two bytes' => [['ordinal' => 65536]],
        ];
    }

    /** @param  array<string, mixed>  $override */
    #[DataProvider('badFields')]
    public function test_a_field_out_of_range_cannot_be_made(array $override): void
    {
        $this->expectException(CertificateRejected::class);

        self::offlineCode($override);
    }
}
