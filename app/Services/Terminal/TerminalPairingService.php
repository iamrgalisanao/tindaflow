<?php

namespace App\Services\Terminal;

use App\Domain\Exceptions\PairingRequestInvalidException;
use App\Domain\Exceptions\PairingRequestLimitException;
use App\Domain\Exceptions\TerminalNotFoundException;
use App\Models\Store;
use App\Models\Terminal;
use App\Models\TerminalPairingRequest;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ADR-015: remote till approval, the device-authorization pattern (RFC 8628). A signed-in person at an un-enrolled browser asks to be a
 * till and is shown a short code; an administrator ELSEWHERE approves by typing that code and choosing the terminal; the browser then
 * claims its credential. The administrator is not needed at the till computer, but nothing becomes a till without an administrator's
 * explicit approval of that specific request.
 *
 *   - The code is typed by the administrator, never listed to them, so approving means having read it from the till's own screen
 *     (over the phone, say): an administrator cannot approve a request they have not matched to a person.
 *   - The claim secret never leaves the requesting browser; only its hash is stored, and the claim must also come from the same
 *     signed-in user that asked.
 *   - Five wrong codes decline the request; a request lives 15 minutes (plus a short window to claim once approved); only one request
 *     per person is live at a time; at most 20 wait per store.
 *   - Approving a revoked terminal takes a licensed seat back (ADR-014), so it is checked here; issuing the credential is the same
 *     TerminalEnrollmentService step as the token flow.
 */
final class TerminalPairingService
{
    public const TTL_MINUTES = 15;

    public const CLAIM_MINUTES_AFTER_APPROVAL = 10;

    public const MAX_PENDING_PER_STORE = 20;

    public const MAX_CODE_ATTEMPTS = 5;

    /** No 0/O/1/I: a code read aloud or copied by eye must not be ambiguous. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly TerminalEnrollmentService $enrollment, private readonly TerminalSeats $seats) {}

    /** @return array{request: TerminalPairingRequest, code: string, secret: string} the code as shown ("ABCD-EFGH") and the secret, each returned once */
    public function request(User $requester, ?string $ipAddress, ?string $userAgent): array
    {
        return DB::transaction(function () use ($requester, $ipAddress, $userAgent) {
            // A person has one live request at a time: asking again replaces the earlier one rather than piling up.
            TerminalPairingRequest::where('requested_by', $requester->id)->where('status', 'PENDING')->update(['status' => 'REJECTED']);

            Store::whereKey($requester->store_id)->lockForUpdate()->first();
            if ($this->pending($requester->store_id)->count() >= self::MAX_PENDING_PER_STORE) {
                throw PairingRequestLimitException::make(self::MAX_PENDING_PER_STORE);
            }

            $code = $this->newCode();
            $secret = Str::random(48);

            $request = TerminalPairingRequest::create([
                'store_id' => $requester->store_id,
                'requested_by' => $requester->id,
                'code_hash' => $this->hashCode($code),
                'secret_hash' => hash('sha256', $secret),
                'status' => 'PENDING',
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent === null ? null : Str::limit($userAgent, 250, ''),
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            ]);

            AuditTrail::record($requester->store_id, 'TERMINAL_PAIRING_REQUESTED', $requester->id, 'terminal_pairing_request', $request->id, null, [
                'ip_address' => $ipAddress,
                'expires_at' => $request->expires_at->toIso8601String(),
            ]);

            return ['request' => $request, 'code' => substr($code, 0, 4).'-'.substr($code, 4), 'secret' => $secret];
        });
    }

