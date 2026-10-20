<?php

namespace App\Services\Terminal;

use App\Domain\Exceptions\EnrollmentTokenInvalidException;
use App\Models\Store;
use App\Models\Terminal;
use App\Models\TerminalEnrollmentToken;
use App\Services\Audit\AuditTrail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ADR-011 steps 2-3: verifies a one-time enrollment token and, on
 * success, issues a fresh terminal credential.
 *
 * Two deliberately SEPARATE transactions, not one wrapping both steps --
 * ADR-011's own wording: single-use semantics are "invalidated
 * immediately on first successful use, regardless of outcome." Token
 * consumption (phase 1) is committed on its own before credential
 * issuance (phase 2) is even attempted, so a failure in phase 2 can
 * never roll back and un-consume the token -- a fresh token is always
 * required for a retry, exactly as the ADR states, never a byproduct of
 * incidental transaction boundaries. consumeToken() and issueCredential()
 * are public specifically so a test can exercise each phase's boundary
 * independently (see TerminalEnrollmentTest's failure-boundary test).
 *
 * Phase 1's SELECT ... FOR UPDATE + used_at check-then-set is what makes
 * concurrent use of the same token race-safe: two simultaneous callers
 * serialize on the token row's lock, and only the first to commit sees
 * used_at still null.
 *
 * A3 closeout (Store scoping): the token's own store_id must match the
 * authenticated actor's store_id, checked inside the SAME locked
 * transaction as used_at/expiry -- a cross-store attempt is rejected as
 * ENROLLMENT_TOKEN_INVALID (the same code as unknown/expired/used,
 * terminalEnroll's only declared conflict outcome; a cross-store token
 * is not a real failure mode the frozen contract distinguishes, and the
 * project-wide non-enumeration convention -- e.g. AUTHENTICATION_REQUIRED
 * never distinguishing unknown-email from wrong-password -- applies
 * equally here) and, critically, is NOT marked used: the check runs
 * before the update, so a mismatched actor can never burn the token for
 * its legitimate (correct-store) user.
 */
final class TerminalEnrollmentService
{
    /** @return array{terminal: Terminal, credential: string} */
    public function enroll(string $plaintextToken, string $actorStoreId, ?string $actorUserId = null): array
    {
        $terminalId = $this->consumeToken($plaintextToken, $actorStoreId);

        return $this->issueCredential($terminalId, $actorUserId);
    }

    public function consumeToken(string $plaintextToken, string $actorStoreId): string
    {
        $tokenHash = hash('sha256', $plaintextToken);

        return DB::transaction(function () use ($tokenHash, $actorStoreId): string {
            $token = TerminalEnrollmentToken::where('token_hash', $tokenHash)->lockForUpdate()->first();

            if ($token === null
                || $token->used_at !== null
                || $token->expires_at->isPast()
                || $token->store_id !== $actorStoreId
            ) {
                throw EnrollmentTokenInvalidException::make();
            }

            $token->update(['used_at' => now()]);

            return $token->terminal_id;
        });
    }

    /** @return array{terminal: Terminal, credential: string} */
    public function issueCredential(string $terminalId, ?string $actorUserId = null): array
    {
        return DB::transaction(function () use ($terminalId, $actorUserId): array {
            $terminal = Terminal::lockForUpdate()->findOrFail($terminalId);

            // ADR-014: re-enrolling a revoked terminal takes a licensed seat back (backstop to the check made
            // when the token was issued -- a license can change in between).
            if ($terminal->revoked_at !== null) {
                Store::whereKey($terminal->store_id)->lockForUpdate()->first();
                app(TerminalSeats::class)->assertRoomForOneMore($terminal->id);
            }

            $plaintextCredential = Str::random(64);

            $wasRevoked = $terminal->revoked_at !== null;

            $terminal->update([
                'credential_hash' => hash('sha256', $plaintextCredential),
                'credential_issued_at' => now(),
                // Set once, on first enrollment only -- distinct from
                // credential_issued_at, which updates on every
                // (re-)enrollment. This is an A3 implementation choice
                // (TerminalFactory::unenrolled()'s own convention of
                // bundling it with credential_hash), not a frozen rule --
                // no ADR/Decision Register text mandates it, and it does
                // not contradict any of them either.
                'activated_at' => $terminal->activated_at ?? now(),
                // ADR-011: "a fresh enrollment... is required to re-bind,
                // either to the same terminal record or a new one" --
                // re-enrolling a previously-revoked terminal must clear
                // the old revocation, or re-binding to the same record
                // would be permanently impossible.
                'revoked_at' => null,
            ]);

            // Which till a browser became, and by whom; never the credential or its hash.
            AuditTrail::record($terminal->store_id, 'TERMINAL_ENROLLED', $actorUserId, 'terminal', $terminal->id, null, [
                'terminal_code' => $terminal->terminal_code,
                're_enrolled_after_revocation' => $wasRevoked,
            ], $terminal->id);

            return ['terminal' => $terminal->refresh(), 'credential' => $plaintextCredential];
        });
    }
}
