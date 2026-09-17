<?php

namespace App\Services\Checkout;

use App\Domain\Exceptions\FiscalInstallationResolutionException;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a terminal's effective `fiscal_installation_id` at a given
 * point in time from `terminal_fiscal_installations` (Stage 2's
 * effective-dated join table for STANDALONE vs. SERVER_CONNECTED
 * deployments). Stage 6C ruling (stage-6c-sale-finalization.md
 * SS"Gap 1"): the effective interval is inclusive-start, exclusive-end
 * -- [effective_from, effective_to) -- and resolution requires exactly
 * one matching row; zero or more than one is a data/setup defect this
 * class refuses to guess around.
 *
 * A plain read, not a locked one: this resolution does not need its own
 * entry in the checkout Global Lock Order (architecture.md's frozen
 * `shift -> fiscal_day -> invoice_series` order for Checkout is
 * unchanged) -- it runs inside the same transaction as the rest of
 * finalization, but as an ordinary SELECT.
 */
final class FiscalInstallationResolver
{
    public function resolveForTerminal(string $terminalId, string $soldAt): string
    {
        $matches = DB::table('terminal_fiscal_installations')
            ->where('terminal_id', $terminalId)
            ->where('effective_from', '<=', $soldAt)
            ->where(function ($query) use ($soldAt) {
                $query->whereNull('effective_to')->orWhere('effective_to', '>', $soldAt);
            })
            ->pluck('fiscal_installation_id');

        return match ($matches->count()) {
            0 => throw FiscalInstallationResolutionException::noMappingForTerminal($terminalId, $soldAt),
            1 => $matches->first(),
            default => throw FiscalInstallationResolutionException::ambiguousMappingForTerminal($terminalId, $soldAt, $matches->count()),
        };
    }
}
