<?php

namespace Tests\Database;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The first ADMIN's generated password is shown once, on the console. Console output treats `<...>` as a style tag and
 * drops a backslash before `<`, so a password containing those characters used to be shown altered (19 characters
 * instead of 20) and did not match the stored hash. What is shown must be exactly what was stored.
 */
class AdminUserSeederOutputTest extends PostgresSchemaTestCase
{
    /**
     * @return array{0: string, 1: string} the password the seeder used and the text the console showed
     */
    private function seedShowing(string $password): array
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

        return [$password, $buffer->fetch()];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function awkwardPasswords(): array
    {
        return [
            'a tag-like run' => ['ab<comment>cd1234567890'],
            'a backslash before a bracket' => ['ab\\<cd>ef1234567890'],
            'a trailing backslash' => ['abcdefghij1234567890\\'],
            'brackets and specials' => ['7g>fD<U;E?zF\\9B1!N<a>?'],
            'plain' => ['abcdefghij1234567890'],
        ];
    }

    #[DataProvider('awkwardPasswords')]
    public function test_the_password_shown_is_exactly_the_password_stored(string $password): void
    {
        [, $shown] = $this->seedShowing($password);

        $this->assertStringContainsString('Generated password (shown once, not stored anywhere): '.$password, $shown);

        $admin = User::where('role', 'ADMIN')->firstOrFail();
        $this->assertTrue(Hash::check($password, $admin->password_hash));
    }

    public function test_the_store_and_email_lines_are_still_shown(): void
    {
        [, $shown] = $this->seedShowing('abcdefghij1234567890');

        $this->assertStringContainsString("Created initial ADMIN user 'admin@example.com'", $shown);
    }
}
