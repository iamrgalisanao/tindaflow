<?php

namespace App\Services\StoreSetup;

use App\Models\InventoryLocation;
use App\Models\InvoiceSeries;
use App\Models\TaxRegistration;
use App\Models\Terminal;
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
        $checks = $this->terminalChecks($storeId, $terminalId) + $this->storeChecks($storeId);

        return [
            'ready' => ! in_array(false, $checks, true),
            'checks' => $this->ordered($checks),
        ];
    }

    /**
     * The same four checks for the whole store, asked the way the till asks them: a store is ready only when EVERY
     * terminal that can sell (ACTIVE and not revoked) is ready, so this page can never say READY about a store whose
     * till is blocked. `terminals` names each one, with its own checks, so a failure can be pinned to a till. With no
     * terminal that can sell yet, the two per-terminal checks are false.
     *
     * @return array{ready: bool, checks: array<string, bool>, terminals: list<array{id: string, terminal_code: string, ready: bool, checks: array<string, bool>}>}
     */
    public function forStore(string $storeId): array
    {
        $storeChecks = $this->storeChecks($storeId);

        $terminals = Terminal::where('store_id', $storeId)
            ->whereNull('revoked_at')
            ->where('status', 'ACTIVE')
            ->orderBy('terminal_code')
            ->get()
            ->map(function (Terminal $terminal) use ($storeId, $storeChecks): array {
                $checks = $this->ordered($this->terminalChecks($storeId, $terminal->id) + $storeChecks);

                return [
                    'id' => $terminal->id,
                    'terminal_code' => $terminal->terminal_code,
                    'ready' => ! in_array(false, $checks, true),
                    'checks' => $checks,
                ];
            })
            ->all();

        $everyTerminal = fn (string $check): bool => $terminals !== [] && collect($terminals)->every(fn (array $terminal) => $terminal['checks'][$check]);

        $checks = $this->ordered([
            'fiscal_installation' => $everyTerminal('fiscal_installation'),
            'invoice_series' => $everyTerminal('invoice_series'),
        ] + $storeChecks);

        return [
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
            'terminals' => $terminals,
        ];
    }

    /** @return array{fiscal_installation: bool, invoice_series: bool} */
    private function terminalChecks(string $storeId, string $terminalId): array
    {
        $now = Carbon::now();

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

        return ['fiscal_installation' => $fiscalInstallationReady, 'invoice_series' => $invoiceSeriesReady];
    }

    /** @return array{inventory_location: bool, tax_registration: bool} */
    private function storeChecks(string $storeId): array
    {
        $today = Carbon::now()->toDateString();

        // Mirrors InventoryLocationResolver::resolveDefaultForStore.
        $inventoryLocationReady = InventoryLocation::where('store_id', $storeId)->where('is_default', true)->exists();

        // Mirrors TaxRegistrationResolver::resolveForStore's own
        // inclusive-both-ends window.
        $taxRegistrationReady = TaxRegistration::where('store_id', $storeId)
            ->where('effective_from', '<=', $today)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->exists();

        return ['inventory_location' => $inventoryLocationReady, 'tax_registration' => $taxRegistrationReady];
    }

    /**
     * @param  array<string, bool>  $checks
     * @return array<string, bool>
     */
    private function ordered(array $checks): array
    {
        return [
            'fiscal_installation' => $checks['fiscal_installation'],
            'invoice_series' => $checks['invoice_series'],
            'inventory_location' => $checks['inventory_location'],
            'tax_registration' => $checks['tax_registration'],
        ];
    }
}
