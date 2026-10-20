<?php

namespace App\Services\Terminal;

use App\Models\Terminal;
use App\Models\TerminalEnrollmentToken;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ADR-011 step 1: an administrator generates a one-time enrollment token
 * for a terminal -- high-entropy, short validity window (the ADR's own
 * example: 15 minutes), single-use. Only the deterministic hash is ever
 * persisted; the plaintext is returned exactly once by the caller and
 * never logged or stored anywhere.
 */
final class TerminalEnrollmentTokenService
{
    private const TTL_MINUTES = 15;

    /** @return array{token: string, model: TerminalEnrollmentToken} */
    public function issue(Terminal $terminal, User $actor): array
    {
        $plaintext = Str::random(64);

        $model = DB::transaction(function () use ($terminal, $actor, $plaintext) {
            $model = TerminalEnrollmentToken::create([
                'store_id' => $terminal->store_id,
                'terminal_id' => $terminal->id,
                'token_hash' => hash('sha256', $plaintext),
                'created_by' => $actor->id,
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ]);

            // Never the token, nor its hash: who asked to enroll which till, and until when the offer stands.
            AuditTrail::record($terminal->store_id, 'TERMINAL_ENROLLMENT_TOKEN_ISSUED', $actor->id, 'terminal', $terminal->id, null, [
                'terminal_code' => $terminal->terminal_code,
                'expires_at' => $model->expires_at->toIso8601String(),
            ], $terminal->id);

            return $model;
        });

        return ['token' => $plaintext, 'model' => $model];
    }
}
