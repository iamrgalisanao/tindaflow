<?php

namespace App\Services\StoreSetup;

use App\Domain\Exceptions\FiscalInstallationNotFoundException;
use App\Domain\Exceptions\TerminalNotFoundException;
use App\Models\FiscalInstallation;
use App\Models\Terminal;
use App\Models\TerminalFiscalInstallation;
use App\Models\User;
use App\Services\Audit\AuditTrail;
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
    public function create(string $storeId, array $data, ?User $actor = null): FiscalInstallation
    {
        return DB::transaction(function () use ($storeId, $data, $actor) {
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

            AuditTrail::record($storeId, 'FISCAL_INSTALLATION_CREATED', $actor?->id, 'fiscal_installation', $installation->id, null, [
                'deployment_model' => $installation->deployment_model,
                'software_version' => $installation->software_version,
                'machine_serial_number' => $installation->machine_serial_number,
                'has_accreditation' => ! empty($data['accreditation']),
                'has_permit_to_use' => ! empty($data['permit_to_use']),
            ]);

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
    public function assignTerminal(string $storeId, string $fiscalInstallationId, string $terminalId, ?Carbon $effectiveFrom, ?User $actor = null): TerminalFiscalInstallation
    {
        $effectiveFrom ??= now();

        return DB::transaction(function () use ($storeId, $fiscalInstallationId, $terminalId, $effectiveFrom, $actor) {
            $installation = FiscalInstallation::where('store_id', $storeId)->find($fiscalInstallationId);
            if ($installation === null) {
                throw FiscalInstallationNotFoundException::forId($fiscalInstallationId);
            }

            $terminal = Terminal::where('store_id', $storeId)->find($terminalId);
            if ($terminal === null) {
                throw TerminalNotFoundException::forId($terminalId);
            }

            $previous = TerminalFiscalInstallation::where('terminal_id', $terminalId)->whereNull('effective_to')->first();

            TerminalFiscalInstallation::where('terminal_id', $terminalId)
                ->whereNull('effective_to')
                ->update(['effective_to' => $effectiveFrom]);

            $mapping = TerminalFiscalInstallation::create([
                'store_id' => $storeId,
                'terminal_id' => $terminalId,
                'fiscal_installation_id' => $fiscalInstallationId,
                'effective_from' => $effectiveFrom,
                'effective_to' => null,
            ]);

            AuditTrail::record($storeId, 'FISCAL_INSTALLATION_TERMINAL_ASSIGNED', $actor?->id, 'terminal_fiscal_installation', $mapping->id,
                $previous === null ? null : ['fiscal_installation_id' => $previous->fiscal_installation_id],
                ['fiscal_installation_id' => $fiscalInstallationId, 'terminal_id' => $terminalId, 'terminal_code' => $terminal->terminal_code, 'effective_from' => $effectiveFrom->toIso8601String()],
                $terminalId,
            );

            return $mapping;
        });
    }
}
