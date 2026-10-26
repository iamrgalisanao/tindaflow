<?php

namespace App\Services\Billing;

use App\Domain\Exceptions\BillingRunNotFoundException;
use App\Models\BillingParty;
use App\Models\BillingRun;
use App\Models\Employee;
use App\Models\User;
use App\Services\Employees\BillingPartyResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-016 section 8 / slice 4.1: the billing statement and the saved billing run. previewing is a read; saving is an explicit action that
 * freezes the statement as it stood into the append-only `billing_runs` table with the next gap-free number (BR-000001, one counter per
 * store, locked FOR UPDATE inside the same transaction as the insert, like the employee codes).
 *
 * A re-run for the same range never edits an earlier run: it is a new row, and both the live statement and a new run carry a
 * `comparison` with the latest EARLIER run for the same party, range and filters, listing the voids and refunds recorded since
 * (added adjustments), any no longer counted (an earlier as-of instant), and how the sold, returned and net totals moved.
 */
final class BillingService
{
    public function __construct(
        private readonly BillingStatementBuilder $builder,
        private readonly BillingPartyResolver $parties,
        private readonly BillingAuditor $auditor,
    ) {}

    /**
     * The live statement. Nothing is stored.
     *
     * @param  array<string, mixed>  $input  validated BillingRequest input
     * @return array<string, mixed>
     */
    public function preview(User $actor, array $input): array
    {
        return $this->compute($actor, $this->criteria($actor, $input));
    }

    /**
     * Saves the statement as the next billing run. The statement is built first (in its own snapshot read); the number is then taken and
     * the row inserted in a short transaction of its own, so concurrent saves queue on the counter instead of failing a snapshot.
     *
     * @param  array<string, mixed>  $input
     */
    public function generate(User $actor, array $input): BillingRun
    {
        $criteria = $this->criteria($actor, $input);
        $statement = $this->compute($actor, $criteria);

        return DB::transaction(function () use ($actor, $criteria, $statement): BillingRun {
            $sequence = $this->nextSequence($actor->store_id);
            $totals = $statement['totals'];
            $generatedAt = CarbonImmutable::parse($statement['generated_at']);

            $run = BillingRun::create([
                'store_id' => $actor->store_id,
                'billing_party_id' => $criteria->partyId,
                'sequence' => $sequence,
                'run_number' => self::format($sequence),
                'from_date' => $criteria->from,
                'to_date' => $criteria->to,
                'period_label' => $criteria->periodLabel,
                'employee_type' => $criteria->employeeType,
                'employee_id' => $criteria->employeeId,
                'employee_code' => $criteria->employeeCode,
                'as_of' => $criteria->asOf,
                'generated_by' => $actor->id,
                'generated_at' => $generatedAt,
                'charges_total' => $totals['sold_amount'],
                'reversals_total' => $totals['returned_amount'],
                'net_total' => $totals['net_amount'],
                'account_count' => $totals['employee_count'],
                'sale_count' => $totals['sale_count'],
                'statement' => $statement,
                'statement_sha256' => self::hash($statement),
            ]);
            $this->auditor->runCreated($actor, $run);

            return $run;
        });
    }

    /** @throws BillingRunNotFoundException a run of another store is indistinguishable from a missing one */
    public function find(string $storeId, string $runId): BillingRun
    {
        return BillingRun::where('store_id', $storeId)->find($runId) ?? throw BillingRunNotFoundException::forId($runId);
    }

    public function list(string $storeId, int $perPage, int $page): LengthAwarePaginator
    {
        return BillingRun::where('store_id', $storeId)
            ->select(['id', 'run_number', 'sequence', 'from_date', 'to_date', 'period_label', 'employee_type', 'employee_code', 'as_of', 'generated_by',
                'generated_at', 'charges_total', 'reversals_total', 'net_total', 'account_count', 'sale_count', 'statement_sha256'])
            ->with('generator:id,name')
            ->orderByDesc('sequence')
            ->paginate(perPage: $perPage, page: $page);
    }

    /** Whether the stored statement still hashes to the value recorded when the run was saved (BILL-004). */
    public function isIntact(BillingRun $run): bool
    {
        return hash_equals($run->statement_sha256, self::hash($run->statement));
    }

