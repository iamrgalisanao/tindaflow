<?php

namespace App\Services\Users;

use App\Domain\Exceptions\UserNotFoundException;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Auth\UserSessions;
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
 *
 * Every change is audited in the same transaction as the change, with the signed-in administrator as the actor: who was
 * created, whose name, e-mail or role changed (before and after), whose password was changed (never the password itself, nor
 * its hash), who was deactivated or reactivated. A password change or a deactivation also ends that user's signed-in
 * browsers at once. A role change does not: the role is read from the database on every request, so it takes effect on the
 * user's next click, and signing them out would only throw away a cashier's open cart.
 */
final class UserService
{
    public function __construct(private readonly UserSessions $sessions) {}

    /**
     * `password` is optional in openapi.yaml's UserInput. Without one the account gets an unguessable
     * random hash, so it cannot sign in until an administrator sets a password with userUpdate.
     *
     * @param  array<string, mixed>  $data  the already-validated UserInput
     */
    public function create(string $storeId, array $data, ?User $actor = null): User
    {
        try {
            return DB::transaction(function () use ($storeId, $data, $actor) {
                $user = User::create([
                    'store_id' => $storeId,
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'role' => $data['role'],
                    'active' => true,
                    'password_hash' => Hash::make($data['password'] ?? Str::random(64)),
                ]);

                $this->audit($user, 'USER_CREATED', $actor, null, [
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'password_set' => ! empty($data['password']),
                ]);

                return $user;
            });
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
     * @param  string|null  $currentSessionId  the session the administrator is using, kept if they change their own password
     */
    public function update(string $storeId, string $userId, array $data, ?User $actor = null, ?string $currentSessionId = null): User
    {
        try {
            return DB::transaction(function () use ($storeId, $userId, $data, $actor, $currentSessionId) {
                $activeAdmins = $this->lockActiveAdmins($storeId);
                $user = $this->lock($storeId, $userId);

                if ($user->role === 'ADMIN' && $user->active && $data['role'] !== 'ADMIN' && $this->isLastAdmin($activeAdmins, $user)) {
                    throw ValidationException::withMessages(['role' => 'The last active administrator cannot be changed to another role.']);
                }

                $before = ['name' => $user->name, 'email' => $user->email, 'role' => $user->role];

                $user->name = $data['name'];
                $user->email = $data['email'];
                $user->role = $data['role'];

                $passwordChanged = ! empty($data['password']);
                if ($passwordChanged) {
                    $user->password_hash = Hash::make($data['password']);
                }

                $user->save();

                $after = ['name' => $user->name, 'email' => $user->email, 'role' => $user->role];
                if ($after !== $before) {
                    $this->audit($user, 'USER_UPDATED', $actor, $before, $after);
                }

                if ($passwordChanged) {
                    // An administrator changing their OWN password keeps the screen they are on; every other browser of the
                    // user is signed out, as is every browser of anyone else whose password was changed.
                    $kept = $actor?->id === $user->id ? $currentSessionId : null;
                    $ended = $this->sessions->endFor($user->id, $kept);
                    $this->audit($user, 'USER_PASSWORD_CHANGED', $actor, null, ['email' => $user->email, 'sessions_ended' => $ended]);
                }

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
    public function setActive(string $storeId, string $userId, bool $active, ?User $actor = null): User
    {
        return DB::transaction(function () use ($storeId, $userId, $active, $actor) {
            $activeAdmins = $active ? collect() : $this->lockActiveAdmins($storeId);
            $user = $this->lock($storeId, $userId);

            if (! $active && $user->active && $user->role === 'ADMIN' && $this->isLastAdmin($activeAdmins, $user)) {
                throw ValidationException::withMessages(['active' => 'The last active administrator cannot be deactivated.']);
            }

            if ($user->active !== $active) {
                $user->active = $active;
                $user->save();

                $ended = $active ? 0 : $this->sessions->endFor($user->id);
                $this->audit($user, $active ? 'USER_ACTIVATED' : 'USER_DEACTIVATED', $actor, ['active' => ! $active], ['active' => $active, 'email' => $user->email] + ($active ? [] : ['sessions_ended' => $ended]));
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

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>  $after  never a password or hash
     */
    private function audit(User $user, string $eventType, ?User $actor, ?array $before, array $after): void
    {
        AuditEvent::create([
            'store_id' => $user->store_id,
            'event_type' => $eventType,
            'actor_user_id' => $actor?->id,
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'before_metadata' => $before,
            'after_metadata' => $after,
        ]);
    }

    /** Two concurrent requests can both pass the FormRequest check; the (store_id, email) unique index is the backstop. */
    private function duplicateEmail(): ValidationException
    {
        return ValidationException::withMessages(['email' => 'This email is already used by another user.']);
    }
}
