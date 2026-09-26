<?php

namespace Tests\Database;

use App\Console\Commands\ResetUserPassword;
use App\Models\AuditEvent;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * `tindaflow:reset-password`: the recovery for a sole administrator who has forgotten the password (a small shop's server
 * has no e-mail, and nobody else could reset it from Users).
 */
class ResetUserPasswordCommandTest extends PostgresSchemaTestCase
{
    /** @return array{status: int, output: string, password: string|null} */
    private function reset(string $email, array $options = []): array
    {
        $status = Artisan::call('tindaflow:reset-password', ['email' => $email] + $options);
        $output = Artisan::output();
        preg_match('/New password \(shown once, not stored anywhere\): (.+)$/m', $output, $found);

        return ['status' => $status, 'output' => $output, 'password' => $found[1] ?? null];
    }

    public function test_it_sets_a_new_password_the_user_can_sign_in_with_and_the_old_one_no_longer_works(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'owner@shop.test']);

        $result = $this->reset('owner@shop.test');

        $this->assertSame(0, $result['status']);
        $this->assertNotNull($result['password'], 'the new password is printed');
        $this->assertGreaterThanOrEqual(20, strlen($result['password']));
        $this->assertTrue(Hash::check($result['password'], $admin->fresh()->password_hash), 'the printed password is exactly the one stored, whatever characters it holds');
        $this->postJson('/api/v1/auth/login', ['email' => 'owner@shop.test', 'password' => $result['password']])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'owner@shop.test', 'password' => 'password'])->assertStatus(401);
    }

    public function test_it_ends_every_session_of_that_user_and_only_theirs(): void
    {
        config(['session.driver' => 'database']);
        $admin = User::factory()->admin()->create(['email' => 'owner@shop.test']);
        $other = User::factory()->create();
        foreach ([['a1', $admin->id], ['a2', $admin->id], ['b1', $other->id]] as [$id, $userId]) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'ip_address' => '127.0.0.1', 'user_agent' => 't', 'payload' => 'x', 'last_activity' => time()]);
        }

        $this->reset('owner@shop.test');

        $this->assertSame(0, DB::table('sessions')->where('user_id', $admin->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count());
    }

    public function test_it_records_an_audit_event_that_never_carries_the_password(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'owner@shop.test']);

        $result = $this->reset('owner@shop.test');

        $event = AuditEvent::where('event_type', 'PASSWORD_RESET')->sole();
        $this->assertSame($admin->id, $event->entity_id);
        $this->assertSame($admin->store_id, $event->store_id);
        $this->assertNull($event->actor_user_id, 'it is run from the server console, not by a signed-in user');
        $this->assertSame('owner@shop.test', $event->after_metadata['email']);
        $this->assertStringNotContainsString($result['password'], json_encode($event->toArray()));
    }

    public function test_the_e_mail_is_matched_without_regard_to_case(): void
    {
        User::factory()->admin()->create(['email' => 'owner@shop.test']);

        $this->assertSame(0, $this->reset('  Owner@Shop.TEST ')['status']);
    }

    public function test_an_unknown_e_mail_changes_nothing_and_fails(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'owner@shop.test']);
        $hash = $admin->password_hash;

        $result = $this->reset('nobody@shop.test');

        $this->assertSame(1, $result['status']);
        $this->assertNull($result['password']);
        $this->assertSame($hash, $admin->fresh()->password_hash);
        $this->assertSame(0, AuditEvent::where('event_type', 'PASSWORD_RESET')->count());
    }

    public function test_an_inactive_user_is_refused_unless_told_to_reactivate_them(): void
    {
        $user = User::factory()->create(['email' => 'gone@shop.test', 'active' => false]);
        $hash = $user->password_hash;

        $refused = $this->reset('gone@shop.test');
        $this->assertSame(1, $refused['status']);
        $this->assertNull($refused['password']);
        $this->assertSame($hash, $user->fresh()->password_hash);
        $this->assertFalse($user->fresh()->active);

        $allowed = $this->reset('gone@shop.test', ['--activate' => true]);
        $this->assertSame(0, $allowed['status']);
        $this->assertTrue($user->fresh()->active);
        $this->assertTrue(AuditEvent::where('event_type', 'PASSWORD_RESET')->sole()->after_metadata['reactivated']);
    }

    public function test_an_e_mail_used_in_two_stores_is_refused_because_it_is_not_clear_which_to_reset(): void
    {
        $first = User::factory()->create(['email' => 'same@shop.test']);
        $second = User::factory()->create(['email' => 'same@shop.test', 'store_id' => Store::factory()->create()->id]);

        $result = $this->reset('same@shop.test');

        $this->assertSame(1, $result['status']);
        $this->assertNull($result['password']);
        $this->assertSame($first->password_hash, $first->fresh()->password_hash);
        $this->assertSame($second->password_hash, $second->fresh()->password_hash);
    }

    /**
     * The console treats `<...>` as a style tag and a backslash before `<` as an escape, so a password printed through the
     * formatter comes out altered and locks the administrator out. A generated password rarely looks like a tag, so the
     * command's password is chosen here: it holds real style tags, an escaped bracket and a lone backslash.
     */
    public function test_a_password_that_looks_like_console_markup_is_printed_exactly(): void
    {
        $trouble = 'a<b>c<info>d</info>e\\<f>g\\h<>i';
        $command = new class extends ResetUserPassword
        {
            public string $chosen = '';

            protected function newPassword(): string
            {
                return $this->chosen;
            }
        };
        $command->chosen = $trouble;
        Artisan::registerCommand($command);
        $admin = User::factory()->admin()->create(['email' => 'owner@shop.test']);

        $printed = $this->reset('owner@shop.test')['password'];

        $this->assertSame($trouble, $printed, 'the console must not interpret the password as markup');
        $this->assertTrue(Hash::check($trouble, $admin->fresh()->password_hash));
    }
}
