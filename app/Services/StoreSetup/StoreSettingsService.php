<?php

namespace App\Services\StoreSetup;

use App\Models\AuditEvent;
use App\Models\Store;
use App\Models\StoreSettings;
use App\Models\TaxRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * openapi.yaml storeSettingsGet / storeSettingsUpdate (Module B). One settings row per store, created on the
 * first save; until then a read shows a blank baseline (the store's own name, everything else empty) so a
 * new store has something to fill in. The row holds business identity only -- the tax registration lives in
 * its own effective-dated history and is only *shown* alongside.
 *
 * A change affects invoices issued from then on. Invoices already issued keep exactly what they were issued
 * with (ADR-006), because they render from their own snapshot and never read this table again.
 */
final class StoreSettingsService
{
    private const FIELDS = ['business_name', 'registered_name', 'business_address', 'tin', 'branch_code', 'invoice_header', 'invoice_footer', 'telephone', 'email'];

    private const REQUIRED_ON_CREATE = ['business_name', 'registered_name', 'tin'];

    /** @return array<string, string|null> */
    public function current(string $storeId): array
    {
        $row = StoreSettings::find($storeId);
        if ($row !== null) {
            return $row->only(self::FIELDS);
        }

        return array_replace(array_fill_keys(self::FIELDS, null), ['business_name' => Store::findOrFail($storeId)->name, 'registered_name' => '', 'tin' => '']);
    }

    public function currentTaxRegistration(string $storeId): ?TaxRegistration
    {
        return TaxRegistration::where('store_id', $storeId)->whereNull('effective_to')->first();
    }

    /**
     * @param  array<string, string|null>  $input  only the fields being changed
     * @return array<string, string|null> the settings after the change
     *
     * @throws ValidationException creating the settings without every identity field
     */
    public function update(User $actor, array $input): array
    {
        $changes = array_intersect_key($input, array_flip(self::FIELDS));

        return DB::transaction(function () use ($actor, $changes): array {
            $row = StoreSettings::whereKey($actor->store_id)->lockForUpdate()->first();

            if ($row === null) {
                $missing = array_values(array_filter(self::REQUIRED_ON_CREATE, fn (string $field) => ! array_key_exists($field, $changes)));
                if ($missing !== []) {
                    throw ValidationException::withMessages(array_fill_keys($missing, 'This is needed the first time the business details are saved.'));
                }

                $row = new StoreSettings(['store_id' => $actor->store_id] + $changes);
                $row->save();
                $this->audit($actor, array_fill_keys(array_keys($changes), null), $changes);

                return $row->fresh()->only(self::FIELDS);
            }

            $before = $row->only(array_keys($changes));
            $changed = array_filter($changes, fn ($value, $field) => $before[$field] !== $value, ARRAY_FILTER_USE_BOTH);
            if ($changed !== []) {
                $row->update($changed);
                $this->audit($actor, array_intersect_key($before, $changed), $changed);
            }

            return $row->fresh()->only(self::FIELDS);
        });
    }

    /**
     * @param  array<string, string|null>  $before
     * @param  array<string, string|null>  $after
     */
    private function audit(User $actor, array $before, array $after): void
    {
        AuditEvent::create([
            'store_id' => $actor->store_id,
            'event_type' => 'SETTINGS_CHANGED',
            'actor_user_id' => $actor->id,
            'entity_type' => 'store_settings',
            'entity_id' => $actor->store_id,
            'before_metadata' => $before,
            'after_metadata' => $after,
        ]);
    }
}
