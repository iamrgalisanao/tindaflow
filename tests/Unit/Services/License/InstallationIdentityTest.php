<?php

namespace Tests\Unit\Services\License;

use App\Services\License\InstallationIdentity;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * ADR-019 defect 7: the file holding this installation's private key is created readable by its owner only. Nothing restricts it
 * afterwards, so its final mode is the mode it was created with. File modes mean nothing on Windows; the Linux CI job checks them.
 */
class InstallationIdentityTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/tindaflow-test-license-'.uniqid('', true);
        config(['tindaflow.license.installation_file' => $this->directory.'/license/installation.json']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function mode(string $path): string
    {
        clearstatcache();

        return decoct(fileperms($path) & 0777);
    }

    public function test_the_identity_is_made_once_and_the_process_umask_is_left_as_it_was(): void
    {
        $before = umask();

        $first = app(InstallationIdentity::class)->ensure();

        $this->assertSame($first, app(InstallationIdentity::class)->ensure());
        $this->assertSame($before, umask());
        $this->assertFileDoesNotExist(app(InstallationIdentity::class)->path().'.tmp');
    }

    public function test_the_key_file_and_its_folder_are_for_the_owner_only(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Windows has no POSIX file modes.');
        }
        $before = umask(0022);

        try {
            app(InstallationIdentity::class)->ensure();
        } finally {
            umask($before);
        }

        $this->assertSame('600', $this->mode(app(InstallationIdentity::class)->path()));
        $this->assertSame('700', $this->mode(dirname(app(InstallationIdentity::class)->path())));
    }

    public function test_a_file_left_over_from_an_interrupted_run_does_not_lend_its_permissions(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Windows has no POSIX file modes.');
        }
        $path = app(InstallationIdentity::class)->path();
        mkdir(dirname($path), 0700, true);
        file_put_contents($path.'.tmp', 'left over');
        chmod($path.'.tmp', 0644);

        app(InstallationIdentity::class)->ensure();

        $this->assertSame('600', $this->mode($path));
    }
}
