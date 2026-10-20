<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ends a user's signed-in browsers (their `sessions` rows). One place for it, so the server-console password reset and an
 * administrator changing a password in the Users screen cannot drift apart: a lost or borrowed till must not stay signed in
 * after the password it used is changed. Only the database session driver stores sessions this way; with any other driver there is
 * nothing to delete here.
 */
final class UserSessions
{
    /**
     * @param  string|null  $exceptSessionId  a session to keep, so an administrator who changes their own password is not signed out of the screen they are using
     * @return int how many sessions were ended
     */
    public function endFor(string $userId, ?string $exceptSessionId = null): int
    {
        if (config('session.driver') !== 'database' || ! Schema::hasTable('sessions')) {
            return 0;
        }

        return DB::table('sessions')
            ->where('user_id', $userId)
            ->when($exceptSessionId !== null, fn ($query) => $query->where('id', '!=', $exceptSessionId))
            ->delete();
    }
}