    public static function format(int $sequence): string
    {
        return 'BR-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    /**
     * The SHA-256 of the statement with every object's keys in sorted order, so it is the same before it is stored and after jsonb returns it.
     *
     * @param  array<string, mixed>  $statement
     */
    public static function hash(array $statement): string
    {
        return hash('sha256', (string) json_encode(self::canonical($statement), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(fn ($item) => self::canonical($item), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /** @param  array<string, mixed>  $input */
    private function criteria(User $actor, array $input): BillingCriteria
    {
        $party = $this->parties->forStore($actor->store_id);
        $employeeCode = null;
        if (! empty($input['employee_id'])) {
            $employeeCode = Employee::where('store_id', $actor->store_id)->whereKey($input['employee_id'])->value('employee_code')
                ?? throw ValidationException::withMessages(['employee_id' => 'That employee was not found.']);
        }

        return BillingCriteria::fromValidated($party->id, $input, CarbonImmutable::now(), $employeeCode);
    }

    /** @return array<string, mixed> */
    private function compute(User $actor, BillingCriteria $criteria): array
    {
        $party = BillingParty::findOrFail($criteria->partyId);

        return DB::transaction(function () use ($actor, $criteria, $party): array {
            // One snapshot for every query of the statement. Inside an outer transaction (a test) the level cannot be changed, and the
            // as-of filter plus append-only entries keep the statement consistent anyway.
            if (DB::transactionLevel() === 1) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            }
            $statement = $this->builder->build($actor->store_id, $party->name, $criteria);
            $statement['comparison'] = $this->compare($actor->store_id, $criteria, $statement);

            return $statement;
        });
    }

    private function nextSequence(string $storeId): int
    {
        DB::table('billing_run_counters')->insertOrIgnore(['store_id' => $storeId, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::selectOne('SELECT last_number FROM billing_run_counters WHERE store_id = ? FOR UPDATE', [$storeId]);
        $next = (int) $row->last_number + 1;
        DB::update('UPDATE billing_run_counters SET last_number = ?, updated_at = ? WHERE store_id = ?', [$next, now(), $storeId]);

        return $next;
    }

    /**
     * How this statement differs from the latest EARLIER saved run for the same party, range and filters; null when there is none.
     *
     * @param  array<string, mixed>  $statement
     * @return array<string, mixed>|null
     */
    private function compare(string $storeId, BillingCriteria $criteria, array $statement): ?array
    {
        $previous = BillingRun::where('store_id', $storeId)
            ->where('billing_party_id', $criteria->partyId)
            ->where('from_date', $criteria->from)->where('to_date', $criteria->to)
            ->where(fn ($query) => $criteria->employeeType === null ? $query->whereNull('employee_type') : $query->where('employee_type', $criteria->employeeType))
            ->where(fn ($query) => $criteria->employeeId === null ? $query->whereNull('employee_id') : $query->where('employee_id', $criteria->employeeId))
            ->orderByDesc('sequence')->first();

        if ($previous === null) {
            return null;
        }

        $before = collect($previous->statement['adjustments'] ?? [])->keyBy('entry_id');
        $now = collect($statement['adjustments'])->keyBy('entry_id');
        $added = $now->diffKeys($before)->values()->all();
        $removed = $before->diffKeys($now)->values()->all();
        $totals = $statement['totals'];

        $change = fn (string $now, string $then): string => bcsub($now, $then, 2);
        $netChange = $change($totals['net_amount'], (string) $previous->net_total);
        $soldChange = $change($totals['sold_amount'], (string) $previous->charges_total);
        $returnedChange = $change($totals['returned_amount'], (string) $previous->reversals_total);

        return [
            'previous_run' => [
                'id' => $previous->id,
                'run_number' => $previous->run_number,
                'generated_at' => $previous->generated_at->toIso8601String(),
                'as_of' => $previous->as_of->toIso8601String(),
                'sold_amount' => (string) $previous->charges_total,
                'returned_amount' => (string) $previous->reversals_total,
                'net_amount' => (string) $previous->net_total,
            ],
            'sold_change' => $soldChange,
            'returned_change' => $returnedChange,
            'net_change' => $netChange,
            'changed' => bccomp($netChange, '0', 2) !== 0 || $added !== [] || $removed !== [],
            'added_adjustments' => $added,
            'removed_adjustments' => $removed,
        ];
    }
}
