<?php

namespace Tests\Unit\Services\License;

use App\Services\License\Contract\Certificate;
use App\Services\License\Contract\CertificateVerifier;
use App\Services\License\Contract\KeyRing;
use App\Services\License\LicenseEvaluator;
use App\Services\License\LicenseState;
use App\Services\License\LicenseVerdict;
use App\Services\License\LocalFacts;
use App\Services\License\RefusalReason;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Services\License\Contract\SignsTestCertificates;

/**
 * ADR-018 decision 2, row by row: the state table, LOCKED taking precedence, trusted time, report mode, LEGACY, and the
 * 18-hour cap on an open shift. The certificate expires at 2026-12-25 05:00 +08:00 in every case below.
 */
class LicenseEvaluatorTest extends TestCase
{
    use SignsTestCertificates;

    private const EXP = '2026-12-25T05:00:00+08:00';

    private function certificate(array $overrides = []): Certificate
    {
        $ring = new KeyRing(['test-2026-01' => self::testPublicKeyBase64()]);

        return (new CertificateVerifier($ring))->verify(self::sign(self::payload($overrides)));
    }

    private function at(string $offsetFromExp): DateTimeImmutable
    {
        return (new DateTimeImmutable(self::EXP))->modify($offsetFromExp);
    }

    /** @param  array<string, mixed>  $facts */
    private function evaluate(?Certificate $certificate, array $facts = [], bool $configured = true, bool $legacy = false): LicenseVerdict
    {
        $local = new LocalFacts(
            wallNow: $facts['wall'] ?? $this->at('-20 days'),
            trustedTimeHwm: $facts['hwm'] ?? null,
            observedFingerprint: array_key_exists('fp', $facts) ? $facts['fp'] : self::payload()['fp'],
            revoked: $facts['revoked'] ?? false,
            chainInvalid: $facts['chainInvalid'] ?? false,
        );

        return (new LicenseEvaluator)->evaluate($certificate, $local, $configured, $legacy);
    }

    public function test_with_no_vendor_key_in_the_build_nothing_is_enforced_whatever_else_is_present(): void
    {
        $verdict = $this->evaluate($this->certificate(), ['wall' => $this->at('+30 days')], configured: false);

        $this->assertSame(LicenseState::Legacy, $verdict->state);
        $this->assertFalse($verdict->enforcing);
        $this->assertNull($verdict->shiftOpenRefusal());
        $this->assertNull($verdict->saleRefusal($this->at('-100 days'), $this->at('+30 days')));
    }

    public function test_a_key_and_no_certificate_is_not_activated(): void
    {
        $verdict = $this->evaluate(null);

        $this->assertSame(LicenseState::NotActivated, $verdict->state);
        $this->assertTrue($verdict->enforcing);
        $this->assertSame(RefusalReason::NotActivated, $verdict->shiftOpenRefusal());
    }

    public function test_an_installation_still_holding_an_adr_014_license_stays_legacy_until_it_is_adopted(): void
    {
        $verdict = $this->evaluate(null, legacy: true);

        $this->assertSame(LicenseState::Legacy, $verdict->state);
        $this->assertFalse($verdict->enforcing);
        $this->assertNull($verdict->shiftOpenRefusal());
    }

    /** @return array<string, array{0: string, 1: LicenseState, 2: ?RefusalReason}> */
    public static function timeline(): array
    {
        return [
            'long before the warning' => ['-20 days', LicenseState::Active, null],
            'one second before the warning window' => ['-7 days -1 second', LicenseState::Active, null],
            'exactly 7 days before expiry is still active' => ['-7 days', LicenseState::Active, null],
            'one second inside the warning window' => ['-7 days +1 second', LicenseState::Warning, null],
            'the moment of expiry is still warning' => ['+0 seconds', LicenseState::Warning, null],
            'one second after expiry' => ['+1 second', LicenseState::Expired, RefusalReason::Expired],
            'long after expiry' => ['+40 days', LicenseState::Expired, RefusalReason::Expired],
        ];
    }

    #[DataProvider('timeline')]
    public function test_the_state_table_around_every_boundary(string $offset, LicenseState $state, ?RefusalReason $refusal): void
    {
        $verdict = $this->evaluate($this->certificate(), ['wall' => $this->at($offset)]);

        $this->assertSame($state, $verdict->state);
        $this->assertSame($refusal, $verdict->shiftOpenRefusal());
        $this->assertTrue($verdict->enforcing);
    }

    public function test_a_report_mode_certificate_computes_the_state_but_does_not_enforce(): void
    {
        $verdict = $this->evaluate($this->certificate(['mode' => 'report']), ['wall' => $this->at('+2 days')]);

        $this->assertSame(LicenseState::Expired, $verdict->state);
        $this->assertFalse($verdict->enforcing, 'report mode logs the decision and refuses nothing');
        $this->assertSame(RefusalReason::Expired, $verdict->shiftOpenRefusal(), 'what it WOULD have refused, for the pilot log');
    }

    public function test_a_revoked_installation_is_locked_even_though_the_certificate_is_still_in_date(): void
    {
        $verdict = $this->evaluate($this->certificate(), ['revoked' => true]);

        $this->assertSame(LicenseState::Locked, $verdict->state);
        $this->assertSame(RefusalReason::Revoked, $verdict->shiftOpenRefusal());
    }

    public function test_a_broken_chain_is_locked(): void
    {
        $verdict = $this->evaluate($this->certificate(), ['chainInvalid' => true]);

        $this->assertSame(LicenseState::Locked, $verdict->state);
        $this->assertSame(RefusalReason::ChainInvalid, $verdict->shiftOpenRefusal());
    }

