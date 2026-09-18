<?php

namespace App\Services\Terminal;

use App\Domain\Exceptions\TerminalNotEnrolledException;
use App\Domain\Exceptions\TerminalRevokedException;
use App\Models\Terminal;

/**
 * ADR-011's trust boundary: the server resolves terminal_id EXCLUSIVELY
 * from the `tindaflow_terminal` credential -- never a request body/query
 * parameter/header. openapi.yaml's terminalCookieAuth description: "a
 * revoked... or unmatched credential fails with
 * TERMINAL_REVOKED/TERMINAL_NOT_ENROLLED."
 *
 * Deliberately does NOT compare the resolved Terminal against the
 * authenticated User's store_id -- that composition is A4's
 * responsibility (module-a-auth-terminal-initialization.md §14 Ruling 1
 * layered model), not A3's.
 */
final class TerminalCredentialResolver
{
    public function resolve(?string $rawCredential): Terminal
    {
        if ($rawCredential === null || $rawCredential === '') {
            throw TerminalNotEnrolledException::make();
        }

        $hash = hash('sha256', $rawCredential);

        // Fetched by hash alone, then revocation checked separately --
        // never `WHERE credential_hash = ? AND revoked_at IS NULL` in one
        // query, which would make "unknown" and "revoked" indistinguishable
        // even though the frozen contract gives them separate codes.
        $terminal = Terminal::where('credential_hash', $hash)->first();

        if ($terminal === null) {
            throw TerminalNotEnrolledException::make();
        }

        if ($terminal->revoked_at !== null) {
            throw TerminalRevokedException::forTerminal($terminal->id);
        }

        return $terminal;
    }
}
