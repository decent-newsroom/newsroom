<?php

/**
 * Lightweight database connectivity check.
 *
 * Used by docker-entrypoint.sh to test whether PostgreSQL is reachable
 * WITHOUT booting the full Symfony kernel (which is very slow on
 * bind-mounted Windows/Mac volumes).
 *
 * Exit 0 = reachable, exit 1 = not reachable (error on stderr).
 */

$dsn = getenv('DATABASE_URL');
if (!$dsn) {
    fwrite(STDERR, "DATABASE_URL environment variable is not set\n");
    exit(1);
}

$parts = parse_url($dsn);
if ($parts === false || !isset($parts['host'])) {
    fwrite(STDERR, "Cannot parse DATABASE_URL\n");
    exit(1);
}

$host   = $parts['host'];
$port   = $parts['port'] ?? 5432;
$dbname = ltrim($parts['path'] ?? '/app', '/');
$user   = $parts['user'] ?? 'app';
$pass   = $parts['pass'] ?? '';

try {
    new PDO(
        sprintf('pgsql:host=%s;port=%d;dbname=%s;connect_timeout=3', $host, $port, $dbname),
        $user,
        $pass,
        [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

