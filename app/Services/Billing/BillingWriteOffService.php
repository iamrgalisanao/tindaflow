<?php

namespace App\Services\Billing;

use App\Domain\CutOff;
use App\Domain\Exceptions\WriteOffAlreadyReversedException;
use App\Domain\Exceptions\WriteOffChequeInvalidException;
use App\Domain\Exceptions\WriteOffExceedsOwedException;
use App\Domain\Exceptions\WriteOffNotFoundException;
use App\Domain\Exceptions\WriteOffNotReversibleException;
use App\Domain\Exceptions\WriteOffRequestReusedException;
use App\Models\BillingCheque;
use App\Models\BillingParty;
use App\Models\BillingWriteOff;
use App\Models\User;
use App\Services\Employees\BillingPartyResolver;
use App\Support\GlobalLockOrder;
use App\Support\LockableResource;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * ADR-016 section 8 / slice 4.3, BILL-008 and BILL-009: writing off an unpaid amount of one cut-off period as bad debt, and cancelling a
 * mistaken write-off. A write-off is NOT money received: it takes no cash, makes no payment, and touches no sale, shift, drawer or reading;
 * it only lowers the derived "owed" of its period (BillingBalanceService).
 *
 * Writing off: inside ONE transaction the billing party's row is locked FOR UPDATE, the SAME lock a cheque takes, so a cheque and a
 * write-off racing for the same owed money queue and the second sees the first. The amount is checked to be at most what the period owes
 * right now (billed - received - net written off), then the row and its audit event are written.
 *
 * Reversing: the same party lock, then a REVERSAL row carrying exactly the original's period and amount is appended (a composite foreign
 * key and a unique index back that up). A write-off is reversed at most once and a reversal is never reversed; to write the amount off
 * again, make a new write-off. Nothing is ever updated or deleted.
 *
 * Idempotency: a client-generated `request_id` (the screen sends one per form) makes a retried POST return the write-off it already
 * created instead of writing a second one. Reversal needs none: the unique reversal per write-off refuses a repeat with a clear 409.
 */
final class BillingWriteOffService
{
    public function __construct(
        private readonly BillingPartyResolver $parties,
        private readonly BillingBalanceService $balance,
        private readonly BillingWriteOffAuditor $auditor,
    ) {}

    /**
     * @param  array{period_start: string, amount: string, reason: string, cheque_id?: ?string, request_id?: ?string}  $input
     * @return array{0: BillingWriteOff, 1: bool} the row, and true when it is the answer to a retried request_id (nothing new was written)
     *
     * @throws WriteOffExceedsOwedException
     * @throws WriteOffChequeInvalidException
     * @throws WriteOffRequestReusedException
     */
    public function record(User $actor, array $input): array
    {
        $party = $this->parties->forStore($actor->store_id);
        $timezone = (string) config('app.timezone');
        $period = CutOff::startingOn($input['period_start'], $timezone);
        $reason = trim($input['reason']);
        $chequeId = $input['cheque_id'] ?? null;
        $requestId = $input['request_id'] ?? null;

        return DB::transaction(function () use ($actor, $party, $input, $period, $reason, $chequeId, $requestId): array {
            $this->lockParty(new GlobalLockOrder, $actor->store_id, $party->id);

            if ($requestId !== null) {
                $existing = BillingWriteOff::where('store_id', $actor->store_id)->where('request_id', $requestId)->first();
                if ($existing !== null) {
                    $same = $existing->kind === BillingWriteOff::WRITE_OFF
                        && $existing->period_start->toDateString() === $period->startDate()
                        && bccomp((string) $existing->amount, $input['amount'], 2) === 0
                        && $existing->reason === $reason
                        && $existing->cheque_id === $chequeId;
                    if (! $same) {
                        throw WriteOffRequestReusedException::forRequest($requestId, $existing->id);
                    }

                    return [$existing, true];
                }
            }

            $owed = $this->balance->owedFor($actor->store_id, $party->id, [$period->startDate()], CarbonImmutable::now())[$period->startDate()];
            if (bccomp($input['amount'], '0', 2) <= 0 || bccomp($input['amount'], $owed, 2) > 0) {
                throw WriteOffExceedsOwedException::forPeriod($period->startDate(), $period->endDate(), $owed, $input['amount']);
            }

            $cheque = $chequeId === null ? null : $this->linkedCheque($actor->store_id, $chequeId, $period->startDate());

            $row = BillingWriteOff::create([
                'store_id' => $actor->store_id,
                'billing_party_id' => $party->id,
                'kind' => BillingWriteOff::WRITE_OFF,
                'period_start' => $period->startDate(),
                'period_end' => $period->endDate(),
                'amount' => $input['amount'],
                'reason' => $reason,
                'cheque_id' => $cheque?->id,
                'request_id' => $requestId,
                'written_by' => $actor->id,
                'written_at' => CarbonImmutable::now(),
            ]);
            $this->auditor->recorded($actor, $row, $cheque, $owed, bcsub($owed, $input['amount'], 2));

            return [$row, false];
        });
    }