    /** @return Collection<int, TerminalPairingRequest> the requests still waiting, oldest first; never with their codes */
    public function pending(string $storeId): Collection
    {
        return TerminalPairingRequest::with('requester')
            ->where('store_id', $storeId)
            ->where('status', 'PENDING')
            ->where('expires_at', '>', now())
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @throws PairingRequestInvalidException the request is not waiting (or never was)
     * @throws ValidationException the code does not match (a field error; five of them decline the request)
     */
    public function approve(User $admin, string $requestId, string $code, string $terminalId): TerminalPairingRequest
    {
        $request = TerminalPairingRequest::where('store_id', $admin->store_id)->find($requestId);
        if ($request === null || $request->status !== 'PENDING' || $request->expires_at->isPast()) {
            throw PairingRequestInvalidException::make();
        }

        if (! hash_equals($request->code_hash, $this->hashCode($code))) {
            // Outside any transaction, so the count survives the refusal; an atomic increment, so simultaneous guesses cannot
            // slip past the limit by all reading the same old count. The refusal is recorded (who tried, which request): a run of
            // wrong codes from an administrator account is exactly what a stolen administrator sign-in looks like.
            TerminalPairingRequest::whereKey($request->id)->increment('code_attempts');
            $attempts = (int) TerminalPairingRequest::whereKey($request->id)->value('code_attempts');
            $declined = $attempts >= self::MAX_CODE_ATTEMPTS;
            if ($declined) {
                TerminalPairingRequest::whereKey($request->id)->where('status', 'PENDING')->update(['status' => 'REJECTED']);
            }
            AuditTrail::record($admin->store_id, 'TERMINAL_PAIRING_CODE_REFUSED', $admin->id, 'terminal_pairing_request', $request->id, null, [
                'attempts' => $attempts,
                'declined' => $declined,
                'requested_by' => $request->requested_by,
            ]);

            throw ValidationException::withMessages(['code' => $declined
                ? 'Too many wrong codes. This request was declined; ask for a new one.'
                : 'That code does not match this request. Read it again from the till computer\'s screen.']);
        }

        return DB::transaction(function () use ($admin, $request, $terminalId) {
            $locked = TerminalPairingRequest::lockForUpdate()->find($request->id);
            if ($locked === null || $locked->status !== 'PENDING' || $locked->expires_at->isPast()) {
                throw PairingRequestInvalidException::make();
            }

            $terminal = Terminal::where('store_id', $admin->store_id)->find($terminalId) ?? throw TerminalNotFoundException::forId($terminalId);

            if ($terminal->revoked_at !== null) {
                Store::whereKey($admin->store_id)->lockForUpdate()->first();
                $this->seats->assertRoomForOneMore($terminal->id);
            }

            $locked->update([
                'status' => 'APPROVED',
                'terminal_id' => $terminal->id,
                'approved_by' => $admin->id,
                'approved_at' => now(),
                // Time to carry on once approved, even if the request was nearly out of time.
                'expires_at' => max($locked->expires_at, now()->addMinutes(self::CLAIM_MINUTES_AFTER_APPROVAL)),
            ]);

            AuditTrail::record($admin->store_id, 'TERMINAL_PAIRING_APPROVED', $admin->id, 'terminal_pairing_request', $locked->id, null, [
                'terminal_code' => $terminal->terminal_code,
                'requested_by' => $locked->requested_by,
                'ip_address' => $locked->ip_address,
            ], $terminal->id);

            return $locked->refresh();
        });
    }

    public function reject(User $admin, string $requestId): TerminalPairingRequest
    {
        return DB::transaction(function () use ($admin, $requestId) {
            $request = TerminalPairingRequest::where('store_id', $admin->store_id)->lockForUpdate()->find($requestId);
            if ($request === null || $request->status !== 'PENDING' || $request->expires_at->isPast()) {
                throw PairingRequestInvalidException::make();
            }

            $request->update(['status' => 'REJECTED']);

            AuditTrail::record($admin->store_id, 'TERMINAL_PAIRING_DECLINED', $admin->id, 'terminal_pairing_request', $request->id, null, [
                'requested_by' => $request->requested_by,
                'ip_address' => $request->ip_address,
            ]);

            return $request->refresh();
        });
    }

    /**
     * The requesting browser asks "am I approved yet?". Null while the request is still waiting; once approved, issues the terminal
     * credential (once) and returns it.
     *
     * @return array{terminal: Terminal, credential: string}|null
     *
     * @throws PairingRequestInvalidException
     */
    public function claim(User $requester, string $requestId, string $secret): ?array
    {
        $request = TerminalPairingRequest::where('requested_by', $requester->id)->find($requestId);

        if ($request === null
            || ! hash_equals($request->secret_hash, hash('sha256', $secret))
            || ! in_array($request->status, ['PENDING', 'APPROVED'], true)
            || $request->expires_at->isPast()
        ) {
            throw PairingRequestInvalidException::make();
        }

        if ($request->status === 'PENDING') {
            return null;
        }

        return DB::transaction(function () use ($request, $requester) {
            $locked = TerminalPairingRequest::lockForUpdate()->find($request->id);
            if ($locked === null || $locked->status !== 'APPROVED') {
                throw PairingRequestInvalidException::make();
            }

            $terminal = Terminal::where('store_id', $requester->store_id)->find($locked->terminal_id) ?? throw PairingRequestInvalidException::make();

            $result = $this->enrollment->issueCredential($terminal->id, $locked->approved_by);
            $locked->update(['status' => 'CONSUMED', 'consumed_at' => now()]);

            return $result;
        });
    }

    private function newCode(): string
    {
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    /** What the administrator types ("abcd-efgh", with or without the dash) and what was shown are the same code. */
    private function hashCode(string $code): string
    {
        return hash('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code)));
    }
}
