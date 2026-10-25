<?php

namespace App\Enums;

/**
 * ADR-016 section 5: a direct employee of the client (code TSC00001) or a subcontractor's employee (code SUB00001). Both are
 * billed to the one billing party TSC (client decision 2026-10-25); the type decides the code prefix and the credit limit.
 */
enum EmployeeType: string
{
    case Direct = 'DIRECT';
    case Subcontractor = 'SUBCONTRACTOR';

    public function codePrefix(): string
    {
        return match ($this) {
            self::Direct => 'TSC',
            self::Subcontractor => 'SUB',
        };
    }

    /** The limit per cut-off a store starts with (client decision 2026-10-25: TSC 1,500, SUB 1,000). */
    public function defaultCreditLimit(): string
    {
        return match ($this) {
            self::Direct => '1500.00',
            self::Subcontractor => '1000.00',
        };
    }

    /** Accepts the stored value, the code prefix or a plain word in either case ("direct", "tsc", "SUB"); null when unrecognised. */
    public static function fromLabel(string $label): ?self
    {
        return match (strtoupper(trim($label))) {
            'DIRECT', 'TSC' => self::Direct,
            'SUBCONTRACTOR', 'SUB' => self::Subcontractor,
            default => null,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
