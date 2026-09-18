<?php

namespace App\Services\StoreSetup;

use App\Models\TaxRegistration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * openapi.yaml taxRegistrationCreate. "Never overwrites history" (Stage 2
 * invariant): the prior current registration is closed rather than
 * deleted or mutated in place.
 */
final class TaxRegistrationService
{
    public function create(string $storeId, string $registrationType, string $effectiveFrom): TaxRegistration
    {
        return DB::transaction(function () use ($storeId, $registrationType, $effectiveFrom) {
            // Closed the day BEFORE the new one starts, never the same
            // day -- TaxRegistrationResolver reads inclusive-both-ends
            // (`effective_from <= date <= effective_to`), so a shared
            // boundary day would resolve to both rows ("ambiguous") at
            // checkout.
            $priorEffectiveTo = Carbon::parse($effectiveFrom)->subDay()->toDateString();

            TaxRegistration::where('store_id', $storeId)
                ->whereNull('effective_to')
                ->update(['effective_to' => $priorEffectiveTo]);

            return TaxRegistration::create([
                'store_id' => $storeId,
                'registration_type' => $registrationType,
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
            ]);
        });
    }
}
