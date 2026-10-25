<?php

namespace App\Services\StoreSetup;

use App\Domain\Exceptions\SaleDocumentModeChangeBlockedException;
use App\Enums\SaleDocumentMode;
use App\Models\AuditEvent;
use App\Models\Store;
use App\Models\StoreSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ADR-016 section 7: how the store documents a sale (the invoice path, or the slip path) and the slip's footer line.
 *
 * The mode is a business decision of the whole store, uniform across its tills, so it is changed only here: by a user holding
 * FISCAL_CONFIGURATION_MANAGE (the ADR-014 vendor unlock), only while no shift and no business day is open on any terminal, and with a
 * SALE_DOCUMENT_MODE_CHANGED audit event. Each sale records the mode it was finalized in, so a store that switches keeps an
 * unambiguous history. The change takes a row lock on the store that ShiftOpenService also takes (shared), so a shift cannot slip in
 * between the "nothing is open" check and the switch.
 *
 * The slip footer is the store's own free-text line at the bottom of every slip (default empty). It is ordinary store configuration
 * (STORE_SETTINGS_MANAGE) and, like the invoice footer, affects slips printed from then on only; a printed slip keeps its snapshot.
 */
final class SaleDocumentSettingsService
{
    /** @return array{sale_document_mode: string, slip_footer: ?string, open_shifts: int, open_fiscal_days: int} */
    public function current(string $storeId): array
    {
        $row = StoreSettings::find($storeId);

        return [
            'sale_document_mode' => $row?->sale_document_mode ?? SaleDocumentMode::Invoice->value,
            'slip_footer' => $row?->slip_footer,
            ...$this->openCounts($storeId),
        ];
    }

    /**
     * @return array{sale_document_mode: string, slip_footer: ?string, open_shifts: int, open_fiscal_days: int}
     *
     * @throws SaleDocumentModeChangeBlockedException a shift or business day is open somewhere in the store
     */
    public function changeMode(User $actor, SaleDocumentMode $mode): array
    {
        return DB::transaction(function () use ($actor, $mode): array {
            Store::whereKey($actor->store_id)->lockForUpdate()->firstOrFail();

            $row = StoreSettings::whereKey($actor->store_id)->lockForUpdate()->first();
            $before = $row?->sale_document_mode ?? SaleDocumentMode::Invoice->value;
            if ($before === $mode->value) {
                return $this->current($actor->store_id);
            }

            $open = $this->openCounts($actor->store_id);
            if ($open['open_shifts'] > 0 || $open['open_fiscal_days'] > 0) {
                throw SaleDocumentModeChangeBlockedException::becauseSomethingIsOpen($open['open_shifts'], $open['open_fiscal_days']);
            }

            $this->row($actor->store_id, $row)->update(['sale_document_mode' => $mode->value]);

            AuditEvent::create([
                'store_id' => $actor->store_id,
                'event_type' => 'SALE_DOCUMENT_MODE_CHANGED',
                'actor_user_id' => $actor->id,
                'entity_type' => 'store_settings',
                'entity_id' => $actor->store_id,
                'before_metadata' => ['sale_document_mode' => $before],
                'after_metadata' => ['sale_document_mode' => $mode->value],
            ]);

            return $this->current($actor->store_id);
        });
    }

    /** @return array{sale_document_mode: string, slip_footer: ?string, open_shifts: int, open_fiscal_days: int} */
    public function setSlipFooter(User $actor, ?string $footer): array
    {
        return DB::transaction(function () use ($actor, $footer): array {
            $row = StoreSettings::whereKey($actor->store_id)->lockForUpdate()->first();
            $before = $row?->slip_footer;

            if ($before !== $footer) {
                $this->row($actor->store_id, $row)->update(['slip_footer' => $footer]);

                AuditEvent::create([
                    'store_id' => $actor->store_id,
                    'event_type' => 'SETTINGS_CHANGED',
                    'actor_user_id' => $actor->id,
                    'entity_type' => 'store_settings',
                    'entity_id' => $actor->store_id,
                    'before_metadata' => ['slip_footer' => $before],
                    'after_metadata' => ['slip_footer' => $footer],
                ]);
            }

            return $this->current($actor->store_id);
        });
    }

    /**
     * The store's settings row, created on first need. A store that has never saved its business details gets the same blank baseline
     * StoreSettingsService shows (its own name, empty registered name and TIN), so the business details screen still recognises it
     * as not yet filled in.
     */
    private function row(string $storeId, ?StoreSettings $row): StoreSettings
    {
        if ($row !== null) {
            return $row;
        }

        $created = new StoreSettings(['store_id' => $storeId, 'business_name' => Store::findOrFail($storeId)->name, 'registered_name' => '', 'tin' => '']);
        $created->save();

        return $created;
    }

    /** @return array{open_shifts: int, open_fiscal_days: int} */
    private function openCounts(string $storeId): array
    {
        return [
            'open_shifts' => DB::table('shifts')->join('terminals', 'terminals.id', '=', 'shifts.terminal_id')
                ->where('terminals.store_id', $storeId)->where('shifts.status', 'OPEN')->count(),
            'open_fiscal_days' => DB::table('fiscal_days')->where('store_id', $storeId)->where('status', 'OPEN')->count(),
        ];
    }
}
