<?php

namespace Tests\Unit\Services\License\Renewal;

use App\Services\License\Contract\Certificate;
use App\Services\License\Contract\CertificateVerifier;
use App\Services\License\Contract\KeyRing;
use App\Services\License\Renewal\ChainRules;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Services\License\Contract\SignsTestCertificates;

/** ADR-018 decisions 1 and 5: what the client insists on before it replaces its certificate with the one the control plane returned. */
class ChainRulesTest extends TestCase
{
    use SignsTestCertificates;

    private function token(array $overrides = []): string
    {
        return self::sign(self::payload($overrides));
    }

    private function certificate(string $token): Certificate
    {
        return (new CertificateVerifier(new KeyRing(['test-2026-01' => self::testPublicKeyBase64()])))->verify($token);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-24T09:00:00+08:00');
    }

    public function test_the_next_certificate_of_the_same_installation_is_accepted(): void
    {
        $current = $this->certificate($this->token(['seq' => 42]));
        $next = $this->certificate($this->token(['seq' => 43, 'jti' => str_repeat('b', 32)]));

        $this->assertNull(ChainRules::refusal($current, $next, $this->now()));
    }

    public function test_a_different_installation_store_or_deployment_is_refused(): void
    {
        $current = $this->certificate($this->token());

        foreach ([['installation' => '11111111-2222-4333-8444-555555555555'], ['store' => '11111111-2222-4333-8444-555555555555'], ['deployment' => 'FFFFFFFF']] as $change) {
            $other = $this->certificate($this->token(['seq' => 43] + $change));
            $this->assertStringContainsString('different installation', (string) ChainRules::refusal($current, $other, $this->now()));
        }
    }

    public function test_a_seq_that_goes_backwards_is_refused(): void
    {
        $current = $this->certificate($this->token(['seq' => 42]));
        $older = $this->certificate($this->token(['seq' => 41]));

        $this->assertStringContainsString('seq went backwards', (string) ChainRules::refusal($current, $older, $this->now()));
    }

    public function test_the_same_seq_with_another_jti_is_a_fork(): void
    {
        $current = $this->certificate($this->token(['seq' => 42]));
        $forked = $this->certificate($this->token(['seq' => 42, 'jti' => str_repeat('c', 32)]));

        $this->assertStringContainsString('forked', (string) ChainRules::refusal($current, $forked, $this->now()));
    }

    public function test_the_same_certificate_again_is_accepted_and_recognised_as_the_same(): void
    {
        $current = $this->certificate($this->token());

        $this->assertNull(ChainRules::refusal($current, $current, $this->now()));
        $this->assertTrue(ChainRules::isSame($current, $current));
    }

    public function test_a_certificate_that_has_already_expired_is_refused(): void
    {
        $current = $this->certificate($this->token(['seq' => 42]));
        $stale = $this->certificate($this->token(['seq' => 43, 'iat' => '2026-10-01T09:00:00+08:00', 'exp' => '2026-11-01T05:00:00+08:00']));

        $this->assertStringContainsString('expired', (string) ChainRules::refusal($current, $stale, $this->now()));
    }

    public function test_a_clean_link_is_the_next_seq_with_the_previous_tokens_hash(): void
    {
        $currentToken = $this->token(['seq' => 42]);
        $current = $this->certificate($currentToken);

        $clean = $this->certificate($this->token(['seq' => 43, 'prev' => hash('sha256', $currentToken)]));
        $gap = $this->certificate($this->token(['seq' => 45, 'prev' => hash('sha256', $currentToken)]));
        $wrongPrev = $this->certificate($this->token(['seq' => 43, 'prev' => hash('sha256', 'something else')]));

        $this->assertTrue(ChainRules::linksCleanly($currentToken, $current, $clean));
        $this->assertFalse(ChainRules::linksCleanly($currentToken, $current, $gap));
        $this->assertFalse(ChainRules::linksCleanly($currentToken, $current, $wrongPrev));
    }
}
