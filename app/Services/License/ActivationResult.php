<?php

namespace App\Services\License;

use App\Models\Store;
use App\Models\Terminal;
use App\Models\User;
use App\Services\License\Contract\Certificate;

/** What an activation produced. On an idempotent re-run (`alreadyActive`) nothing was created, so the admin, terminal and password are null. */
final class ActivationResult
{
    public function __construct(
        public readonly Store $store,
        public readonly ?User $admin,
        public readonly ?Terminal $terminal,
        public readonly ?string $adminPassword,
        public readonly bool $alreadyActive,
        public readonly Certificate $certificate,
    ) {}
}
