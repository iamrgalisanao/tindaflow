<?php

namespace App\Services\Billing;

use App\Domain\CutOff;
use App\Services\Credit\CreditLedger;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * ADR-016 section 8 / slice 4.2, BILL-002 and BILL-006: what the billing party still owes, per cut-off period. NOTHING is stored.
 *
 *   billed   the credit ledger's CHARGE minus REVERSAL for the period (CreditLedger::totalsByPeriod, the same figure the billing
 *            statement reconciles with), so a later void or refund, which carries the original charge's period, lowers it;
 *   received the allocations of cheques that count at the as-of instant: status RECEIVED, or returned only AFTER that instant;
 *   owed     billed - received. Negative means the party has paid more than it now owes (a refund arrived after the cheque): it is shown
 *            as a credit to the party, never hidden or clamped.
 *
 * "As of" reproduces a past balance: ledger entries recorded after it, cheques recorded after it, and returns made after it are ignored
 * or reinstated accordingly (a return counts from its `returned_at`, the instant it was entered, not from the typed `returned_on` date).
 */
final class BillingBalanceService
{
    public const OPEN = 'OPEN';

    public const PARTLY_PAID = 'PARTLY_PAID';

    public const PAID = 'PAID';

    public const OVERPAID = 'OVERPAID';

    public const NO_CHARGES = 'NO_CHARGES';

    public function __construct(private readonly CreditLedger $ledger) {}

    /**
     * @return array<string, mixed> {party, as_of, periods[], totals}
     */
    public function balance(string $storeId, string $partyId, string $partyName, CarbonInterface $asOf): array
    {
        $periods = $this->periods($storeId, $partyId, $asOf, null);

        $billed = $received = $stillOwed = $credit = $returned = '0.00';
        foreach ($periods as $period) {
            $billed = bcadd($billed, $period['billed_amount'], 2);
            $received = bcadd($received, $period['received_amount'], 2);
            $returned = bcadd($returned, $period['returned_cheques_amount'], 2);
            if (bccomp($period['owed_amount'], '0', 2) > 0) {
                $stillOwed = bcadd($stillOwed, $period['owed_amount'], 2);
            } elseif (bccomp($period['owed_amount'], '0', 2) < 0) {
                $credit = bcadd($credit, bcmul($period['owed_amount'], '-1', 2), 2);
            }
        }

        return [
            'party' => ['id' => $partyId, 'name' => $partyName],
            'as_of' => $asOf->toIso8601String(),
            'periods' => $periods,
            'totals' => [
                'billed_amount' => $billed,
                'received_amount' => $received,
                'owed_amount' => $stillOwed,
                'credit_amount' => $credit,
                'returned_cheques_amount' => $returned,
                'period_count' => count($periods),
                'open_period_count' => count(array_filter($periods, fn (array $period) => in_array($period['status'], [self::OPEN, self::PARTLY_PAID], true))),
            ],
        ];
    }

    /**
     * What is owed (billed minus received) for exactly the given period starts, as of $asOf; a period with no activity owes "0.00".
     * The cheque service calls this under the billing party's lock.
     *
     * @param  list<string>  $periodStarts
     * @return array<string, string> period start => owed
     */
    public function owedFor(string $storeId, string $partyId, array $periodStarts, CarbonInterface $asOf): array
    {
        $owed = array_fill_keys($periodStarts, '0.00');
        foreach ($this->periods($storeId, $partyId, $asOf, $periodStarts) as $period) {
            $owed[$period['period_start']] = $period['owed_amount'];
        }

        return $owed;
    }

    /**
     * @param  list<string>|null  $only
     * @return list<array<string, mixed>> newest period first
     */
    private function periods(string $storeId, string $partyId, CarbonInterface $asOf, ?array $only): array
    {
        $ledger = $this->ledger->totalsByPeriod($storeId, $partyId, $asOf, $only);
        $allocations = $this->allocations($storeId, $partyId, $asOf, $only);

        $starts = array_unique(array_merge(array_keys($ledger), array_keys($allocations)));
        rsort($starts);
        $timezone = (string) config('app.timezone');

        $rows = [];
        foreach ($starts as $start) {
            $entry = $ledger[$start] ?? null;
            $period = CutOff::startingOn($start, $timezone);
            $charges = $entry['charges'] ?? '0.00';
            $reversals = $entry['reversals'] ?? '0.00';
            $billed = bcsub($charges, $reversals, 2);
            $counted = $allocations[$start]['received'] ?? '0.00';
            $owed = bcsub($billed, $counted, 2);

            $rows[] = [
                'period_start' => $start,
                'period_end' => $entry['period_end'] ?? $period?->endDate() ?? $start,
                'period_label' => $period?->label() ?? $start,
                'charged_amount' => $charges,
                'reversed_amount' => $reversals,
                'billed_amount' => $billed,
                'received_amount' => $counted,
                'returned_cheques_amount' => $allocations[$start]['returned'] ?? '0.00',
                'owed_amount' => $owed,
                'status' => self::statusOf($billed, $owed, $counted),
                'cheques' => $allocations[$start]['cheques'] ?? [],
            ];
        }

        return $rows;
    }

    public static function statusOf(string $billed, string $owed, string $received): string
    {
        if (bccomp($owed, '0', 2) < 0) {
            return self::OVERPAID;
        }
        if (bccomp($owed, '0', 2) > 0) {
            return bccomp($received, '0', 2) > 0 ? self::PARTLY_PAID : self::OPEN;
        }

        return bccomp($billed, '0', 2) > 0 ? self::PAID : self::NO_CHARGES;
    }

    /**
     * Allocations per period as of the instant: `received` counts the cheques that count, `returned` the ones that bounced by then.
     *
     * @param  list<string>|null  $only
     * @return array<string, array{received: string, returned: string, cheques: list<array<string, mixed>>}>
     */
    private function allocations(string $storeId, string $partyId, CarbonInterface $asOf, ?array $only): array
    {
        $rows = DB::table('billing_cheque_allocations as a')
            ->join('billing_cheques as c', 'c.id', '=', 'a.cheque_id')
            ->where('c.store_id', $storeId)
            ->where('c.billing_party_id', $partyId)
            ->where('c.created_at', '<=', $asOf->toIso8601String())
            ->when($only !== null, fn ($query) => $query->whereIn('a.period_start', $only))
            ->orderBy('c.received_on')->orderBy('c.created_at')
            ->get(['a.period_start', 'a.amount', 'c.id', 'c.cheque_number', 'c.bank', 'c.status', 'c.returned_at', 'c.received_on']);

        $byPeriod = [];
        foreach ($rows as $row) {
            $start = (string) $row->period_start;
            $byPeriod[$start] ??= ['received' => '0.00', 'returned' => '0.00', 'cheques' => []];
            $counts = $row->status === 'RECEIVED' || ($row->returned_at !== null && strtotime((string) $row->returned_at) > $asOf->getTimestamp());
            $byPeriod[$start][$counts ? 'received' : 'returned'] = bcadd($byPeriod[$start][$counts ? 'received' : 'returned'], (string) $row->amount, 2);
            $byPeriod[$start]['cheques'][] = [
                'cheque_id' => $row->id,
                'cheque_number' => $row->cheque_number,
                'bank' => $row->bank,
                'received_on' => (string) $row->received_on,
                'amount' => bcadd((string) $row->amount, '0', 2),
                'status' => $counts ? 'RECEIVED' : 'RETURNED',
            ];
        }

        return $byPeriod;
    }
}
