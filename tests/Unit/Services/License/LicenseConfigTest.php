<?php

namespace Tests\Unit\Services\License;

use App\Services\License\Contract\KeyRing;
use App\Services\License\LicenseEvaluator;
use App\Services\License\LicenseState;
use App\Services\License\LocalFacts;
use DateTimeImmutable;
use Tests\TestCase;

/**
 * Safe by default (ADR-018 decision 11): the build as shipped carries no vendor key, so the new license code decides nothing
 * and every existing installation behaves exactly as before. This is the guard the later slices lean on.
 */
class LicenseConfigTest extends TestCase
{
    public function test_the_shipped_build_trusts_no_vendor_key_and_revokes_none(): void
    {
        $this->assertSame([], config('tindaflow.license.vendor_keys'));
        $this->assertSame([], config('tindaflow.license.revoked_kids'));
    }

    public function test_the_shipped_build_trusts_no_offline_code_key_and_revokes_none(): void
    {
        $this->assertSame([], config('tindaflow.license.code_keys'));
        $this->assertSame([], config('tindaflow.license.revoked_code_keys'));
    }

    public function test_an_empty_key_ring_means_the_evaluator_reports_legacy_and_refuses_nothing(): void
    {
        $ring = new KeyRing(config('tindaflow.license.vendor_keys'), config('tindaflow.license.revoked_kids'));
        $now = new DateTimeImmutable('2026-11-24T09:00:00+08:00');

        $verdict = (new LicenseEvaluator)->evaluate(null, new LocalFacts($now), ! $ring->isEmpty());

        $this->assertTrue($ring->isEmpty());
        $this->assertSame(LicenseState::Legacy, $verdict->state);
        $this->assertFalse($verdict->enforcing);
        $this->assertNull($verdict->shiftOpenRefusal());
        $this->assertNull($verdict->saleRefusal($now->modify('-30 days'), $now));
    }
}