    public function test_one_matching_hardware_hash_is_locked_and_two_are_tolerated(): void
    {
        $two = ['bios_serial' => str_repeat('0', 64)] + self::payload()['fp'];
        $one = ['board_serial' => str_repeat('0', 64)] + $two;

        $this->assertSame(LicenseState::Active, $this->evaluate($this->certificate(), ['fp' => $two])->state);

        $verdict = $this->evaluate($this->certificate(), ['fp' => $one]);
        $this->assertSame(LicenseState::Locked, $verdict->state);
        $this->assertSame(RefusalReason::FingerprintMismatch, $verdict->shiftOpenRefusal());
    }

    public function test_a_missing_or_stale_host_file_changes_no_state(): void
    {
        $this->assertSame(LicenseState::Active, $this->evaluate($this->certificate(), ['fp' => null])->state);
    }

    public function test_a_clock_one_hour_behind_the_high_water_mark_is_tolerated_and_one_second_more_is_locked(): void
    {
        $hwm = $this->at('-10 days');

        $this->assertSame(LicenseState::Active, $this->evaluate($this->certificate(), ['hwm' => $hwm, 'wall' => $hwm->modify('-1 hour')])->state);

        $verdict = $this->evaluate($this->certificate(), ['hwm' => $hwm, 'wall' => $hwm->modify('-1 hour -1 second')]);
        $this->assertSame(LicenseState::Locked, $verdict->state);
        $this->assertSame(RefusalReason::ClockRollback, $verdict->shiftOpenRefusal());
    }

    public function test_trusted_time_is_the_later_of_the_clock_and_the_high_water_mark(): void
    {
        // The wall clock says the certificate is in date, but the mark proves time has already passed expiry.
        $hwm = $this->at('+1 second');
        $verdict = $this->evaluate($this->certificate(), ['hwm' => $hwm, 'wall' => $hwm->modify('-30 minutes')]);

        $this->assertSame(LicenseState::Expired, $verdict->state, 'a clock set back by minutes does not buy the certificate more time');
    }

    public function test_locked_reasons_have_a_fixed_precedence(): void
    {
        $everythingWrong = ['revoked' => true, 'chainInvalid' => true, 'fp' => [], 'hwm' => $this->at('-1 day'), 'wall' => $this->at('-3 days')];

        $this->assertSame(RefusalReason::Revoked, $this->evaluate($this->certificate(), $everythingWrong)->shiftOpenRefusal());
        $this->assertSame(RefusalReason::ChainInvalid, $this->evaluate($this->certificate(), ['revoked' => false] + $everythingWrong)->shiftOpenRefusal());
        $this->assertSame(RefusalReason::FingerprintMismatch, $this->evaluate($this->certificate(), ['revoked' => false, 'chainInvalid' => false] + $everythingWrong)->shiftOpenRefusal());
        $this->assertSame(RefusalReason::ClockRollback, $this->evaluate($this->certificate(), ['revoked' => false, 'chainInvalid' => false, 'fp' => self::payload()['fp']] + $everythingWrong)->shiftOpenRefusal());
    }

    public function test_locked_beats_expired(): void
    {
        $verdict = $this->evaluate($this->certificate(), ['wall' => $this->at('+5 days'), 'revoked' => true]);

        $this->assertSame(LicenseState::Locked, $verdict->state);
    }

    public function test_an_open_shift_in_a_good_license_is_never_capped(): void
    {
        foreach (['-20 days', '-7 days +1 second'] as $offset) {
            $verdict = $this->evaluate($this->certificate(), ['wall' => $this->at($offset)]);

            $this->assertNull($verdict->saleRefusal($this->at('-200 days'), $this->at($offset)), $offset);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function statesThatCapAnOpenShift(): array
    {
        return ['expired' => ['expired'], 'locked' => ['locked'], 'not activated' => ['notActivated']];
    }

    #[DataProvider('statesThatCapAnOpenShift')]
    public function test_when_expired_locked_or_not_activated_a_sale_is_refused_after_18_hours_from_opening(string $kind): void
    {
        $now = $this->at('+2 days');
        $verdict = match ($kind) {
            'expired' => $this->evaluate($this->certificate(), ['wall' => $now]),
            'locked' => $this->evaluate($this->certificate(), ['wall' => $now, 'revoked' => true]),
            'notActivated' => $this->evaluate(null, ['wall' => $now]),
        };

        $this->assertNull($verdict->saleRefusal($now->modify('-18 hours'), $now), 'exactly 18 hours is still allowed');
        $this->assertSame(RefusalReason::ShiftTooLong, $verdict->saleRefusal($now->modify('-18 hours -1 second'), $now));
    }

    public function test_the_cap_is_measured_on_trusted_time_so_a_rolled_back_clock_cannot_stretch_it(): void
    {
        $opened = $this->at('+2 days');
        $verdict = $this->evaluate($this->certificate(), ['wall' => $opened->modify('+1 hour')]);

        // The caller passes trusted_now = max(wall, mark); a mark 19 hours after opening wins over a clock set back to "1 hour after".
        $this->assertSame(RefusalReason::ShiftTooLong, $verdict->saleRefusal($opened, $opened->modify('+19 hours')));
    }

    public function test_the_refusal_reasons_are_the_error_codes_of_the_adr(): void
    {
        $this->assertSame(
            ['NOT_ACTIVATED', 'EXPIRED', 'CLOCK_ROLLBACK', 'FINGERPRINT_MISMATCH', 'REVOKED', 'CHAIN_INVALID', 'SHIFT_TOO_LONG'],
            array_map(fn (RefusalReason $reason) => $reason->value, RefusalReason::cases()),
        );
    }
}
