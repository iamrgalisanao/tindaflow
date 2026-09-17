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
}
