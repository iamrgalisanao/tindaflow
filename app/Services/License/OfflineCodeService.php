<?php

namespace App\Services\License;

use App\Services\Audit\AuditTrail;
use App\Services\License\Contract\Certificate;
use App\Services\License\Contract\CertificateRejected;
use App\Services\License\Contract\OfflineCode;
use App\Services\License\Contract\OfflineCodeVerifier;
use App\Services\License\Time\TrustedClock;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Applies an offline code (ADR-018 decisions 3 and 6). A code counts only if a code key signed it for THIS installation and for
 * the certificate held right now, and only once: its ordinal must be higher than any applied before, whatever its type.
 *
 * - An EXTENSION moves the expiry to the next 05:00 at or after (the later of the certificate's expiry and the code's issue time)
 *   plus its days, at most two in a row without a renewal. It never unlocks a LOCKED installation, and one that would not make the
 *   expiry later is refused without being used up.
 * - A TIME_RESET sets the trusted-time mark to the code's issue time, for a clock that was once set into the future.
 *
 * It shares the renewal's lock, so a code and a renewal cannot be applied over each other.
 */
final class OfflineCodeService
{
    public const MAX_EXTENSIONS_IN_A_ROW = 2;

    /** A code issued further ahead of this PC's clock than this means the clock is wrong (the rollback tolerance, reused). */
    private const CLOCK_SKEW_SECONDS = 3600;

    public function __construct(
        private readonly LicenseGate $gate,
        private readonly TrustedClock $clock,
        private readonly HostHardware $hardware,
        private readonly ExpiryCalculator $expiry,
    ) {}

    /**
     * @param  string|null  $actorUserId  who typed it in the Manager area; null for the console
     * @return string what was done, for the person who typed the code
     *
     * @throws OfflineCodeRefused
     */
    public function apply(string $text, ?DateTimeImmutable $now = null, ?string $actorUserId = null): string
    {
        $now ??= CarbonImmutable::now();

        $lock = Cache::lock('license-renewal', 300);
        if (! $lock->get()) {
            throw new OfflineCodeRefused('A renewal is running. Try the code again in a minute.');
        }

        try {
            return DB::transaction(fn (): string => $this->applyLocked($text, $now, $actorUserId));
        } finally {
            $lock->release();
        }
    }

    private function applyLocked(string $text, DateTimeImmutable $now, ?string $actorUserId): string
    {
        $ring = $this->gate->keyRing();
        $codeKeys = (array) config('tindaflow.license.code_keys');
        if ($ring->isEmpty() || $codeKeys === []) {
            throw new OfflineCodeRefused('This build accepts no offline codes (it carries no code key).');
        }

        try {
            $code = (new OfflineCodeVerifier($codeKeys, (array) config('tindaflow.license.revoked_code_keys')))->verify($text);
        } catch (CertificateRejected $e) {
            throw new OfflineCodeRefused($e->reason === CertificateRejected::MALFORMED
                ? 'The code is incomplete or mistyped. Check it and type it again.'
                : "The code is not valid ({$e->reason}). Ask your TindaFlow provider for a new one.");
        }

        $row = DB::table('license_state')->where('id', 1)->lockForUpdate()->first();
        if ($row === null || $row->certificate === null) {
            throw new OfflineCodeRefused('This installation is not activated.');
        }
        if ($row->revoked) {
            throw new OfflineCodeRefused('This installation has been revoked. No code can unlock it.');
        }

        $certificate = $this->gate->certificate($row->certificate);
        if ($certificate === null) {
            throw new OfflineCodeRefused('The certificate held is not accepted by this installation. Ask your TindaFlow provider.');
        }

        if ($code->deployment !== $certificate->deployment || $code->installationDigest !== OfflineCode::digestOf($certificate->installation)) {
            throw new OfflineCodeRefused('This code was made for a different installation.');
        }
        if ($code->baseSeq !== $certificate->seq) {
            throw new OfflineCodeRefused("This code was made for certificate {$code->baseSeq}, and this installation holds certificate {$certificate->seq}. Ask for a code for {$certificate->seq}.");
        }
        if ($code->ordinal <= (int) $row->last_code_ordinal) {
            throw new OfflineCodeRefused('This code has already been used, or a newer one has been used since.');
        }
        if ($code->iat->getTimestamp() - $now->getTimestamp() > self::CLOCK_SKEW_SECONDS) {
            throw new OfflineCodeRefused("This computer's clock is more than an hour behind the provider's. Correct the date and time, then type the code again.");
        }

        return $code->type === OfflineCode::TIME_RESET
            ? $this->resetTime($code, $certificate, $now, $actorUserId)
            : $this->extend($code, $certificate, $row, $now, $actorUserId);
    }

