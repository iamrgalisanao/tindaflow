<?php

namespace App\Services\Users;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who may act on whose account (ADR-018 decision 10). `USER_MANAGE` is a capability, not a ceiling: once a MANAGER holds
 * it, the server decides on the target's CURRENT role and the role it is asked to become, so a manager cannot create or
 * edit an administrator, elevate themselves, or deactivate an administrator or clear their approver code.
 *
 * - An ADMIN may do all of it.
 * - A MANAGER may act only on CASHIER and MANAGER accounts, as they are now and as they would become.
 * - Anyone else may do none of it (a defence in depth: the `can:USER_MANAGE` gate already stops them).
 * - A null actor is the console (`tindaflow:reset-password`, activation, seeders), which is trusted.
 *
 * Refusal is an AuthorizationException, which the API already renders as 403 AUTHORIZATION_DENIED.
 */
final class UserRolePolicy
{
    /** Roles a MANAGER may act on. */
    private const MANAGER_MAY_ACT_ON = ['CASHIER', 'MANAGER'];

    /** Creating an account with $requestedRole. */
    public function assertMayCreate(?User $actor, string $requestedRole): void
    {
        $this->assertAllowed($actor, [$requestedRole]);
    }

    /** Editing an account that has $currentRole and will have $requestedRole (the two differ when its role is changed). */
    public function assertMayChange(?User $actor, string $currentRole, string $requestedRole): void
    {
        $this->assertAllowed($actor, [$currentRole, $requestedRole]);
    }

    /** Deactivating, reactivating or clearing the override code of an account that has $targetRole. */
    public function assertMayActOn(?User $actor, string $targetRole): void
    {
        $this->assertAllowed($actor, [$targetRole]);
    }

    /** @param  list<string>  $roles */
    private function assertAllowed(?User $actor, array $roles): void
    {
        if ($actor === null || $actor->role === 'ADMIN') {
            return;
        }

        if ($actor->role === 'MANAGER' && array_diff($roles, self::MANAGER_MAY_ACT_ON) === []) {
            return;
        }

        throw new AuthorizationException('A manager can only manage cashier and manager accounts.');
    }
}
