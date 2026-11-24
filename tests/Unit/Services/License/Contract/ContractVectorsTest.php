<?php

namespace Tests\Unit\Services\License\Contract;

use App\Services\License\Contract\Base64Url;
use App\Services\License\Contract\CanonicalJson;
use App\Services\License\Contract\CertificateRejected;
use App\Services\License\Contract\CertificateVerifier;
use App\Services\License\Contract\KeyRing;
use PHPUnit\Framework\TestCase;

/**
 * The golden file the control plane's tests must also pass (tests/Fixtures/license-contract/vectors.v1.json). It pins the
 * wire format: the same canonical bytes, the same signed tokens (by SHA-256) and the same verdicts, today and after any
 * refactor. An independent implementation checks the same file (verify-with-node.mjs), so the PHP code is never only
 * consistent with itself. The file stores no signed tokens, because they look like credentials to secret scanners; the
 * tests rebuild them from the public test seed. The key is TEST ONLY.
 */
class ContractVectorsTest extends TestCase
{
    use SignsTestCertificates;

    /** @return array<string, mixed> */
    private function vectors(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../../../Fixtures/license-contract/vectors.v1.json'), true, 16, JSON_THROW_ON_ERROR);
    }

    private function verifier(array $vectors): CertificateVerifier
    {
        return new CertificateVerifier(new KeyRing([$vectors['kid'] => $vectors['public_key_base64']]));
    }

    /** @param  array<string, mixed>  $recipe */
    private function tokenFor(array $recipe, array $basePayload): string
    {
        return match ($recipe['build']) {
            'sign' => self::sign($recipe['overrides'] + $basePayload),
            'swap_payload' => Base64Url::encode(CanonicalJson::encode($recipe['overrides'] + $basePayload)).'.'.explode('.', self::sign($basePayload))[1],
            'truncate_signature' => explode('.', self::sign($basePayload))[0].'.'.substr(explode('.', self::sign($basePayload))[1], 0, $recipe['keep']),
            'sign_spaced' => self::signBytes(str_replace(',"', ', "', CanonicalJson::encode($basePayload))),
        };
    }

    public function test_the_golden_key_is_the_documented_test_only_key(): void
    {
        $vectors = $this->vectors();

        $this->assertSame(self::testPublicKeyBase64(), $vectors['public_key_base64']);
        $this->assertSame('tindaflow-license-test-only', $vectors['seed_label']);
        $this->assertSame(1, $vectors['contract_version']);
    }

    public function test_the_file_holds_no_signed_tokens(): void
    {
        // Two base64url runs joined by a dot is the shape of a token; a secret scanner cannot tell one from a credential.
        $this->assertSame(0, preg_match('/[A-Za-z0-9_-]{40,}\.[A-Za-z0-9_-]{40,}/', file_get_contents(__DIR__.'/../../../../Fixtures/license-contract/vectors.v1.json')));
    }

    public function test_every_canonicalization_vector_is_reproduced_byte_for_byte(): void
    {
        foreach ($this->vectors()['canonicalization'] as $case) {
            $this->assertSame($case['canonical'], CanonicalJson::encode($case['input']), json_encode($case['input']));
        }
    }

    public function test_every_accepted_vector_verifies_and_its_token_is_reproduced_exactly(): void
    {
        $vectors = $this->vectors();

        foreach ($vectors['accepted'] as $case) {
            $token = self::sign($case['payload']);

            $this->assertSame($case['canonical'], CanonicalJson::encode($case['payload']), $case['name']);
            $this->assertSame($case['token_sha256'], hash('sha256', $token), $case['name'].': Ed25519 is deterministic, so the token is reproducible');
            $this->assertSame($case['payload']['seq'], $this->verifier($vectors)->verify($token)->seq, $case['name']);
        }
    }

    public function test_every_rejected_vector_is_refused_for_the_documented_reason(): void
    {
        $vectors = $this->vectors();

        foreach ($vectors['rejected'] as $case) {
            try {
                $this->verifier($vectors)->verify($this->tokenFor($case['recipe'], $vectors['base_payload']));
                $this->fail($case['name'].' must be rejected.');
            } catch (CertificateRejected $e) {
                $this->assertSame($case['reason'], $e->reason, $case['name']);
            }
        }
    }
}
