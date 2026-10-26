<?php

namespace App\Services\Billing;

use App\Domain\CutOff;
use App\Domain\Exceptions\ChequeAllocationExceedsOwedException;
use App\Domain\Exceptions\ChequeAlreadyReturnedException;
use App\Domain\Exceptions\ChequeDuplicateException;
use App\Domain\Exceptions\ChequeNotFoundException;
use App\Models\BillingCheque;
use App\Models\BillingParty;
use App\Models\User;
use App\Services\Employees\BillingPartyResolver;
use App\Support\GlobalLockOrder;
use App\Support\LockableResource;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ADR-016 section 8 / slice 4.2: recording a cheque against the cut-off periods it pays, and marking it returned.
 *
 * Recording (BILL-002): inside ONE transaction the billing party's row is locked FOR UPDATE (so two cheques queue instead of both
 * seeing the same owed figure), every allocation is checked to be no more than what its period currently owes (BillingBalanceService),
 * then the cheque, its allocations and the audit event are written. The allocations were already checked by the request to add up to
 * the cheque amount, so a cheque is always fully allocated. A cheque touches no sale, shift, cash drawer or reading; it is not a payment.
 *
 * Returning (BILL-007): the party row and then the cheque row are locked, RECEIVED becomes RETURNED once (a second attempt is
 * CHEQUE_ALREADY_RETURNED), and the owed amounts reappear by derivation: nothing else is written.
 *
 * No Idempotency-Key (the back office has no terminal; see the ADR-016 amendment of slice 4.2): the unique RECEIVED bank + number and the
 * one-way status give the same protection against a double submit, and a retry gets a clear 409 rather than a second cheque.
 */