    private function extend(OfflineCode $code, Certificate $certificate, object $row, DateTimeImmutable $now, ?string $actorUserId): string
    {
        $check = $this->gate->check($now);
        if ($check->verdict->state === LicenseState::Locked) {
            throw new OfflineCodeRefused('This installation is locked, and an extension cannot unlock it. Ask your TindaFlow provider.');
        }
        if ((int) $row->extensions_in_a_row >= self::MAX_EXTENSIONS_IN_A_ROW) {
            throw new OfflineCodeRefused('This installation has used '.self::MAX_EXTENSIONS_IN_A_ROW.' extensions in a row. It has to renew online before another one.');
        }

        $zone = (string) config('app.timezone');
        $until = $this->expiry->expiry($code->iat > $certificate->exp ? $code->iat : $certificate->exp, $zone, $code->days);
        $current = $row->extended_until !== null && (int) $row->extension_base_seq === $certificate->seq ? new DateTimeImmutable($row->extended_until) : $certificate->exp;

        if ($until <= $current) {
            throw new OfflineCodeRefused('This code would not make the license run any longer, so it was not used. It can be applied only after a later one is needed.');
        }
        if ($until <= $check->trustedNow) {
            throw new OfflineCodeRefused('The period this code gives is already over. Ask your TindaFlow provider for a new code.');
        }

        $inARow = (int) $row->extensions_in_a_row + 1;
        DB::table('license_state')->where('id', 1)->update([
            'extended_until' => $this->stamp($until),
            'extension_base_seq' => $certificate->seq,
            'extensions_in_a_row' => $inARow,
            'last_code_ordinal' => $code->ordinal,
            'updated_at' => $this->stamp($now),
        ]);
        AuditTrail::record($certificate->store, 'LICENSE_EXTENDED', $actorUserId, 'store', $certificate->store, null, [
            'ordinal' => $code->ordinal, 'base_seq' => $certificate->seq, 'until' => $this->stamp($until), 'in_a_row' => $inARow,
        ]);

        $local = $until->setTimezone(new DateTimeZone($zone))->format('Y-m-d H:i');

        return "Extended: the license now runs until {$local} (extension {$inARow} of ".self::MAX_EXTENSIONS_IN_A_ROW.' before it must renew online).';
    }

    private function resetTime(OfflineCode $code, Certificate $certificate, DateTimeImmutable $now, ?string $actorUserId): string
    {
        $previous = $this->clock->rebaseline($code->iat, $this->hardware->read($now)->generatedAt);
        DB::table('license_state')->where('id', 1)->update(['last_code_ordinal' => $code->ordinal, 'updated_at' => $this->stamp($now)]);
        AuditTrail::record($certificate->store, 'LICENSE_TIME_RESET', $actorUserId, 'store', $certificate->store, null, [
            'ordinal' => $code->ordinal, 'base_seq' => $certificate->seq,
            'from' => $previous === null ? null : $this->stamp($previous), 'to' => $this->stamp($code->iat),
        ]);

        return 'The recorded time has been reset. The license is now checked against the corrected clock.';
    }

    private function stamp(DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d\TH:i:sP');
    }
}
