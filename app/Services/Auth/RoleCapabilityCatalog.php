<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\StoreSetup\FiscalConfigurationLock;

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

    /**
     * ADR-014: not part of any role's fixed set. An ADMIN holds it only while the server operator has the store's
     * fiscal configuration unlocked (FiscalConfigurationLock); see forUser()/hasForUser().
     */
    public const UNLOCK_ONLY_CAPABILITY = 'FISCAL_CONFIGURATION_MANAGE';

    /** @var array<string, list<string>> */
    private const MAP = [
        // ADMIN is every known capability except the one ADR-014 holds back, so "Admin: all functions" stays a
        // structural guarantee that cannot drift out of sync with CAPABILITIES above.
        'ADMIN' => [
            'SALE_VOID', 'SALE_VOID_APPROVE', 'SALE_REFUND', 'SALE_REFUND_APPROVE',
            'PRICE_OVERRIDE', 'DISCOUNT_OVERRIDE', 'STOCK_ADJUST', 'CASH_OUT',
            'REPORT_VIEW', 'STORE_SETTINGS_MANAGE', 'FISCAL_DAY_CLOSE', 'CATALOG_MANAGE',
            'AUDIT_VIEW', 'JOURNAL_VIEW', 'USER_MANAGE', 'TERMINAL_MANAGE',
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

    /**
     * What this user can do right now: the role's fixed set, plus FISCAL_CONFIGURATION_MANAGE for an ADMIN while the
     * store's fiscal configuration is unlocked. This, not forRole(), is what login/me report and the Gate checks.
     *
     * @return list<string>
     */
    public static function forUser(User $user): array
    {
        $capabilities = self::forRole($user->role);

        if (self::isUnlockedAdmin($user)) {
            $capabilities[] = self::UNLOCK_ONLY_CAPABILITY;
        }

        return $capabilities;
    }

    public static function hasForUser(User $user, string $capability): bool
    {
        if ($capability !== self::UNLOCK_ONLY_CAPABILITY) {
            return self::has($user->role, $capability);
        }

        return self::isUnlockedAdmin($user);
    }

    private static function isUnlockedAdmin(User $user): bool
    {
        return $user->role === 'ADMIN' && $user->store_id !== null && app(FiscalConfigurationLock::class)->isUnlocked($user->store_id);
    }

    /** Unknown roles are never granted anything -- forRole()'s empty-array fallback already guarantees this; has() just names the check. */
    public static function has(string $role, string $capability): bool
    {
        return in_array($capability, self::forRole($role), true);
    }
}
