<?php

namespace Tests\Database;

/**
 * Where the PostgreSQL-backed tests connect. One place, driven by the same PGSQL_TEST_* environment variables for
 * every test in tests/Database -- including the multi-process concurrency tests and their worker scripts, which
 * used to hard-code 127.0.0.1 / postgres / no password and so only ran against a local trust-authenticated server.
 * The defaults are still exactly that, so a plain local run needs no configuration.
 */
final class PostgresTestConnection
{
    /**
     * Config overrides that point the `pgsql` connection at $database.
     *
     * @return array<string, mixed>
     */
    public static function settings(string $database): array
    {
        return [
            'database.default' => 'pgsql',
            'database.connections.pgsql.host' => env('PGSQL_TEST_HOST', '127.0.0.1'),
            'database.connections.pgsql.port' => env('PGSQL_TEST_PORT', '5432'),
            'database.connections.pgsql.database' => $database,
            'database.connections.pgsql.username' => env('PGSQL_TEST_USERNAME', 'postgres'),
            'database.connections.pgsql.password' => env('PGSQL_TEST_PASSWORD', ''),
        ];
    }
}
