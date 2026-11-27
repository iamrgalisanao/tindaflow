<?php

namespace App\Domain\Exceptions;

use InvalidArgumentException;

/**
 * A Store TaxRegistration type the calculator does not know. Deliberately
 * NOT a {@see DomainException} subclass: the database allows only VAT and
 * NON_VAT, so reaching this is a programming defect, not something a
 * caller should receive as a stable HTTP error response. A line whose tax
 * classification does not fit the registration IS something a shop can set
 * up, and is {@see TaxClassificationMismatchException}.
 */
final class InvalidTaxConfigurationException extends InvalidArgumentException
{
    public static function unknownRegistrationType(string $taxRegistrationType): self
    {
        return new self("Unknown tax registration type \"{$taxRegistrationType}\" -- expected \"VAT\" or \"NON_VAT\".");
    }
}
