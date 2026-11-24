<?php

namespace Tests\Unit\Services\License\Renewal;

use App\Services\License\Renewal\Rfc9421Signer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** ADR-018 decision 8: every machine request is signed per RFC 9421 over the method, target URI, content digest and content type. */
class Rfc9421SignerTest extends TestCase
{
    private const URL = 'https://licensing.example.ph/v1/renewals';

    private const BODY = '{"v":1,"request_id":"0b8f6f0e-2f0a-4d27-9a3e-0c1b6a5d4e11"}';

    private function keys(): array
    {
        $pair = sodium_crypto_sign_seed_keypair(hash('sha256', 'signer-test', true));

        return [base64_encode(sodium_crypto_sign_secretkey($pair)), sodium_crypto_sign_publickey($pair)];
    }

    public function test_the_content_digest_is_the_rfc_9530_sha_256_of_the_exact_body(): void
    {
        $this->assertSame('sha-256=:'.base64_encode(hash('sha256', self::BODY, true)).':', Rfc9421Signer::contentDigest(self::BODY));
        $this->assertNotSame(Rfc9421Signer::contentDigest(self::BODY), Rfc9421Signer::contentDigest(self::BODY.' '));
    }

    public function test_the_signature_verifies_over_a_base_rebuilt_independently(): void
    {
        [$secret, $public] = $this->keys();

        $headers = Rfc9421Signer::headers('POST', self::URL, self::BODY, 'application/json', 'installation-uuid', 'tindaflow-renewal', 'nonce-0123456789abcdef', $secret);

        $params = '("@method" "@target-uri" "content-digest" "content-type");keyid="installation-uuid";tag="tindaflow-renewal";nonce="nonce-0123456789abcdef"';
        $expectedBase = "\"@method\": POST\n\"@target-uri\": ".self::URL."\n\"content-digest\": ".$headers['Content-Digest']."\n\"content-type\": application/json\n\"@signature-params\": ".$params;

        $this->assertSame('sig1='.$params, $headers['Signature-Input']);
        $this->assertSame(1, preg_match('/\Asig1=:([A-Za-z0-9+\/]+=*):\z/', $headers['Signature'], $match));
        $this->assertTrue(sodium_crypto_sign_verify_detached(base64_decode($match[1], true), $expectedBase, $public));
    }

    public function test_a_changed_body_or_url_no_longer_verifies(): void
    {
        [$secret, $public] = $this->keys();
        $headers = Rfc9421Signer::headers('POST', self::URL, self::BODY, 'application/json', 'k', 'tindaflow-renewal', 'nonce-0123456789abcdef', $secret);
        preg_match('/:([^:]+):/', $headers['Signature'], $match);
        $signature = base64_decode($match[1], true);
        $params = '("@method" "@target-uri" "content-digest" "content-type");keyid="k";tag="tindaflow-renewal";nonce="nonce-0123456789abcdef"';

        $otherBody = Rfc9421Signer::signatureBase('POST', self::URL, Rfc9421Signer::contentDigest(self::BODY.'x'), 'application/json', $params);
        $otherUrl = Rfc9421Signer::signatureBase('POST', 'https://evil.example/v1/renewals', $headers['Content-Digest'], 'application/json', $params);

        $this->assertFalse(sodium_crypto_sign_verify_detached($signature, $otherBody, $public));
        $this->assertFalse(sodium_crypto_sign_verify_detached($signature, $otherUrl, $public));
    }

    public function test_a_parameter_with_a_quote_or_space_is_refused(): void
    {
        [$secret] = $this->keys();

        $this->expectException(InvalidArgumentException::class);
        Rfc9421Signer::headers('POST', self::URL, self::BODY, 'application/json', 'k', 'tag', 'bad"nonce injected', $secret);
    }

    public function test_a_key_that_is_not_a_64_byte_ed25519_secret_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Rfc9421Signer::headers('POST', self::URL, self::BODY, 'application/json', 'k', 'tag', 'nonce-0123456789abcdef', base64_encode('short'));
    }
}
