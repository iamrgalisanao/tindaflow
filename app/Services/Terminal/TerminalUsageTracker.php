<?php

namespace App\Services\Terminal;

use App\Models\Terminal;
use App\Services\Audit\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Notices when one till credential is being used from two different computers in turn (ADR-011's accepted risk: the HttpOnly cookie can
 * be read in a browser's developer tools and copied). It records where and when a till was last used, and flags the till when the
 * calls ALTERNATE between two clients -- A, then B, then A again within half an hour -- which is what two machines sharing one credential
 * look like. A single change (the till's address moved, the browser updated) is not alternation and raises nothing.
 *
 * A client is its network address plus browser identification, so two machines on the same shop network running the same browser
 * version look identical and are NOT caught: this is a tripwire with known blind spots, not a lock (the device-bound credential is the
 * real fix). It never blocks anything, never throws, and never delays a sale: a failure here is reported and swallowed.
 */
final class TerminalUsageTracker
{
    private const WRITE_EVERY_SECONDS = 60;

    private const ALTERNATION_WINDOW_MINUTES = 30;

    private const FLAG_AGAIN_AFTER_MINUTES = 60;

    public function record(Terminal $terminal, Request $request): void
    {
        try {
            $this->track($terminal, $request);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function track(Terminal $terminal, Request $request): void
    {
        $ip = (string) $request->ip();
        $agent = (string) $request->userAgent();
        $signature = hash('sha256', $ip.'|'.$agent);
        $now = now();

        if ($signature === $terminal->last_seen_signature) {
            // The usual case: the same computer again. Write at most once a minute, not on every request of a busy till.
            if ($terminal->last_seen_at === null || $terminal->last_seen_at->diffInSeconds($now, true) >= self::WRITE_EVERY_SECONDS) {
                $terminal->forceFill(['last_seen_at' => $now])->saveQuietly();
            }

            return;
        }

        $alternating = $terminal->previous_signature !== null
            && $signature === $terminal->previous_signature
            && $terminal->last_seen_at !== null
            && $terminal->last_seen_at->diffInMinutes($now, true) <= self::ALTERNATION_WINDOW_MINUTES;

        $changes = [
            'previous_signature' => $terminal->last_seen_signature,
            'last_seen_signature' => $signature,
            'last_seen_at' => $now,
            'last_seen_ip' => $ip === '' ? null : $ip,
            'last_seen_user_agent' => $agent === '' ? null : mb_substr($agent, 0, 250),
        ];

        $flag = $alternating && ($terminal->shared_use_suspected_at === null
            || $terminal->shared_use_suspected_at->diffInMinutes($now, true) >= self::FLAG_AGAIN_AFTER_MINUTES);
        if ($flag) {
            $changes['shared_use_suspected_at'] = $now;
        }

        $previousIp = $terminal->last_seen_ip;
        $terminal->forceFill($changes)->saveQuietly();

        if ($flag) {
            AuditTrail::record($terminal->store_id, 'TERMINAL_SHARED_USE_SUSPECTED', Auth::guard('web')->id(), 'terminal', $terminal->id, null, [
                'terminal_code' => $terminal->terminal_code,
                'ip_address' => $ip === '' ? null : $ip,
                'other_ip_address' => $previousIp,
            ], $terminal->id);
        }
    }
}
