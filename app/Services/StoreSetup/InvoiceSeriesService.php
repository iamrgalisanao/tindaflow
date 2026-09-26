<?php

namespace App\Services\StoreSetup;

use App\Domain\Exceptions\InvoiceSeriesAlreadyActiveException;
use App\Domain\Exceptions\InvoiceSeriesAlreadyClosedException;
use App\Domain\Exceptions\InvoiceSeriesNotFoundException;
use App\Models\InvoiceSeries;
use App\Services\Idempotency\DatabaseExceptionTranslator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin surface for `invoice_series` -- the table
 * `InvoiceSeriesAllocator::allocateForFiscalInstallation()` reads at
 * checkout time. Without an ACTIVE row here for a fiscal installation,
 * `POST /sales` fails to allocate an invoice number.
 */
final class InvoiceSeriesService
{
    private const ONE_ACTIVE_PER_INSTALLATION = 'invoice_series_one_active_per_installation';

    public function __construct(
        private readonly DatabaseExceptionTranslator $exceptionTranslator = new DatabaseExceptionTranslator,
    ) {}

    /**
     * @param  array<string, mixed>  $data  the already-shape-validated request body
     *
     * @throws ValidationException when starting_number would reissue a number an earlier series of the same
     *                             fiscal installation already used (invariants.md #11/#18; the field error keeps
     *                             the frozen error catalog unchanged)
     */
    public function create(string $storeId, array $data): InvoiceSeries
    {
        $this->exceptionTranslator->registerConstraint(
            self::ONE_ACTIVE_PER_INSTALLATION,
            fn () => InvoiceSeriesAlreadyActiveException::forFiscalInstallation($data['fiscal_installation_id']),
        );

        try {
            return DB::transaction(function () use ($storeId, $data) {
                $this->assertNoIssuedNumberIsReused($storeId, $data['fiscal_installation_id'], (int) $data['starting_number']);

                return InvoiceSeries::create([
                    'store_id' => $storeId,
                    'fiscal_installation_id' => $data['fiscal_installation_id'],
                    'series_code' => $data['series_code'],
                    'prefix' => $data['prefix'] ?? null,
                    // Stage 6B bootstrap rule (invariants.md INVSERIES-001): a
                    // fresh series starts one below its own starting_number
                    // so the first real allocation yields starting_number
                    // exactly.
                    'current_number' => $data['starting_number'] - 1,
                    'starting_number' => $data['starting_number'],
                    'ending_number' => $data['ending_number'] ?? null,
                    'status' => 'ACTIVE',
                    'version' => 0,
                ]);
            });
        } catch (QueryException $e) {
            $this->exceptionTranslator->translate($e);
        }
    }

    /**
     * invoice_number is the bare digits of the counter (the prefix is not part of it) and is unique only per series, so
     * a replacement series that starts at or below the highest number an earlier series of the same installation
     * reached would issue an invoice number twice -- and closing and recreating would be a way to rewind the counter.
     * The earlier series are locked so a concurrent allocation cannot move the high-water mark under this check.
     *
     * While a series is still ACTIVE for the installation the request is refused with INVOICE_SERIES_ALREADY_ACTIVE by
     * the partial unique index on the insert, so this check has nothing to add and stays out of that answer's way.
     */
    private function assertNoIssuedNumberIsReused(string $storeId, string $fiscalInstallationId, int $startingNumber): void
    {
        $earlier = InvoiceSeries::where('store_id', $storeId)
            ->where('fiscal_installation_id', $fiscalInstallationId)
            ->lockForUpdate()
            ->get(['status', 'starting_number', 'current_number']);

        if ($earlier->contains('status', 'ACTIVE')) {
            return;
        }

        // A series that never issued anything (current_number still one below its start) reserves no numbers.
        $highestReached = (int) $earlier->filter(fn (InvoiceSeries $series) => $series->current_number >= $series->starting_number)->max('current_number');

        if ($highestReached > 0 && $startingNumber <= $highestReached) {
            throw ValidationException::withMessages([
                'starting_number' => 'Numbers up to '.$highestReached.' were already used by an earlier series of this fiscal installation. Start after '.$highestReached.'.',
            ]);
        }
    }

    public function close(string $storeId, string $invoiceSeriesId): InvoiceSeries
    {
        return DB::transaction(function () use ($storeId, $invoiceSeriesId) {
            $series = InvoiceSeries::where('store_id', $storeId)->lockForUpdate()->find($invoiceSeriesId);

            if ($series === null) {
                throw InvoiceSeriesNotFoundException::forId($invoiceSeriesId);
            }

            if ($series->status === 'CLOSED') {
                throw InvoiceSeriesAlreadyClosedException::forId($invoiceSeriesId);
            }

            $series->update(['status' => 'CLOSED']);

            return $series;
        });
    }
}
