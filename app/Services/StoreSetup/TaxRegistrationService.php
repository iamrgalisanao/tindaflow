<?php

namespace App\Services\StoreSetup;

use App\Models\AuditEvent;
use App\Models\TaxRegistration;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * openapi.yaml taxRegistrationCreate. "Never overwrites history" (Stage 2
 * invariant): the prior current registration is closed rather than
 * deleted or mutated in place.
 *
 * One exception, for a registration that has NOT STARTED yet (its effective_from is still in the future): the till only
 * applies a registration from its start date, so no sale has ever used it, and a mistyped start date would otherwise lock
 * the shop out until that date arrives (a new registration must start after the current one, and the unstarted one counts
 * as current). Registering again while one is unstarted therefore CORRECTS it in place -- new type and start date -- and
 * moves the end of the registration before it to match, with the old values kept in the audit trail. A registration that
 * has started is history and is never edited.
 */
final class TaxRegistrationService
{
    /** The current (open-ended) registration, if any, and whether it is still waiting for its start date. */
    public function unstartedCurrent(string $storeId): ?TaxRegistration
    {
        $current = TaxRegistration::where('store_id', $storeId)->whereNull('effective_to')->first();

        return $current !== null && $current->effective_from->toDateString() > Carbon::today()->toDateString() ? $current : null;
    }

    /** The latest registration that has already been closed: the one a correction must leave a day of its own. */
    public function previous(string $storeId): ?TaxRegistration
    {
        return TaxRegistration::where('store_id', $storeId)->whereNotNull('effective_to')->orderByDesc('effective_from')->first();
    }

    public function create(string $storeId, string $registrationType, string $effectiveFrom, ?User $actor = null): TaxRegistration
    {
        return DB::transaction(function () use ($storeId, $registrationType, $effectiveFrom, $actor) {
            // Closed the day BEFORE the new one starts, never the same
            // day -- TaxRegistrationResolver reads inclusive-both-ends
            // (`effective_from <= date <= effective_to`), so a shared
            // boundary day would resolve to both rows ("ambiguous") at
            // checkout.
            $priorEffectiveTo = Carbon::parse($effectiveFrom)->subDay()->toDateString();

            $unstarted = TaxRegistration::where('store_id', $storeId)->whereNull('effective_to')->lockForUpdate()->first();
            if ($unstarted !== null && $this->unstartedCurrent($storeId)?->id === $unstarted->id) {
                return $this->correct($unstarted, $registrationType, $effectiveFrom, $priorEffectiveTo, $actor);
            }

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

    private function correct(TaxRegistration $unstarted, string $registrationType, string $effectiveFrom, string $priorEffectiveTo, ?User $actor): TaxRegistration
    {
        $before = ['registration_type' => $unstarted->registration_type, 'effective_from' => $unstarted->effective_from->toDateString()];

        // The registration before it was closed the day before the mistaken start; close it the day before the right one.
        $previous = $this->previous($unstarted->store_id);
        if ($previous !== null) {
            $previous->update(['effective_to' => $priorEffectiveTo]);
        }

        $unstarted->update(['registration_type' => $registrationType, 'effective_from' => $effectiveFrom]);

        AuditEvent::create([
            'store_id' => $unstarted->store_id,
            'event_type' => 'TAX_REGISTRATION_CORRECTED',
            'actor_user_id' => $actor?->id,
            'entity_type' => 'tax_registration',
            'entity_id' => $unstarted->id,
            'before_metadata' => $before,
            'after_metadata' => ['registration_type' => $registrationType, 'effective_from' => $effectiveFrom],
            'reason' => 'A registration that had not started was re-registered; it never applied to a sale.',
        ]);

        return $unstarted->refresh();
    }
}
