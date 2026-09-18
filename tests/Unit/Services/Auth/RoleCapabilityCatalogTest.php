<?php

namespace Tests\Unit\Services\Auth;

use App\Services\Auth\RoleCapabilityCatalog;
use Tests\TestCase;

/** api-design.md's "Stage 6 default role->capability mapping" table, projected. */
class RoleCapabilityCatalogTest extends TestCase
{
    public function test_admin_has_every_capability(): void
    {
        $capabilities = RoleCapabilityCatalog::forRole('ADMIN');

        $this->assertCount(17, $capabilities);
        $this->assertContains('USER_MANAGE', $capabilities);
        $this->assertContains('TERMINAL_MANAGE', $capabilities);
        $this->assertContains('FISCAL_CONFIGURATION_MANAGE', $capabilities);
        $this->assertContains('STORE_SETTINGS_MANAGE', $capabilities);
    }

    public function test_manager_lacks_admin_only_capabilities(): void
    {
        $capabilities = RoleCapabilityCatalog::forRole('MANAGER');

        $this->assertContains('SALE_VOID_APPROVE', $capabilities);
        $this->assertContains('FISCAL_DAY_CLOSE', $capabilities);
        $this->assertNotContains('USER_MANAGE', $capabilities);
        $this->assertNotContains('TERMINAL_MANAGE', $capabilities);
        $this->assertNotContains('STORE_SETTINGS_MANAGE', $capabilities);
        $this->assertNotContains('FISCAL_CONFIGURATION_MANAGE', $capabilities);
    }

    public function test_cashier_has_only_the_request_only_capabilities(): void
    {
        $this->assertSame(['SALE_VOID', 'SALE_REFUND'], RoleCapabilityCatalog::forRole('CASHIER'));
    }

    public function test_unknown_role_yields_no_capabilities(): void
    {
        $this->assertSame([], RoleCapabilityCatalog::forRole('NOT_A_ROLE'));
    }

    // A2: the fixed vocabulary itself -- 17 entries, no duplicates.
    public function test_capabilities_constant_has_exactly_seventeen_unique_entries(): void
    {
        $this->assertCount(17, RoleCapabilityCatalog::CAPABILITIES);
        $this->assertCount(17, array_unique(RoleCapabilityCatalog::CAPABILITIES));
    }

    public function test_admin_is_defined_as_literally_every_capability(): void
    {
        $this->assertSame(RoleCapabilityCatalog::CAPABILITIES, RoleCapabilityCatalog::forRole('ADMIN'));
    }

    public function test_has_matches_for_role_for_every_role_and_capability(): void
    {
        foreach (['ADMIN', 'MANAGER', 'CASHIER', 'NOT_A_ROLE'] as $role) {
            foreach (RoleCapabilityCatalog::CAPABILITIES as $capability) {
                $this->assertSame(
                    in_array($capability, RoleCapabilityCatalog::forRole($role), true),
                    RoleCapabilityCatalog::has($role, $capability),
                    "has($role, $capability) disagreed with forRole($role)"
                );
            }
        }
    }

    public function test_unknown_role_has_no_capability_at_all(): void
    {
        foreach (RoleCapabilityCatalog::CAPABILITIES as $capability) {
            $this->assertFalse(RoleCapabilityCatalog::has('NOT_A_ROLE', $capability));
        }
    }
}