final class BillingChequeService
{
    public function __construct(
        private readonly BillingPartyResolver $parties,
        private readonly BillingBalanceService $balance,
        private readonly BillingChequeAuditor $auditor,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated BillingChequeRequest input (allocations already sum to the amount)
     *
     * @throws ChequeDuplicateException
     * @throws ChequeAllocationExceedsOwedException
     */
    public function record(User $actor, array $input): BillingCheque
    {
        $party = $this->parties->forStore($actor->store_id);
        $number = self::normalizeNumber($input['cheque_number']);
        $bank = self::normalizeBank($input['bank']);
        $timezone = (string) config('app.timezone');

        try {
            return DB::transaction(function () use ($actor, $party, $input, $number, $bank, $timezone): BillingCheque {
                $this->lockParty(new GlobalLockOrder, $actor->store_id, $party->id);

                $existing = BillingCheque::where('store_id', $actor->store_id)->where('status', BillingCheque::RECEIVED)
                    ->whereRaw('lower(bank) = ?', [mb_strtolower($bank)])->where('cheque_number', $number)->first();
                if ($existing !== null) {
                    throw ChequeDuplicateException::forExisting($existing->id, $number, $existing->bank, $existing->received_on->toDateString(), (string) $existing->amount);
                }

                $starts = array_column($input['allocations'], 'period_start');
                $owed = $this->balance->owedFor($actor->store_id, $party->id, $starts, CarbonImmutable::now());
                foreach ($input['allocations'] as $allocation) {
                    $period = CutOff::startingOn($allocation['period_start'], $timezone);
                    if (bccomp($allocation['amount'], $owed[$allocation['period_start']], 2) > 0) {
                        throw ChequeAllocationExceedsOwedException::forPeriod($period->startDate(), $period->endDate(), $owed[$allocation['period_start']], $allocation['amount']);
                    }
                }

                $cheque = BillingCheque::create([
                    'store_id' => $actor->store_id,
                    'billing_party_id' => $party->id,
                    'cheque_number' => $number,
                    'bank' => $bank,
                    'cheque_date' => $input['cheque_date'],
                    'amount' => $input['amount'],
                    'received_on' => $input['received_on'],
                    'received_by' => $actor->id,
                    'status' => BillingCheque::RECEIVED,
                    'notes' => isset($input['notes']) && trim((string) $input['notes']) !== '' ? trim((string) $input['notes']) : null,
                    'created_at' => CarbonImmutable::now(),
                ]);
                foreach ($input['allocations'] as $allocation) {
                    $period = CutOff::startingOn($allocation['period_start'], $timezone);
                    $cheque->allocations()->create([
                        'period_start' => $period->startDate(),
                        'period_end' => $period->endDate(),
                        'amount' => $allocation['amount'],
                        'created_at' => CarbonImmutable::now(),
                    ]);
                }
                $cheque->load('allocations');
                $this->auditor->recorded($actor, $cheque);

                return $cheque;
            });
        } catch (UniqueConstraintViolationException) {
            // The pre-check runs under the party lock, so this is only the database's own backstop for the same rule.
            throw ChequeDuplicateException::forExisting('', $number, $bank, $input['received_on'], $input['amount']);
        }
    }

    /**
     * @param  array{returned_on: string, reason: string}  $input
     *
     * @throws ChequeNotFoundException
     * @throws ChequeAlreadyReturnedException
     */
    public function markReturned(User $actor, string $chequeId, array $input): BillingCheque
    {
        $party = $this->parties->forStore($actor->store_id);

        return DB::transaction(function () use ($actor, $chequeId, $input, $party): BillingCheque {
            $order = new GlobalLockOrder;
            $this->lockParty($order, $actor->store_id, $party->id);
            $order->acquire(LockableResource::BillingCheque);
            $cheque = BillingCheque::where('store_id', $actor->store_id)->whereKey($chequeId)->lockForUpdate()->first()
                ?? throw ChequeNotFoundException::forId($chequeId);

            if ($cheque->status === BillingCheque::RETURNED) {
                throw ChequeAlreadyReturnedException::forCheque($cheque->id, $cheque->cheque_number, $cheque->returned_on?->toDateString());
            }

            $cheque->status = BillingCheque::RETURNED;
            $cheque->returned_on = $input['returned_on'];
            $cheque->returned_at = CarbonImmutable::now();
            $cheque->returned_by = $actor->id;
            $cheque->return_reason = trim($input['reason']);
            $cheque->save();
            $cheque->load('allocations');
            $this->auditor->returned($actor, $cheque);

            return $cheque;
        });
    }

    /** @throws ChequeNotFoundException another store's cheque is indistinguishable from a missing one */
    public function find(string $storeId, string $chequeId): BillingCheque
    {
        return BillingCheque::where('store_id', $storeId)->with(['allocations', 'receiver:id,name', 'returner:id,name'])->find($chequeId)
            ?? throw ChequeNotFoundException::forId($chequeId);
    }

    public function list(string $storeId, ?string $status, int $perPage, int $page): LengthAwarePaginator
    {
        return BillingCheque::where('store_id', $storeId)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->with(['allocations', 'receiver:id,name', 'returner:id,name'])
            ->orderByDesc('received_on')->orderByDesc('created_at')
            ->paginate(perPage: $perPage, page: $page);
    }

    /** @return Collection<int, BillingCheque> every cheque of the store (for the CSV export), newest first */
    public function all(string $storeId, ?string $status)
    {
        return BillingCheque::where('store_id', $storeId)
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->with(['allocations', 'receiver:id,name', 'returner:id,name'])
            ->orderByDesc('received_on')->orderByDesc('created_at')->get();
    }

    /** The billing balance of the store's one party as of an instant (derived; nothing stored). */
    public function balance(User $actor, ?CarbonImmutable $asOf): array
    {
        $party = $this->parties->forStore($actor->store_id);

        return $this->balance->balance($actor->store_id, $party->id, $party->name, $asOf ?? CarbonImmutable::now());
    }

    private function lockParty(GlobalLockOrder $order, string $storeId, string $partyId): void
    {
        $order->acquire(LockableResource::BillingParty);
        BillingParty::where('store_id', $storeId)->whereKey($partyId)->lockForUpdate()->firstOrFail();
    }

    /** Surrounding spaces removed, letters upper-cased: "ab 0012 " and "AB 0012" are the same cheque. */
    public static function normalizeNumber(string $number): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/', ' ', $number)));
    }

    /** Surrounding spaces removed and inner runs of spaces collapsed; the case is kept for display and ignored when comparing. */
    public static function normalizeBank(string $bank): string
    {
        return trim(preg_replace('/\s+/', ' ', $bank));
    }
}
