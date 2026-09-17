<?php

namespace App\Services\Auth;

/**
 * The fixed role -> capability projection (api-design.md's "Stage 6
 * default role->capability mapping"; domain-model.md SS2.2's single,
 * centralized, code-level table -- not a dynamic/configurable permission
 * system). A1 uses this only to populate UserSummary.capabilities so
 * login/me can never drift from each other; A2 owns the actual
 * Gate::define() authorization checks built on top of this same table.
 */
final class RoleCapabilityCatalog
{
    /** @var array<string, list<string>> */
    private const MAP = [
        'ADMIN' => [
            'SALE_VOID', 'SALE_VOID_APPROVE', 'SALE_REFUND', 'SALE_REFUND_APPROVE',
            'PRICE_OVERRIDE', 'DISCOUNT_OVERRIDE', 'STOCK_ADJUST', 'CASH_OUT',
            'REPORT_VIEW', 'CATALOG_MANAGE', 'AUDIT_VIEW', 'JOURNAL_VIEW',
            'FISCAL_DAY_CLOSE', 'STORE_SETTINGS_MANAGE', 'USER_MANAGE',
            'TERMINAL_MANAGE', 'FISCAL_CONFIGURATION_MANAGE',
        ],
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
}
