<?php

// Prints "<sha256>  <file>" for every file of the license contract kernel (app/Services/License/Contract), sorted by name. Line endings are
// normalised first, so a Windows checkout and a Linux one agree. The control plane keeps a copy of these files (ADR-018), and this manifest
// is how both repositories notice that the copies have drifted: the client test fails when the kernel changes without the manifest, and the
// control plane's test fails when its copy differs from the manifest. To refresh after an intended change:
//   php scripts/license-kernel-manifest.php > tests/Fixtures/license-contract/kernel.sha256
// then copy the changed files and the manifest to the control plane in the same change.
$directory = $argv[1] ?? __DIR__.'/../app/Services/License/Contract';
$files = glob($directory.'/*.php');
sort($files);

foreach ($files as $file) {
    echo hash('sha256', str_replace("\r\n", "\n", (string) file_get_contents($file))).'  '.basename($file)."\n";
}
