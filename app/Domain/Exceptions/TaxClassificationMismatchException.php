<?php

namespace App\Domain\Exceptions;

/**
 * error-catalog.md TAX_CLASSIFICATION_MISMATCH: a line's tax classification does not fit the store's tax registration
 * (invariants.md TAX-NV-002/003, rules 74 and 75). The line is never reclassified to make the sale go through.
 *
 * The calculator knows only the sale line number; checkout adds which item that line is ({@see forItem()}), because a meal
 * becomes several sale lines and the number means nothing to the cashier.
 */
final class TaxClassificationMismatchException extends DomainException
{
    /** @param  array<string, mixed>  $details */
    public function __construct(
        string $message,
        array $details,
        public readonly int $lineNumber,
        private readonly string $taxClassification,
        private readonly string $taxRegistrationType,
    ) {
        parent::__construct($message, $details);
    }

    public static function forLine(int $lineNumber, string $taxClassification, string $taxRegistrationType): self
    {
        return new self(
            "Line {$lineNumber}'s tax classification \"{$taxClassification}\" is incompatible with a {$taxRegistrationType}-registered store.",
            ['tax_classification' => $taxClassification, 'tax_registration_type' => $taxRegistrationType],
            $lineNumber,
            $taxClassification,
            $taxRegistrationType,
        );
    }

    /**
     * @param  bool  $fromMenu  a meal or add-on line: its hidden backing product got its tax class from the store's registration
     *                          on the day it was made, and no screen changes it, so the message must not ask for that.
     */
    public function forItem(string $productId, string $productName, bool $fromMenu): self
    {
        $remedy = $fromMenu
            ? "The product screens cannot change a meal or add-on's tax class: ask your administrator to check the store's tax ".
                'registration, or contact your system provider.'
            : "Ask a manager or administrator to check the item's tax class and the store's tax registration.";
        $store = $this->taxRegistrationType === 'VAT' ? 'VAT-registered' : 'non-VAT';

        return new self(
            "\"{$productName}\" has the tax class {$this->taxClassification}, which a {$store} store cannot sell. Nothing was sold. {$remedy}",
            ['product_id' => $productId, 'product_name' => $productName] + $this->details,
            $this->lineNumber,
            $this->taxClassification,
            $this->taxRegistrationType,
        );
    }

    public function errorCode(): string
    {
        return 'TAX_CLASSIFICATION_MISMATCH';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
