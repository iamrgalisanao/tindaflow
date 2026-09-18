<?php

namespace App\Services\StoreSetup;

use App\Models\InventoryLocation;
use App\Models\InvoiceSeries;
use App\Models\TaxRegistration;
use App\Models\TerminalFiscalInstallation;
use Illuminate\Support\Carbon;

/**
 * Read-only aggregate check across the four prerequisites
 * `CheckoutService`'s resolvers require (`FiscalInstallationResolver`,
 * `InvoiceSeriesAllocator`, `InventoryLocationResolver`,
 * `TaxRegistrationResolver`) -- lets the frontend show a clear "setup
 * incomplete" state before a cashier ever attempts a checkout that's
 * guaranteed to fail. Deliberately independent, read-only re-implementations
 * of each resolver's own lookup query rather than calling into
 * `app/Services/Checkout/*` directly -- that package is frozen under the
 * Stage 6C addendum ("may not silently modify CheckoutService, its
 * resolvers, or their exceptions"), and this new read-only surface has no
 * need to touch it to answer the same question non-authoritatively.
 */
final class StoreSetupReadinessService
{
    /** @return array{ready: bool, checks: array<string, bool>} */
    public function forTerminal(string $storeId, string $terminalId): array
    {
        $now = Carbon::now();
        $today = $now->toDateString();

        // Mirrors FiscalInstallationResolver::resolveForTerminal's own
        // inclusive-start/exclusive-end window.
        $currentMapping = TerminalFiscalInstallation::where('terminal_id', $terminalId)
            ->where('effective_from', '<=', $now)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $now))
            ->first();

        $fiscalInstallationReady = $currentMapping !== null;

        // Mirrors InvoiceSeriesAllocator::allocateForFiscalInstallation's
        // own "exactly one ACTIVE series for this installation" lookup.
        $invoiceSeriesReady = $fiscalInstallationReady && InvoiceSeries::where('store_id', $storeId)
            ->where('fiscal_installation_id', $currentMapping->fiscal_installation_id)
            ->where('status', 'ACTIVE')
            ->exists();

        // Mirrors InventoryLocationResolver::resolveDefaultForStore.
        $inventoryLocationReady = InventoryLocation::where('store_id', $storeId)->where('is_default', true)->exists();

        // Mirrors TaxRegistrationResolver::resolveForStore's own
        // inclusive-both-ends window.
        $taxRegistrationReady = TaxRegistration::where('store_id', $storeId)
            ->where('effective_from', '<=', $today)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->exists();

        $checks = [
            'fiscal_installation' => $fiscalInstallationReady,
            'invoice_series' => $invoiceSeriesReady,
            'inventory_location' => $inventoryLocationReady,
            'tax_registration' => $taxRegistrationReady,
        ];

        return [
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
        ];
    }
}
