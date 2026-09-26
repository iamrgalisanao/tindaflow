<?php

namespace App\Console\Commands;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Recovery for a user who cannot sign in, run by whoever administers the server (`docker compose exec app php artisan
 * tindaflow:reset-password owner@example.com`). There is no e-mail in a small shop's server, so no "forgot password" link
 * exists; and a sole administrator has nobody else who could reset the password from Users. Being able to run this
 * command already means having the server, which is the same trust as owning the database.
 *
 * It sets a freshly generated password, prints it once (never stored anywhere in plaintext), ends every session the user
 * has, and records a PASSWORD_RESET audit event that says who was reset and never carries the password.
 */
class ResetUserPassword extends Command
{
    protected $signature = 'tindaflow:reset-password
        {email : The e-mail the user signs in with}
        {--activate : Also reactivate the user if they are inactive}';

    protected $description = 'Set a new generated password for a user who cannot sign in, and end their sessions';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $matches = User::query()->whereRaw('LOWER(email) = ?', [$email])->get();

        if ($matches->isEmpty()) {
            $this->error("No user has the e-mail '{$email}'.");

            return self::FAILURE;
        }

        if ($matches->count() > 1) {
            $this->error("More than one store has a user with the e-mail '{$email}', so it is not clear which one to reset. Nothing was changed.");

            return self::FAILURE;
        }

        $user = $matches->first();

        if (! $user->active && ! $this->option('activate')) {
            $this->error("'{$user->email}' is inactive, so signing in would still be refused. Nothing was changed. Run it again with --activate to reactivate the user as well.");

            return self::FAILURE;
        }

        $password = $this->newPassword();
        $reactivated = ! $user->active;

        DB::transaction(function () use ($user, $password, $reactivated) {
            $user->forceFill(['password_hash' => Hash::make($password), 'active' => true])->save();

            // Signed-in browsers of this user must not survive a password reset (a lost or borrowed till, for instance).
            if (config('session.driver') === 'database' && Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }

            AuditEvent::create([
                'store_id' => $user->store_id,
                'event_type' => 'PASSWORD_RESET',
                'actor_user_id' => null, // run from the server console, not by a signed-in user
                'entity_type' => 'user',
                'entity_id' => $user->id,
                'after_metadata' => ['email' => $user->email, 'role' => $user->role, 'reactivated' => $reactivated, 'via' => 'console'],
            ]);
        });

        $this->info("Password reset for '{$user->email}' ({$user->role})".($reactivated ? ' and the user was reactivated' : '').'. Their sessions were ended.');
        // Written RAW: the console treats `<...>` as style tags, so a generated password containing one would be shown altered.
        $this->getOutput()->writeln("New password (shown once, not stored anywhere): {$password}", OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    /** Its own method so a test can choose the password and prove the raw printing (a random one almost never trips it). */
    protected function newPassword(): string
    {
        return Str::password(20);
    }
}
