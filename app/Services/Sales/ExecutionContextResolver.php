<?php

namespace App\Services\Sales;

use App\Domain\Exceptions\FiscalDayClosedException;
use App\Domain\Exceptions\ShiftNotOpenException;
use App\Support\GlobalLockOrder;
use App\Support\LockableResource;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * invariants.md #67-#69: a void or refund is attributed to the *executing* terminal's own open
 * fiscal day and the executing user's own open shift at that terminal -- never the original sale's,
 * never the context that existed when the request was made. The shift is locked first, then the
 * fiscal day(s), and both are re-checked under the lock, so a Z-Reading or shift close that commits
 * first simply makes the row stop matching.
 */
final class ExecutionContextResolver
{
    /**
     * @param  string|null  $alsoLockFiscalDayId  a void must also see the *original* sale's fiscal day
     *                                            (eligibility condition 2). When it is another row than the executing
     *                                            one, both are locked in ascending id order so two voids executed from
     *                                            different terminals against each other's days cannot deadlock.
     * @return array{shift: stdClass, fiscalDay: stdClass, other: stdClass|null}
     */
    public function lockFor(GlobalLockOrder $lockOrder, string $terminalId, string $userId, ?string $alsoLockFiscalDayId = null): array
    {
        $lockOrder->acquire(LockableResource::Shift);
        $shift = DB::table('shifts')
            ->where('terminal_id', $terminalId)
            ->where('cashier_id', $userId)
            ->where('status', 'OPEN')
            ->lockForUpdate()
            ->first();
        if ($shift === null) {
            throw ShiftNotOpenException::forExecutingUser($terminalId, $userId);
        }

        // The executing day is found first without a lock so both rows can then be locked together in id order.
        $candidate = DB::table('fiscal_days')->where('terminal_id', $terminalId)->where('status', 'OPEN')->first();
        if ($candidate === null) {
            throw FiscalDayClosedException::noOpenDayAtTerminal($terminalId);
        }

        $ids = array_values(array_unique(array_filter([$candidate->id, $alsoLockFiscalDayId])));
        sort($ids);
        $lockOrder->acquire(LockableResource::FiscalDay);
        $rows = DB::table('fiscal_days')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        // Locked reads see the latest committed row, so a day closed in the meantime shows up here.
        $fiscalDay = $rows[$candidate->id];
        if ($fiscalDay->status !== 'OPEN') {
            throw FiscalDayClosedException::noOpenDayAtTerminal($terminalId);
        }

        return [
            'shift' => $shift,
            'fiscalDay' => $fiscalDay,
            'other' => $alsoLockFiscalDayId !== null ? $rows[$alsoLockFiscalDayId] ?? null : null,
        ];
    }
}
