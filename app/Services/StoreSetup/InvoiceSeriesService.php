<?php

namespace App\Services\StoreSetup;

use App\Domain\Exceptions\InvoiceSeriesAlreadyActiveException;
use App\Domain\Exceptions\InvoiceSeriesAlreadyClosedException;
use App\Domain\Exceptions\InvoiceSeriesNotFoundException;
use App\Models\InvoiceSeries;
use App\Services\Idempotency\DatabaseExceptionTranslator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

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

    /** @param  array<string, mixed>  $data  the already-shape-validated request body */
    public function create(string $storeId, array $data): InvoiceSeries
    {
        $this->exceptionTranslator->registerConstraint(
            self::ONE_ACTIVE_PER_INSTALLATION,
            fn () => InvoiceSeriesAlreadyActiveException::forFiscalInstallation($data['fiscal_installation_id']),
        );

        try {
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
        } catch (QueryException $e) {
            $this->exceptionTranslator->translate($e);
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
