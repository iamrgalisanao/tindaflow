<?php

namespace App\Services\InvoiceNumbering;

use App\Domain\Exceptions\InvoiceSeriesExhaustedException;
use App\Domain\Exceptions\InvoiceSeriesResolutionException;
use App\Services\Idempotency\DatabaseExceptionTranslator;
use App\Support\GlobalLockOrder;
use App\Support\LockableResource;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * ADR-004's row-locked counter, made callable. Resolves the applicable
 * `fiscal_installation`'s single ACTIVE `invoice_series` row, locks it
 * with `SELECT ... FOR UPDATE` (never a Postgres SEQUENCE, MAX()+1, or
 * an application-level lock -- ADR-004's "Alternatives Considered"),
 * allocates the next serial, and formats it. Stage 6B scope only: this
 * class does not create a `sale`/`invoice` row, and it never opens or
 * commits its own transaction -- see "Transaction ownership" below.
 *
 * Counter semantics (invariants.md INVSERIES-001, domain-model.md §2.6
 * "Counter bootstrap semantics"): `invoice_series.current_number` holds
 * the LAST allocated serial (ADR-004's own text -- "increment
 * current_number; use the new value to format invoice_number" --
 * unchanged). `starting_number` is the first ISSUABLE serial. A
 * brand-new, unused series must therefore be bootstrapped with
 * `current_number = starting_number - 1`, so its first real allocation
 * yields exactly `starting_number` -- never skipping it. This is the
 * corrected model: an earlier version of this class bootstrapped fresh
 * series AT `starting_number` (matching the CHECK constraint that
 * existed at the time), which meant the first real invoice was
 * `starting_number + 1` -- an avoidable off-by-one, corrected by the
 * `invoice_series_current_number_bounds_check` amendment
 * (`current_number >= starting_number - 1`) alongside this class.
 *
 * Transaction ownership: `allocateForFiscalInstallation()` performs its
 * `SELECT ... FOR UPDATE` and its `UPDATE` using whatever transaction is
 * already open on the connection (the caller's -- a future
 * Sale-finalization transaction, or a test's own). It never calls
 * `DB::transaction()` itself, never commits, and never rolls back on its
 * own initiative -- doing so would let a failed attempt's allocation
 * survive independently of the business mutation it was allocated for,
 * which is exactly what invariants.md #14 ("no gaps from failed
 * attempts") forbids. If the caller's transaction later rolls back for
 * any reason, PostgreSQL reverts this class's `UPDATE` along with
 * everything else, and the serial this call returned was never actually
 * consumed.
 *
 * Series resolution (invariants.md INVSERIES-002/003, domain-model.md
 * §2.6 "Series-to-installation binding"): `invoice_series` belongs to
 * exactly one `fiscal_installation`, not to a bare `store` alone --
 * `constraint-register.md`'s DB-INV-018 already established a store may
 * have more than one `invoice_series` row, and `store_id` alone gave the
 * allocator no way to choose between them. Resolution is now keyed by
 * `(store_id, fiscal_installation_id)`, with
 * `invoice_series_one_active_per_installation` (a partial unique index)
 * guaranteeing at most one ACTIVE row per fiscal installation. The
 * caller is responsible for resolving the authoritative
 * `fiscal_installation_id` for its terminal (via
 * `terminal_fiscal_installation`'s effective-dated mapping) before
 * calling in -- this class never infers it.
 *
 * Lock ordering: the caller must supply the SAME {@see GlobalLockOrder}
 * instance it has already used for `shift`/`fiscal_day`/`sale`/
 * `sale_item` locks earlier in the same transaction (architecture.md's
 * Global Lock Order: shift -> fiscal_day -> sale -> sale_item ->
 * invoice_series). This class only ever acquires `InvoiceSeries`, the
 * last position in that order, so it can never itself introduce a
 * lock-ordering violation -- but it still asserts through the shared
 * guard so a caller that mistakenly locks something else afterward is
 * caught.
 */
final class InvoiceSeriesAllocator
{
    private const CURRENT_NUMBER_BOUNDS_CHECK = 'invoice_series_current_number_bounds_check';

    public function __construct(
        private readonly DatabaseExceptionTranslator $exceptionTranslator = new DatabaseExceptionTranslator,
    ) {}

    /**
     * Resolves the fiscal installation's ACTIVE invoice_series, locks
     * it, and allocates the next serial. Must be called from inside the
     * caller's own open transaction (see class docblock).
     *
     * @throws InvoiceSeriesResolutionException zero (or, defensively, more than one) ACTIVE invoice_series
     *                                          exists for this fiscal installation
     * @throws InvoiceSeriesExhaustedException `current_number` has already reached the series' `ending_number`
     */
    public function allocateForFiscalInstallation(string $storeId, string $fiscalInstallationId, GlobalLockOrder $lockOrder): AllocatedInvoiceNumber
    {
        $lockOrder->acquire(LockableResource::InvoiceSeries);

        $activeSeries = DB::table('invoice_series')
            ->select(['id', 'current_number', 'ending_number'])
            ->where('store_id', $storeId)
            ->where('fiscal_installation_id', $fiscalInstallationId)
            ->where('status', 'ACTIVE')
            ->lockForUpdate()
            ->get();

        if ($activeSeries->isEmpty()) {
            throw InvoiceSeriesResolutionException::noActiveSeriesForFiscalInstallation($fiscalInstallationId);
        }

        if ($activeSeries->count() > 1) {
            throw InvoiceSeriesResolutionException::ambiguousActiveSeriesForFiscalInstallation($fiscalInstallationId, $activeSeries->count());
        }

        $series = $activeSeries->first();
        $invoiceSeriesId = $series->id;
        $currentNumber = (int) $series->current_number;
        $endingNumber = $series->ending_number !== null ? (int) $series->ending_number : null;

        // Checked BEFORE computing/writing the next serial (invariants.md
        // #19): current_number == ending_number means the series' last
        // issuable serial has already been allocated -- nothing remains.
        if ($endingNumber !== null && $currentNumber >= $endingNumber) {
            throw InvoiceSeriesExhaustedException::forSeries($invoiceSeriesId, $currentNumber + 1, $endingNumber);
        }

        $nextSerial = $currentNumber + 1;

        $this->exceptionTranslator->registerConstraint(
            self::CURRENT_NUMBER_BOUNDS_CHECK,
            fn () => InvoiceSeriesExhaustedException::forSeries($invoiceSeriesId, $nextSerial, $endingNumber ?? $nextSerial),
        );

        try {
            DB::table('invoice_series')
                ->where('id', $invoiceSeriesId)
                ->update([
                    'current_number' => $nextSerial,
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ]);
        } catch (QueryException $e) {
            $this->exceptionTranslator->translate($e);
        }

        return new AllocatedInvoiceNumber(
            invoiceSeriesId: $invoiceSeriesId,
            serial: $nextSerial,
            formattedNumber: self::format($nextSerial),
        );
    }

    /**
     * The frozen minimum display rule (openapi.yaml `invoice_number`
     * pattern `^[0-9]{6,}$`): digits only, zero-padded to at least six
     * characters, never truncated or prefixed. `1 -> "000001"`,
     * `999999 -> "999999"`, `1000000 -> "1000000"`. Kept as a pure
     * function of the numeric serial alone -- it takes no `series_code`/
     * `prefix`, since `invoice.invoice_number` is deliberately the
     * digits-only serial with no series prefix embedded (see
     * InvoiceSummary's own contract note in openapi.yaml).
     */
    public static function format(int $serial): string
    {
        if ($serial <= 0) {
            throw new InvalidArgumentException("An invoice serial must be a positive integer, got {$serial}.");
        }

        return str_pad((string) $serial, 6, '0', STR_PAD_LEFT);
    }
}
