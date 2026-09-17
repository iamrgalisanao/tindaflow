<?php

namespace App\Domain\Exceptions;

use RuntimeException;

/**
 * A terminal has zero (or, defensively, more than one) effective
 * `terminal_fiscal_installations` mapping at a given point in time.
 * Deliberately NOT a {@see DomainException} subclass, for the same reason
 * as {@see InvoiceSeriesResolutionException}: a properly enrolled/configured
 * terminal should always have exactly one current mapping before it is ever
 * allowed to process a sale -- this signals a setup/configuration defect
 * (or, for the ambiguous case, overlapping effective-dated rows that should
 * never coexist), not a business outcome a checkout caller should receive
 * as a stable HTTP error. Stage 6C ruling (stage-6c-sale-finalization.md
 * SS"Gap 1"): the interval is inclusive-start, exclusive-end
 * [effective_from, effective_to) -- ambiguity at a boundary is a data
 * problem to fix, never a reason for checkout to guess via "latest wins."
 */
final class FiscalInstallationResolutionException extends RuntimeException
{
    public static function noMappingForTerminal(string $terminalId, string $soldAt): self
    {
        return new self(
            "Terminal \"{$terminalId}\" has no effective terminal_fiscal_installation mapping at {$soldAt}. ".
            'Every terminal must be assigned a fiscal installation before it can finalize a sale -- this is a '.
            'setup defect, not a runtime condition checkout should ever encounter.'
        );
    }

    public static function ambiguousMappingForTerminal(string $terminalId, string $soldAt, int $matchCount): self
    {
        return new self(
            "Terminal \"{$terminalId}\" has {$matchCount} effective terminal_fiscal_installation mappings at ".
            "{$soldAt}. Overlapping effective-dated rows for the same terminal should never coexist -- this is a ".
            'data-integrity problem to investigate and fix directly, never a normal application state to resolve '.
            'by picking the newest or first row.'
        );
    }
}
