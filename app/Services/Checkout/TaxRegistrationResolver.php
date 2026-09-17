<?php

namespace App\Services\Checkout;

use App\Domain\Exceptions\TaxRegistrationResolutionException;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a store's effective `registration_type` (`VAT`/`NON_VAT`) at a
 * given point in time from `tax_registrations` -- the frozen migration
 * comment names this check by name as "a Stage 6 application check, not
 * a schema one." Same resolution rigor as
 * {@see FiscalInstallationResolver} (exactly one match required; zero
 * or more than one is a data/setup defect this class refuses to guess
 * around), but a DIFFERENT interval convention, deliberately:
 * `tax_registrations` stores whole-day `date` columns, not timestamptz
 * instants, and its own CHECK constraint
 * (`tax_registrations_dates_check: effective_to >= effective_from`)
 * permits `effective_from == effective_to` as a valid single-day
 * registration -- which only makes sense under INCLUSIVE-end semantics
 * (`[effective_from, effective_to]`). Copying `terminal_fiscal_
 * installations`' exclusive-end convention here would make that
 * single-day case a zero-length interval, never actually in effect for
 * even a moment. This determination is made from the schema's own CHECK
 * constraint, not assumed by analogy -- flagged in
 * stage-6c-sale-finalization.md for owner confirmation.
 */
final class TaxRegistrationResolver
{
    public function resolveForStore(string $storeId, string $soldAtDate): string
    {
        $matches = DB::table('tax_registrations')
            ->where('store_id', $storeId)
            ->where('effective_from', '<=', $soldAtDate)
            ->where(function ($query) use ($soldAtDate) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>=', $soldAtDate);
            })
            ->pluck('registration_type');

        return match ($matches->count()) {
            0 => throw TaxRegistrationResolutionException::noActiveRegistrationForStore($storeId, $soldAtDate),
            1 => $matches->first(),
            default => throw TaxRegistrationResolutionException::ambiguousRegistrationForStore($storeId, $soldAtDate, $matches->count()),
        };
    }
}
