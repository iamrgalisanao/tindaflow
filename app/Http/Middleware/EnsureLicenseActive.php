<?php

namespace App\Http\Middleware;

use App\Domain\Exceptions\LicenseNotActiveException;
use App\Models\Shift;
use App\Services\Idempotency\IdempotencyOperationType;
use App\Services\License\LicenseCheck;
use App\Services\License\LicenseGate;
use App\Services\License\RefusalReason;
use App\Services\License\Time\TrustedClock;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * ADR-018 decision 2. Attached in routes/web.php only; the checkout code is untouched.
 *
 * - `open`    gates shiftOpen: refused unless the license is ACTIVE or WARNING, and moves the trusted-time mark on success.
 * - `sale`    gates saleFinalize: an open shift is never interrupted, except past the 18-hour cap once the license is EXPIRED,
 *             LOCKED or NOT_ACTIVATED.
 * - `observe` gates nothing (shiftClose is never gated) but moves the trusted-time mark on success.
 *
 * A build with no vendor key (LEGACY) passes straight through without touching a table. A certificate in `report` mode logs
 * what it WOULD have refused and refuses nothing. A replay of an already completed request (same terminal, same
 * Idempotency-Key) is never refused: it returns the original result even after the license has lapsed.
 */
class EnsureLicenseActive
{
    public function __construct(private readonly LicenseGate $gate, private readonly TrustedClock $clock) {}

    public function handle(Request $request, Closure $next, string $gate): Response
    {
        if ($this->gate->keyRing()->isEmpty()) {
            return $next($request);
        }

        if ($gate !== 'observe' && ! $this->isCompletedReplay($request, $gate)) {
            $this->refuseIfNotAllowed($request, $gate);
        }

        $response = $next($request);

        if (in_array($gate, ['open', 'observe'], true) && $response->getStatusCode() < 300) {
            $this->recordMoment();
        }

        return $response;
    }

    private function refuseIfNotAllowed(Request $request, string $gate): void
    {
        $check = $this->gate->check();
        $verdict = $check->verdict;

        $refusal = match ($gate) {
            'open' => $verdict->shiftOpenRefusal(),
            'sale' => $this->saleRefusal($request, $check),
            default => null,
        };

        if ($refusal === null) {
            return;
        }

        if (! $verdict->enforcing) {
            Log::warning('license.would_refuse', ['gate' => $gate, 'reason' => $refusal->value, 'state' => $verdict->state->value, 'terminal_id' => $request->attributes->get('terminal')?->id]);

            return;
        }

        throw LicenseNotActiveException::make($refusal, $verdict->state);
    }

    private function saleRefusal(Request $request, LicenseCheck $check): ?RefusalReason
    {
        $terminal = $request->attributes->get('terminal');
        $openedAt = $terminal === null ? null : Shift::where('terminal_id', $terminal->id)->where('status', 'OPEN')->value('opened_at');

        return $openedAt === null ? null : $check->verdict->saleRefusal(new DateTimeImmutable((string) $openedAt), $check->trustedNow);
    }

    private function isCompletedReplay(Request $request, string $gate): bool
    {
        $key = $request->header('Idempotency-Key');
        $terminal = $request->attributes->get('terminal');
        if ($terminal === null || ! is_string($key) || ! Str::isUuid($key)) {
            return false;
        }

        $type = $gate === 'open' ? IdempotencyOperationType::ShiftOpen : IdempotencyOperationType::Checkout;

        return DB::table('idempotency_records')
            ->where('terminal_id', $terminal->id)
            ->where('idempotency_key', $key)
            ->where('operation_type', $type->value)
            ->where('status', 'COMPLETED')
            ->exists();
    }

    /** After the response is decided and outside any transaction of ours: a failure here must never turn a committed shift into a 500. */
    private function recordMoment(): void
    {
        try {
            $this->clock->advanceTo(CarbonImmutable::now());
        } catch (Throwable $e) {
            Log::error('license.trusted_time_advance_failed', ['message' => $e->getMessage()]);
        }
    }
}
