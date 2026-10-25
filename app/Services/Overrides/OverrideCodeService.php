<?php

namespace App\Services\Overrides;

use App\Domain\Exceptions\UserNotFoundException;
use App\Models\User;
use App\Models\UserOverrideCode;
use App\Services\Auth\RoleCapabilityCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * An approver's credit-limit override code (ADR-016 section 6): set by the approver themselves from their own signed-in session after
 * re-entering their account password, or cleared by an administrator (who never learns or chooses it: the approver then sets a new one).
 * The code is stored only as a slow hash, must differ from the account password and must not be trivially guessable. Nothing here ever
 * returns, logs or audits a code or a hash.
 */
final class OverrideCodeService
{
    public const CAPABILITY = 'CREDIT_LIMIT_OVERRIDE';

    public function __construct(private readonly OverrideAuditor $auditor) {}

    /** Roles whose fixed capability set includes the override. */
    public static function approverRoles(): array
    {
        return array_values(array_filter(['ADMIN', 'MANAGER', 'CASHIER'], fn (string $role): bool => RoleCapabilityCatalog::has($role, self::CAPABILITY)));
    }

    /**
     * The approvers a cashier can pick on the till: active users of the store who hold the capability and have a code. Names only; the
     * signed-in user is left out (nobody approves their own sale).
     *
     * @return Collection<int, array{id: string, name: string}>
     */
    public function approversFor(User $viewer): Collection
    {
        return User::query()
            ->join('user_override_codes', 'user_override_codes.user_id', '=', 'users.id')
            ->where('users.store_id', $viewer->store_id)
            ->where('users.active', true)
            ->whereIn('users.role', self::approverRoles())
            ->whereNotNull('user_override_codes.code_hash')
            ->where('users.id', '!=', $viewer->id)
            ->orderBy('users.name')->orderBy('users.id')
            ->get(['users.id', 'users.name'])
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name]);
    }

    /** @return array{user_id: string, has_code: bool, locked: bool, locked_until: string|null, set_at: string|null} */
    public function statusOf(User $user): array
    {
        return $this->statusFromRow($user->id, UserOverrideCode::find($user->id));
    }

    /**
     * Every user of the store who could hold a code, with whether they do (the administrator's list).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function statusesFor(User $admin): Collection
    {
        $rows = UserOverrideCode::where('store_id', $admin->store_id)->get()->keyBy('user_id');

        return User::where('store_id', $admin->store_id)->whereIn('role', self::approverRoles())->orderBy('name')->orderBy('id')->get()
            ->map(fn (User $user): array => ['name' => $user->name, 'role' => $user->role, 'active' => $user->active] + $this->statusFromRow($user->id, $rows->get($user->id)));
    }

    /**
     * An approver sets (or changes) their OWN code. The account password is re-entered; a wrong one is a field error on `password`.
     * Setting a code also clears a lockout and the failed-attempt count: the person proved who they are with the password.
     */
    public function setOwn(User $actor, string $password, string $code): array
    {
        if (! RoleCapabilityCatalog::hasForUser($actor, self::CAPABILITY)) {
            throw ValidationException::withMessages(['code' => 'Your role cannot approve credit-limit overrides.']);
        }
        $this->assertPassword($actor, $password);

        $weak = OverrideCodePolicy::violation($code);
        if ($weak !== null) {
            throw ValidationException::withMessages(['code' => $weak]);
        }
        if (Hash::check($code, $actor->password_hash)) {
            throw ValidationException::withMessages(['code' => 'The code must be different from your account password.']);
        }

        DB::transaction(function () use ($actor, $code): void {
            $row = UserOverrideCode::lockForUpdate()->find($actor->id);
            $hadCode = $row?->hasCode() ?? false;
            $values = ['code_hash' => Hash::make($code), 'failed_attempts' => 0, 'locked_until' => null, 'set_at' => now(), 'set_by' => $actor->id];

            if ($row === null) {
                UserOverrideCode::create(['user_id' => $actor->id, 'store_id' => $actor->store_id] + $values);
            } else {
                $row->forceFill($values)->save();
            }

            $this->auditor->codeSet($actor, $hadCode);
        });

        return $this->statusOf($actor);
    }

    /**
     * An administrator clears an approver's code (and any lockout). The administrator re-enters their own password. The approver then
     * sets a new code themselves, so the administrator never knows it. A user with no code row has nothing to clear and nothing is written.
     */
    public function resetByAdmin(User $admin, string $adminPassword, string $targetUserId): array
    {
        $this->assertPassword($admin, $adminPassword);

        $target = User::where('store_id', $admin->store_id)->find($targetUserId) ?? throw UserNotFoundException::forId($targetUserId);

        DB::transaction(function () use ($admin, $target): void {
            $row = UserOverrideCode::lockForUpdate()->find($target->id);
            if ($row === null) {
                return;
            }
            $hadCode = $row->hasCode();
            $wasLocked = $row->isLockedAt(now());
            $row->forceFill(['code_hash' => null, 'failed_attempts' => 0, 'locked_until' => null, 'set_at' => null, 'set_by' => $admin->id])->save();

            $this->auditor->codeReset($admin, $target, $hadCode, $wasLocked);
        });

        return $this->statusOf($target);
    }

    private function assertPassword(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password_hash)) {
            throw ValidationException::withMessages(['password' => 'That password is not right.']);
        }
    }

    /** @return array{user_id: string, has_code: bool, locked: bool, locked_until: string|null, set_at: string|null} */
    private function statusFromRow(string $userId, ?UserOverrideCode $row): array
    {
        $locked = $row?->isLockedAt(now()) ?? false;

        return [
            'user_id' => $userId,
            'has_code' => $row?->hasCode() ?? false,
            'locked' => $locked,
            'locked_until' => $locked ? $row->locked_until->toIso8601String() : null,
            'set_at' => $row?->set_at?->toIso8601String(),
        ];
    }
}
