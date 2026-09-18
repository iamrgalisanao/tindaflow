<?php

namespace App\Services\StoreSetup;

use App\Domain\Exceptions\FiscalInstallationNotFoundException;
use App\Domain\Exceptions\TerminalNotFoundException;
use App\Models\FiscalInstallation;
use App\Models\Terminal;
use App\Models\TerminalFiscalInstallation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Store-setup admin surface (openapi.yaml FiscalInstallation tag,
 * FISCAL_CONFIGURATION_MANAGE). Accreditation/PermitToUse are recorded as
 * independent effective-dated child rows per ADR-009 -- this is
 * administrative record-keeping only; it does not itself constitute BIR
 * accreditation (TindaFlow V1 is not accredited, architecture.md §12).
 */
final class FiscalInstallationService
{
    /** @param  array<string, mixed>  $data  the already-shape-validated request body */
    public function create(string $storeId, array $data): FiscalInstallation
    {
        return DB::transaction(function () use ($storeId, $data) {
            $installation = FiscalInstallation::create([
                'store_id' => $storeId,
                'deployment_model' => $data['deployment_model'],
                'machine_serial_number' => $data['machine_serial_number'] ?? null,
                'software_version' => $data['software_version'],
                'installed_at' => now(),
                'superseded_at' => null,
            ]);

            if (! empty($data['accreditation'])) {
                $installation->accreditations()->create([
                    'number' => $data['accreditation']['number'] ?? null,
                    'date' => $data['accreditation']['date'] ?? null,
                    'effective_from' => $data['accreditation']['date'] ?? now()->toDateString(),
                    'effective_to' => null,
                ]);
            }

            if (! empty($data['permit_to_use'])) {
                $installation->permitsToUse()->create([
                    'number' => $data['permit_to_use']['number'] ?? null,
                    'min' => $data['permit_to_use']['min'] ?? null,
                    'date' => $data['permit_to_use']['date'] ?? null,
                    'effective_from' => $data['permit_to_use']['date'] ?? now()->toDateString(),
                    'effective_to' => null,
                ]);
            }

            return $installation->load(['accreditations', 'permitsToUse', 'terminals' => fn ($query) => $query->wherePivotNull('effective_to')]);
        });
    }

    /**
     * Assigns (or reassigns) the terminal's current fiscal installation
     * mapping -- this is exactly the `terminal_fiscal_installations` row
     * `FiscalInstallationResolver::resolveForTerminal()` reads at
     * checkout time; without one, `POST /sales` fails with
     * `FiscalInstallationResolutionException::noMappingForTerminal`.
     * Reassignment closes the terminal's existing current mapping (only
     * one `effective_to IS NULL` row per terminal is allowed) rather than
     * requiring a separate close step first, matching the
     * `taxRegistrationCreate` "prior one closed" convention.
     */
    public function assignTerminal(string $storeId, string $fiscalInstallationId, string $terminalId, ?Carbon $effectiveFrom): TerminalFiscalInstallation
    {
        $effectiveFrom ??= now();

        return DB::transaction(function () use ($storeId, $fiscalInstallationId, $terminalId, $effectiveFrom) {
            $installation = FiscalInstallation::where('store_id', $storeId)->find($fiscalInstallationId);
            if ($installation === null) {
                throw FiscalInstallationNotFoundException::forId($fiscalInstallationId);
            }

            $terminal = Terminal::where('store_id', $storeId)->find($terminalId);
            if ($terminal === null) {
                throw TerminalNotFoundException::forId($terminalId);
            }

            TerminalFiscalInstallation::where('terminal_id', $terminalId)
                ->whereNull('effective_to')
                ->update(['effective_to' => $effectiveFrom]);

            return TerminalFiscalInstallation::create([
                'store_id' => $storeId,
                'terminal_id' => $terminalId,
                'fiscal_installation_id' => $fiscalInstallationId,
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
            ]);
        });
    }
}
