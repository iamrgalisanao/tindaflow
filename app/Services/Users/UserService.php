<?php

namespace App\Services\Users;

use App\Domain\Exceptions\UserNotFoundException;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Admin surface for `users` (userList/Create/Get/Update/Deactivate, plus the forward-committed
 * userActivate). Users are never deleted -- historical audit and sale records reference them --
 * so retirement is `active = false`, and an inactive user's session ends on its next request
 * (EnsureUserIsActive).
 */
final class UserService
{
    /**
     * `password` is optional in openapi.yaml's UserInput. Without one the account gets an unguessable
     * random hash, so it cannot sign in until an administrator sets a password with userUpdate.
     *
     * @param  array<string, mixed>  $data  the already-validated UserInput
     */
    public function create(string $storeId, array $data): User
    {
        try {
            return User::create([
                'store_id' => $storeId,
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => $data['role'],
                'active' => true,
                'password_hash' => Hash::make($data['password'] ?? Str::random(64)),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateEmail();
        }
    }

    public function find(string $storeId, string $userId): User
    {
        $user = User::where('store_id', $storeId)->find($userId);

        if ($user === null) {
            throw UserNotFoundException::forId($userId);
        }

        return $user;
    }

    /**
     * A blank password leaves the current one unchanged. The last active ADMIN cannot be moved to
     * another role (reported as a `role` field error): that would leave the store with nobody able
     * to manage users. The check locks the store's active admins so two concurrent demotions cannot
     * both pass it.
     *
     * @param  array<string, mixed>  $data  the already-validated UserInput
     */
    public function update(string $storeId, string $userId, array $data): User
    {
        try {
            return DB::transaction(function () use ($storeId, $userId, $data) {
                $activeAdmins = $this->lockActiveAdmins($storeId);
                $user = $this->lock($storeId, $userId);

                if ($user->role === 'ADMIN' && $user->active && $data['role'] !== 'ADMIN' && $this->isLastAdmin($activeAdmins, $user)) {
                    throw ValidationException::withMessages(['role' => 'The last active administrator cannot be changed to another role.']);
                }

                $user->name = $data['name'];
                $user->email = $data['email'];
                $user->role = $data['role'];

                if (! empty($data['password'])) {
                    $user->password_hash = Hash::make($data['password']);
                }

                $user->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateEmail();
        }
    }

    /**
     * Idempotent: setting the state a user is already in returns them unchanged. The last active ADMIN cannot
     * be deactivated (reported as an `active` field error, the same 422 VALIDATION_FAILED shape as the role
     * rule in update()): the store would have nobody able to manage users or reactivate anyone. The active
     * admins are locked first, so two admins deactivating each other at once cannot both pass the check.
     */
    public function setActive(string $storeId, string $userId, bool $active): User
    {
        return DB::transaction(function () use ($storeId, $userId, $active) {
            $activeAdmins = $active ? collect() : $this->lockActiveAdmins($storeId);
            $user = $this->lock($storeId, $userId);

            if (! $active && $user->active && $user->role === 'ADMIN' && $this->isLastAdmin($activeAdmins, $user)) {
                throw ValidationException::withMessages(['active' => 'The last active administrator cannot be deactivated.']);
            }

            if ($user->active !== $active) {
                $user->active = $active;
                $user->save();
            }

            return $user;
        });
    }

    /**
     * Locks every active administrator of the store, in id order, before any single user row is locked. One
     * fixed order for every operation that needs the "is this the last admin" answer, so two of them cannot
     * deadlock each other or both see the other admin as still remaining.
     *
     * @return Collection<int, string> the ids of the store's active administrators
     */
    private function lockActiveAdmins(string $storeId): Collection
    {
        return User::where('store_id', $storeId)
            ->where('role', 'ADMIN')
            ->where('active', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');
    }

    /** True when $user is an active administrator and no other active administrator exists in $activeAdmins. */
    private function isLastAdmin(Collection $activeAdmins, User $user): bool
    {
        return $activeAdmins->reject(fn (string $id): bool => $id === $user->id)->isEmpty();
    }

    private function lock(string $storeId, string $userId): User
    {
        $user = User::where('store_id', $storeId)->lockForUpdate()->find($userId);

        if ($user === null) {
            throw UserNotFoundException::forId($userId);
        }

        return $user;
    }

    /** Two concurrent requests can both pass the FormRequest check; the (store_id, email) unique index is the backstop. */
    private function duplicateEmail(): ValidationException
    {
        return ValidationException::withMessages(['email' => 'This email is already used by another user.']);
    }
}
