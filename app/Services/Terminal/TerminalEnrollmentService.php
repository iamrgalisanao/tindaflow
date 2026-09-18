<?php

namespace App\Services\Terminal;

use App\Domain\Exceptions\EnrollmentTokenInvalidException;
use App\Models\Terminal;
use App\Models\TerminalEnrollmentToken;
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
 * incidental transaction boundaries.
 *
 * Phase 1's SELECT ... FOR UPDATE + used_at check-then-set is what makes
 * concurrent use of the same token race-safe: two simultaneous callers
 * serialize on the token row's lock, and only the first to commit sees
 * used_at still null.
 */
final class TerminalEnrollmentService
{
    /** @return array{terminal: Terminal, credential: string} */
    public function enroll(string $plaintextToken): array
    {
        $terminalId = $this->consumeToken($plaintextToken);

        return $this->issueCredential($terminalId);
    }

    private function consumeToken(string $plaintextToken): string
    {
        $tokenHash = hash('sha256', $plaintextToken);

        return DB::transaction(function () use ($tokenHash): string {
            $token = TerminalEnrollmentToken::where('token_hash', $tokenHash)->lockForUpdate()->first();

            if ($token === null || $token->used_at !== null || $token->expires_at->isPast()) {
                throw EnrollmentTokenInvalidException::make();
            }

            $token->update(['used_at' => now()]);

            return $token->terminal_id;
        });
    }

    /** @return array{terminal: Terminal, credential: string} */
    private function issueCredential(string $terminalId): array
    {
        return DB::transaction(function () use ($terminalId): array {
            $terminal = Terminal::lockForUpdate()->findOrFail($terminalId);

            $plaintextCredential = Str::random(64);

            $terminal->update([
                'credential_hash' => hash('sha256', $plaintextCredential),
                'credential_issued_at' => now(),
                // Set once, on first enrollment only -- distinct from
                // credential_issued_at, which updates on every
                // (re-)enrollment. See TerminalFactory::unenrolled(),
                // which nulls both together, and the A3 report for the
                // full reasoning.
                'activated_at' => $terminal->activated_at ?? now(),
                // ADR-011: "a fresh enrollment... is required to re-bind,
                // either to the same terminal record or a new one" --
                // re-enrolling a previously-revoked terminal must clear
                // the old revocation, or re-binding to the same record
                // would be permanently impossible.
                'revoked_at' => null,
            ]);

            return ['terminal' => $terminal->refresh(), 'credential' => $plaintextCredential];
        });
    }
}
