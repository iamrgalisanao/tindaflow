<?php

namespace App\Services\Auth;

use App\Models\Store;
use App\Models\Terminal;
use App\Models\User;

/**
 * A4 -- the authoritative {user, terminal, store} composition
 * (module-a-auth-terminal-initialization.md §14 Ruling 1, §15). Only ever
 * constructed by ComposeAuthoritativeContext after it has confirmed
 * user.store_id == terminal.store_id; a controller that receives one may
 * trust every field on it without re-deriving or re-checking coherence,
 * and must never substitute a request-body-supplied id for any of them.
 */
final readonly class PosRequestContext
{
    public function __construct(
        public User $user,
        public Terminal $terminal,
    ) {}

    /** The single store both the user and the terminal belong to (already proven equal). */
    public function store(): Store
    {
        return $this->user->store;
    }
}
