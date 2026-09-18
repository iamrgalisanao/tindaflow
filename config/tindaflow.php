<?php

// New in A1 (Module A auth/session foundation). Centralizes small
// application-level settings that don't belong in a stock Laravel config
// file, starting with the login throttle's attempts/window (Module A
// Decision Register SS14 Ruling 6) so neither is a magic number embedded
// in the rate limiter registration or its tests.
return [

    'login_throttle' => [
        'max_attempts' => env('LOGIN_THROTTLE_MAX_ATTEMPTS', 5),
        'decay_minutes' => env('LOGIN_THROTTLE_DECAY_MINUTES', 1),
    ],

    // A3: the tindaflow_terminal credential cookie's lifetime. ADR-011
    // calls for a "long-lived" credential; the exact duration is an
    // internal Stage 6 operational choice, not part of the frozen
    // contract (never added to openapi.yaml/architecture.md), so it lives
    // here rather than as a magic number in the controller/tests.
    'terminal_credential' => [
        'lifetime_minutes' => env('TERMINAL_CREDENTIAL_LIFETIME_MINUTES', 60 * 24 * 365 * 5),
    ],

    // Shift-close module: openapi.yaml's shiftCashMovementCreate requires
    // the CASH_OUT capability for a CASH_OUT "above a configurable
    // threshold" (invariant #39) -- the contract mandates configurability,
    // not a specific value. This default is a technical placeholder, not
    // a business decision; a store owner should set CASH_OUT_AUTHORIZATION_
    // THRESHOLD per their own cash-handling policy.
    'cash_movements' => [
        'cash_out_authorization_threshold' => env('CASH_OUT_AUTHORIZATION_THRESHOLD', '1000.00'),
    ],

];
