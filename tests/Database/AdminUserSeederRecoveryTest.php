<?php

namespace Tests\Database;

use App\Models\Store;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The seeder skips a store that has an ACTIVE administrator, and recovers one that has none: it used to skip on any
 * ADMIN row, so a store whose only administrator was inactive could never get its first-deploy admin back.
 */
class AdminUserSeederRecoveryTest extends PostgresSchemaTestCase
{
    private const STORE_NAME = 'Recovery Test Store';

    private const EMAIL = 'owner@recovery.test';

    /** @var array<string, string|null> */
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setEnv('TINDAFLOW_INITIAL_STORE_NAME', self::STORE_NAME);
        $this->setEnv('TINDAFLOW_INITIAL_ADMIN_EMAIL', self::EMAIL);
        $this->setEnv('TINDAFLOW_INITIAL_ADMIN_PASSWORD', '');
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }

        parent::tearDown();
    }

    private function setEnv(string $key, string $value): void
    {
        if (! array_key_exists($key, $this->previous)) {
            $this->previous[$key] = $_ENV[$key] ?? null;
        }
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    /** @return string what the seeder printed */
    private function runSeeder(string $password = 'Fresh-Recovery-Password-1'): string
    {
        $seeder = new class($password) extends AdminUserSeeder
        {
            public function __construct(private string $fixedPassword) {}

            protected function generatePassword(): string
            {
                return $this->fixedPassword;
            }
        };

        $buffer = new BufferedOutput;
        $command = new class extends Command {};
        $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));
        $seeder->setCommand($command);
        $seeder->run();

        return $buffer->fetch();
    }

    private function store(): Store
    {
        return Store::factory()->create(['name' => self::STORE_NAME]);
    }

    public function test_a_store_with_an_active_admin_is_left_alone(): void
    {
        $store = $this->store();
        $admin = User::factory()->admin()->create(['store_id' => $store->id, 'email' => 'someone@recovery.test']);

        $output = $this->runSeeder();

        $this->assertStringContainsString('active ADMIN user already exists', $output);
        $this->assertSame(1, User::where('store_id', $store->id)->count());
        $this->assertTrue($admin->refresh()->active);
    }

    public function test_a_store_with_only_an_inactive_admin_gets_the_configured_admin_created(): void
    {
        $store = $this->store();
        $old = User::factory()->admin()->inactive()->create(['store_id' => $store->id, 'email' => 'former@recovery.test']);

        $this->runSeeder('Brand-New-Admin-Password-2');

        $created = User::where('store_id', $store->id)->where('email', self::EMAIL)->firstOrFail();
        $this->assertSame('ADMIN', $created->role);
        $this->assertTrue($created->active);
        $this->assertTrue(Hash::check('Brand-New-Admin-Password-2', $created->password_hash));
        $this->assertFalse($old->refresh()->active, 'the earlier administrator stays deactivated');
    }

    public function test_the_configured_account_is_reactivated_when_it_exists_as_an_inactive_admin(): void
    {
        $store = $this->store();
        $old = User::factory()->admin()->inactive()->create(['store_id' => $store->id, 'email' => self::EMAIL]);
        $oldHash = $old->password_hash;

        $output = $this->runSeeder('Recovered-Admin-Password-3');

        $this->assertStringContainsString('reactivated ADMIN user', $output);
        $old->refresh();
        $this->assertTrue($old->active);
        $this->assertSame('ADMIN', $old->role);
        $this->assertNotSame($oldHash, $old->password_hash);
        $this->assertTrue(Hash::check('Recovered-Admin-Password-3', $old->password_hash));
        $this->assertSame(1, User::where('store_id', $store->id)->count());
    }

    public function test_the_email_match_ignores_letter_case(): void
    {
        $store = $this->store();
        $old = User::factory()->admin()->inactive()->create(['store_id' => $store->id, 'email' => 'Owner@Recovery.TEST']);

        $this->runSeeder();

        $this->assertTrue($old->refresh()->active);
        $this->assertSame(1, User::where('store_id', $store->id)->count());
    }

    public function test_an_email_that_belongs_to_a_non_admin_is_never_taken_over(): void
    {
        $store = $this->store();
        $manager = User::factory()->manager()->inactive()->create(['store_id' => $store->id, 'email' => self::EMAIL]);
        $hash = $manager->password_hash;

        $output = $this->runSeeder();

        $this->assertStringContainsString('already belongs to a MANAGER user', $output);
        $manager->refresh();
        $this->assertSame('MANAGER', $manager->role);
        $this->assertFalse($manager->active);
        $this->assertSame($hash, $manager->password_hash);
        $this->assertSame(0, User::where('store_id', $store->id)->where('role', 'ADMIN')->count());
    }
}