    /**
     * @throws WriteOffNotFoundException
     * @throws WriteOffNotReversibleException
     * @throws WriteOffAlreadyReversedException
     */
    public function reverse(User $actor, string $writeOffId, string $reason): BillingWriteOff
    {
        $party = $this->parties->forStore($actor->store_id);

        return DB::transaction(function () use ($actor, $writeOffId, $reason, $party): BillingWriteOff {
            $this->lockParty(new GlobalLockOrder, $actor->store_id, $party->id);

            $original = BillingWriteOff::where('store_id', $actor->store_id)->whereKey($writeOffId)->first()
                ?? throw WriteOffNotFoundException::forId($writeOffId);
            if ($original->kind === BillingWriteOff::REVERSAL) {
                throw WriteOffNotReversibleException::forReversal($original->id);
            }
            $already = BillingWriteOff::where('reverses_write_off_id', $original->id)->first();
            if ($already !== null) {
                throw WriteOffAlreadyReversedException::forWriteOff($original->id, $already->id);
            }

            $start = $original->period_start->toDateString();
            $owedBefore = $this->balance->owedFor($actor->store_id, $party->id, [$start], CarbonImmutable::now())[$start];
            $row = BillingWriteOff::create([
                'store_id' => $actor->store_id,
                'billing_party_id' => $party->id,
                'kind' => BillingWriteOff::REVERSAL,
                'period_start' => $start,
                'period_end' => $original->period_end->toDateString(),
                'amount' => (string) $original->amount,
                'reason' => trim($reason),
                'reverses_write_off_id' => $original->id,
                'written_by' => $actor->id,
                'written_at' => CarbonImmutable::now(),
            ]);
            $this->auditor->reversed($actor, $row, $original, $original->cheque, $owedBefore, bcadd($owedBefore, (string) $original->amount, 2));

            return $row;
        });
    }

    /** @throws WriteOffNotFoundException another store's row is indistinguishable from a missing one */
    public function find(string $storeId, string $writeOffId): BillingWriteOff
    {
        return BillingWriteOff::where('store_id', $storeId)->with(['writer:id,name', 'cheque:id,cheque_number,bank'])->find($writeOffId)
            ?? throw WriteOffNotFoundException::forId($writeOffId);
    }

    public function list(string $storeId, int $perPage, int $page): LengthAwarePaginator
    {
        return BillingWriteOff::where('store_id', $storeId)
            ->with(['writer:id,name', 'cheque:id,cheque_number,bank'])
            ->orderByDesc('written_at')->orderByDesc('id')
            ->paginate(perPage: $perPage, page: $page);
    }

    /**
     * For a page of rows: write-off id => its REVERSAL row (with the writer), so the list can show who cancelled what and why.
     *
     * @param  list<string>  $writeOffIds
     * @return array<string, BillingWriteOff>
     */
    public function reversalsOf(string $storeId, array $writeOffIds): array
    {
        return BillingWriteOff::where('store_id', $storeId)->whereIn('reverses_write_off_id', $writeOffIds)->with('writer:id,name')->get()
            ->keyBy('reverses_write_off_id')->all();
    }

    /** The cheque must be this store's, RETURNED, and have paid the period (the bounced cheque is what put the amount back to owed). */
    private function linkedCheque(string $storeId, string $chequeId, string $periodStart): BillingCheque
    {
        $cheque = BillingCheque::where('store_id', $storeId)->with('allocations')->find($chequeId)
            ?? throw WriteOffChequeInvalidException::notFound($chequeId);
        if ($cheque->status !== BillingCheque::RETURNED) {
            throw WriteOffChequeInvalidException::notReturned($cheque->id, $cheque->cheque_number);
        }
        if (! $cheque->allocations->contains(fn ($allocation) => $allocation->period_start->toDateString() === $periodStart)) {
            throw WriteOffChequeInvalidException::didNotPayPeriod($cheque->id, $cheque->cheque_number, $periodStart);
        }

        return $cheque;
    }

    /** The same lock a cheque takes, so a write-off and a cheque for the same owed money never overlap (BILL-008). */
    private function lockParty(GlobalLockOrder $order, string $storeId, string $partyId): void
    {
        $order->acquire(LockableResource::BillingParty);
        BillingParty::where('store_id', $storeId)->whereKey($partyId)->lockForUpdate()->firstOrFail();
    }
}
