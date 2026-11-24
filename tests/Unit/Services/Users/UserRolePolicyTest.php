<?php

namespace Tests\Unit\Services\Users;

use App\Models\User;
use App\Services\Users\UserRolePolicy;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ADR-018 decision 10: once a MANAGER holds USER_MANAGE, the server (not the screen) decides who may act on whom. A
 * MANAGER may act only on CASHIER and MANAGER accounts, both as they are now and as they are asked to become; only an
 * ADMIN may create, elevate to, edit, activate or deactivate an ADMIN. A null actor is the console, which is trusted.
 */
class UserRolePolicyTest extends TestCase
{
    private function actor(?string $role): ?User
    {
        return $role === null ? null : (new User)->forceFill(['role' => $role]);
    }

    /** @return array<string, array{0: ?string, 1: string, 2: bool}> */
    public static function createMatrix(): array
    {
        return [
            'console creates an admin' => [null, 'ADMIN', true],
            'admin creates an admin' => ['ADMIN', 'ADMIN', true],
            'admin creates a manager' => ['ADMIN', 'MANAGER', true],
            'manager creates a manager' => ['MANAGER', 'MANAGER', true],
            'manager creates a cashier' => ['MANAGER', 'CASHIER', true],
            'manager cannot create an admin' => ['MANAGER', 'ADMIN', false],
            'cashier cannot create anyone' => ['CASHIER', 'CASHIER', false],
        ];
    }

    #[DataProvider('createMatrix')]
    public function test_who_may_create_which_role(?string $actorRole, string $requestedRole, bool $allowed): void
    {
        if (! $allowed) {
            $this->expectException(AuthorizationException::class);
        }

        (new UserRolePolicy)->assertMayCreate($this->actor($actorRole), $requestedRole);

        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{0: ?string, 1: string, 2: string, 3: bool}> */
    public static function changeMatrix(): array
    {
        return [
            'console edits an admin' => [null, 'ADMIN', 'ADMIN', true],
            'admin edits an admin' => ['ADMIN', 'ADMIN', 'ADMIN', true],
            'admin promotes a cashier to admin' => ['ADMIN', 'CASHIER', 'ADMIN', true],
            'manager edits a cashier' => ['MANAGER', 'CASHIER', 'CASHIER', true],
            'manager promotes a cashier to manager' => ['MANAGER', 'CASHIER', 'MANAGER', true],
            'manager demotes a manager to cashier' => ['MANAGER', 'MANAGER', 'CASHIER', true],
            'manager cannot elevate a cashier to admin' => ['MANAGER', 'CASHIER', 'ADMIN', false],
            'manager cannot elevate themselves to admin' => ['MANAGER', 'MANAGER', 'ADMIN', false],
            'manager cannot edit an admin' => ['MANAGER', 'ADMIN', 'ADMIN', false],
            'manager cannot demote an admin' => ['MANAGER', 'ADMIN', 'CASHIER', false],
            'cashier cannot edit anyone' => ['CASHIER', 'CASHIER', 'CASHIER', false],
        ];
    }

    #[DataProvider('changeMatrix')]
    public function test_who_may_change_which_role_to_which(?string $actorRole, string $currentRole, string $requestedRole, bool $allowed): void
    {
        if (! $allowed) {
            $this->expectException(AuthorizationException::class);
        }

        (new UserRolePolicy)->assertMayChange($this->actor($actorRole), $currentRole, $requestedRole);

        $this->addToAssertionCount(1);
    }

    /** @return array<string, array{0: ?string, 1: string, 2: bool}> */
    public static function actOnMatrix(): array
    {
        return [
            'console acts on an admin' => [null, 'ADMIN', true],
            'admin acts on an admin' => ['ADMIN', 'ADMIN', true],
            'manager acts on a manager' => ['MANAGER', 'MANAGER', true],
            'manager acts on a cashier' => ['MANAGER', 'CASHIER', true],
            'manager cannot act on an admin' => ['MANAGER', 'ADMIN', false],
            'cashier cannot act on a cashier' => ['CASHIER', 'CASHIER', false],
        ];
    }

    #[DataProvider('actOnMatrix')]
    public function test_who_may_deactivate_activate_or_reset_the_override_code_of_which_role(?string $actorRole, string $targetRole, bool $allowed): void
    {
        if (! $allowed) {
            $this->expectException(AuthorizationException::class);
        }

        (new UserRolePolicy)->assertMayActOn($this->actor($actorRole), $targetRole);

        $this->addToAssertionCount(1);
    }

    public function test_the_refusal_says_what_a_manager_may_do(): void
    {
        try {
            (new UserRolePolicy)->assertMayCreate($this->actor('MANAGER'), 'ADMIN');
            $this->fail('A manager creating an administrator must be refused.');
        } catch (AuthorizationException $e) {
            $this->assertStringContainsString('cashier and manager', $e->getMessage());
        }
    }
}
