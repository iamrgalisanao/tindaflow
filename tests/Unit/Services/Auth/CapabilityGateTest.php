<?php

namespace Tests\Unit\Services\Auth;

use App\Models\User;
use App\Services\Auth\RoleCapabilityCatalog;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * A2: one Gate ability per fixed capability (AppServiceProvider::boot()),
 * each backed by RoleCapabilityCatalog::has(). Uses plain, unsaved User
 * instances -- the Gate closure only reads $user->role, so no database
 * is needed to exercise the full allow/deny matrix.
 */
class CapabilityGateTest extends TestCase
{
    private function userWithRole(string $role): User
    {
        $user = new User(['role' => $role]);
        $user->id = 'test-user-'.$role;

        return $user;
    }

    public function test_gate_allows_admin_for_every_capability(): void
    {
        $admin = $this->userWithRole('ADMIN');

        foreach (RoleCapabilityCatalog::CAPABILITIES as $capability) {
            $this->assertTrue(Gate::forUser($admin)->allows($capability), "ADMIN should be allowed $capability");
        }
    }

    public function test_gate_matches_the_frozen_mapping_for_manager_and_cashier(): void
    {
        foreach (['MANAGER', 'CASHIER'] as $role) {
            $user = $this->userWithRole($role);

            foreach (RoleCapabilityCatalog::CAPABILITIES as $capability) {
                $expected = RoleCapabilityCatalog::has($role, $capability);

                $this->assertSame(
                    $expected,
                    Gate::forUser($user)->allows($capability),
                    "$role x $capability: Gate disagreed with RoleCapabilityCatalog"
                );
            }
        }
    }

    public function test_gate_denies_every_capability_for_an_unknown_role(): void
    {
        $user = $this->userWithRole('NOT_A_ROLE');

        foreach (RoleCapabilityCatalog::CAPABILITIES as $capability) {
            $this->assertFalse(Gate::forUser($user)->allows($capability));
        }

        // Confirms the fallback is "no capabilities", never a silent
        // upgrade to ADMIN/MANAGER-like access.
        $this->assertNotSame(RoleCapabilityCatalog::forRole('ADMIN'), RoleCapabilityCatalog::forRole('NOT_A_ROLE'));
    }

    // 12. UserSummary/Gate consistency: the exact same set, for every role.
    public function test_user_summary_capabilities_equal_the_gate_authorized_set_for_every_role(): void
    {
        foreach (['ADMIN', 'MANAGER', 'CASHIER'] as $role) {
            $user = $this->userWithRole($role);

            $gateAllowed = array_values(array_filter(
                RoleCapabilityCatalog::CAPABILITIES,
                fn (string $capability) => Gate::forUser($user)->allows($capability)
            ));

            $userSummaryCapabilities = RoleCapabilityCatalog::forRole($role);

            sort($gateAllowed);
            $sortedSummary = $userSummaryCapabilities;
            sort($sortedSummary);

            $this->assertSame($sortedSummary, $gateAllowed, "$role: UserSummary capabilities and Gate-authorized set diverged");
        }
    }
}
