<?php

namespace App\Services\Auth;

/**
 * The fixed role -> capability projection (api-design.md's "Stage 6
 * default role->capability mapping"; domain-model.md SS2.2's single,
 * centralized, code-level table -- not a dynamic/configurable permission
 * system). A1 used this to populate UserSummary.capabilities so login/me
 * can never drift from each other; A2 registers a Laravel Gate ability
 * per entry in CAPABILITIES, each backed by has(), so authorization and
 * the API representation read from the exact same source and can never
 * diverge.
 */
final class RoleCapabilityCatalog
{
    /**
     * The fixed V1 capability vocabulary -- domain-model.md SS2.2's
     * catalog (11 original + 6 added in the Stage 4 remediation pass),
     * identical to openapi.yaml's Capability enum.
     *
     * @var list<string>
     */
    public const CAPABILITIES = [
        'SALE_VOID', 'SALE_VOID_APPROVE', 'SALE_REFUND', 'SALE_REFUND_APPROVE',
        'PRICE_OVERRIDE', 'DISCOUNT_OVERRIDE', 'STOCK_ADJUST', 'CASH_OUT',
        'REPORT_VIEW', 'STORE_SETTINGS_MANAGE', 'FISCAL_DAY_CLOSE', 'CATALOG_MANAGE',
        'AUDIT_VIEW', 'JOURNAL_VIEW', 'USER_MANAGE', 'TERMINAL_MANAGE',
        'FISCAL_CONFIGURATION_MANAGE',
    ];

    /** @var array<string, list<string>> */
    private const MAP = [
        // ADMIN is defined as literally every known capability, not a
        // manually duplicated 17-item list -- "Admin: all functions" is
        // then a structural guarantee, never something that can drift
        // out of sync with CAPABILITIES above.
        'ADMIN' => self::CAPABILITIES,
        'MANAGER' => [
            'SALE_VOID', 'SALE_VOID_APPROVE', 'SALE_REFUND', 'SALE_REFUND_APPROVE',
            'PRICE_OVERRIDE', 'DISCOUNT_OVERRIDE', 'STOCK_ADJUST', 'CASH_OUT',
            'REPORT_VIEW', 'CATALOG_MANAGE', 'AUDIT_VIEW', 'JOURNAL_VIEW',
            'FISCAL_DAY_CLOSE',
        ],
        'CASHIER' => [
            'SALE_VOID', 'SALE_REFUND',
        ],
    ];

    /** @return list<string> */
    public static function forRole(string $role): array
    {
        return self::MAP[$role] ?? [];
    }

    /** Unknown roles are never granted anything -- forRole()'s empty-array fallback already guarantees this; has() just names the check. */
    public static function has(string $role, string $capability): bool
    {
        return in_array($capability, self::forRole($role), true);
    }
}
