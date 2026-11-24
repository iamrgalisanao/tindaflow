<?php

namespace Tests\Unit\Services\License\Contract;

use PHPUnit\Framework\TestCase;

/**
 * The control plane keeps a copy of the contract kernel (ADR-018). Changing a kernel file without refreshing the manifest, and so
 * without carrying the change to the control plane, fails here instead of surfacing as a certificate one side will not accept.
 */
class KernelManifestTest extends TestCase
{
    public function test_the_manifest_matches_the_kernel_files(): void
    {
        $root = __DIR__.'/../../../../..';
        $actual = (string) shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/scripts/license-kernel-manifest.php'));
        $recorded = str_replace("\r\n", "\n", (string) file_get_contents($root.'/tests/Fixtures/license-contract/kernel.sha256'));

        $this->assertSame(
            $recorded,
            str_replace("\r\n", "\n", $actual),
            "The license contract kernel changed. Refresh the manifest (php scripts/license-kernel-manifest.php > tests/Fixtures/license-contract/kernel.sha256) and carry the change to the control plane's copy.",
        );
    }
}
