<?php

/**
 * Container health check: succeeds only if PostgreSQL is reachable and answers a query with the app's own
 * credentials. Laravel's built-in /up proves the application boots but never touches the database, so a database
 * outage would leave it "healthy" while every sale failed.
 */
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '5432';
$database = getenv('DB_DATABASE') ?: 'tindaflow';

try {
    $pdo = new PDO(
        "pgsql:host={$host};port={$port};dbname={$database}",
        getenv('DB_USERNAME') ?: 'tindaflow',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $pdo->query('select 1')->fetchColumn();
} catch (Throwable $e) {
    fwrite(STDERR, 'database unreachable: '.$e->getMessage().PHP_EOL);
    exit(1);
}
