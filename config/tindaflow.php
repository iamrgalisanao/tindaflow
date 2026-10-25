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

    // The password the application's own database role (`tindaflow_app`) is given by `tindaflow:harden-database`.
    // Set only where that command runs (the deployment's one-shot migrate step, as the table owner); the running
    // application never needs it and, connecting as `tindaflow_app`, never holds the owner's credentials.
    'database' => [
        'app_password' => env('DB_APP_PASSWORD'),
    ],

    // Ceiling on API requests per signed-in user per minute. A technical safeguard, not a
    // business rule: a busy till makes a few requests a second at most.
    'api_throttle' => [
        'per_minute' => env('API_THROTTLE_PER_MINUTE', 600),
    ],

    // ADR-016 slice 3.2: employee codes are sequential, so the till's employee lookup and credit-status reads are limited per signed-in
    // user (a cashier scans one card per sale; this is far above the busiest queue). A technical safeguard, not a business rule.
    'employee_lookup_throttle' => [
        'per_minute' => env('EMPLOYEE_LOOKUP_THROTTLE_PER_MINUTE', 30),
    ],

    // ADR-016 slice 3.3 (supervisor override of the credit limit). Technical safeguards agreed in the ADR: five wrong codes lock that
    // approver for fifteen minutes; a grant is good for five minutes; a terminal (and a signed-in user) may submit ten override requests
    // in fifteen minutes; the code and the account password are re-entered to set a code, ten tries in fifteen minutes per user.
    'credit_override' => [
        'max_wrong_codes' => (int) env('CREDIT_OVERRIDE_MAX_WRONG_CODES', 5),
        'lock_minutes' => (int) env('CREDIT_OVERRIDE_LOCK_MINUTES', 15),
        'grant_ttl_minutes' => (int) env('CREDIT_OVERRIDE_GRANT_TTL_MINUTES', 5),
        'throttle_attempts' => (int) env('CREDIT_OVERRIDE_THROTTLE_ATTEMPTS', 10),
        'throttle_minutes' => (int) env('CREDIT_OVERRIDE_THROTTLE_MINUTES', 15),
        // Transactions the process already holds when a code is verified. Always 0 outside tests; see CreditOverrideService.
        'base_transaction_level' => 0,
    ],

    // A3: the tindaflow_terminal credential cookie's lifetime. ADR-011
    // calls for a "long-lived" credential; the exact duration is an
    // internal Stage 6 operational choice, not part of the frozen
    // contract (never added to openapi.yaml/architecture.md), so it lives
    // here rather than as a magic number in the controller/tests.
    // 399 days, not longer, because browsers cap a cookie's lifetime (Chrome at 400 days) whatever the server asks for. The
    // cookie is renewed on every use of the till (ResolveTerminalContext), so this is how long a till may sit UNUSED before
    // it has to be re-enrolled, not how long after enrollment it works.
    'terminal_credential' => [
        'lifetime_minutes' => env('TERMINAL_CREDENTIAL_LIFETIME_MINUTES', 60 * 24 * 399),
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

    // ADR-014: the vendor-signed license that caps how many tills this installation may run. `public_key` is a
    // constant in code on purpose (base64 of the 32-byte Ed25519 public key from `tindaflow:license-keygen`), never an
    // environment variable: a shop that could swap it could sign its own license. Empty = the cap is not enforced.
    // The signed license itself is a file (default storage/app/license.key) or TINDAFLOW_LICENSE.
    'license' => [
        'public_key' => '',
        'path' => env('TINDAFLOW_LICENSE_FILE', storage_path('app/license.key')),
        'token' => env('TINDAFLOW_LICENSE'),
    ],

];
