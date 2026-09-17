<?php

namespace App\Domain\Exceptions;

use InvalidArgumentException;

/**
 * A Store TaxRegistration / SaleItem TaxClassification combination that
 * violates domain-model.md §4a / invariants.md TAX-NV-002/003 (e.g. a
 * NON_VAT line under a VAT-registered store, or vice versa). Deliberately
 * NOT a {@see DomainException} subclass: that hierarchy exists to map
 * 1:1 onto error-catalog.md's public API codes, and this condition is
 * unreachable from a validly configured product catalog -- it signals a
 * configuration defect upstream of FinancialCalculator, not a business
 * outcome a caller should receive as a stable HTTP error response. A
 * command handler that reaches this exception should log/alert
 * deliberately rather than translate it into a customer-facing error.
 */
final class InvalidTaxConfigurationException extends InvalidArgumentException
{
    public static function incompatibleClassification(int $lineNumber, string $taxClassification, string $taxRegistrationType): self
    {
        return new self(
            "Line {$lineNumber}'s tax classification \"{$taxClassification}\" is incompatible ".
            "with a {$taxRegistrationType}-registered store (domain-model.md §4a; invariants.md TAX-NV-002/003). ".
            'This combination must never be silently reinterpreted as valid.'
        );
    }

    public static function unknownRegistrationType(string $taxRegistrationType): self
    {
        return new self("Unknown tax registration type \"{$taxRegistrationType}\" -- expected \"VAT\" or \"NON_VAT\".");
    }
}
