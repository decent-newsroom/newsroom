<?php

/**
 * Lightweight check: are Doctrine migrations up-to-date?
 *
 * Compares the number of Version*.php files in migrations/ against the
 * row count in doctrine_migration_versions.  If they match, migrations
 * are (almost certainly) up-to-date and we can skip booting the full
 * Symfony kernel just to run `doctrine:migrations:migrate`.
 *
 * Exit 0 = up-to-date (skip migrate), exit 1 = pending (run migrate).
 */

$dsn = getenv('DATABASE_URL');
if (!$dsn) {
    exit(1);
}

$parts = parse_url($dsn);
if ($parts === false || !isset($parts['host'])) {
    exit(1);
}

$host   = $parts['host'];
$port   = $parts['port'] ?? 5432;
$dbname = ltrim($parts['path'] ?? '/app', '/');
$user   = $parts['user'] ?? 'app';
$pass   = $parts['pass'] ?? '';

try {
    $pdo = new PDO(
        sprintf('pgsql:host=%s;port=%d;dbname=%s;connect_timeout=3', $host, $port, $dbname),
        $user,
        $pass,
        [PDO::ATTR_TIMEOUT => 3, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $applied = (int) $pdo
        ->query('SELECT COUNT(*) FROM doctrine_migration_versions')
        ->fetchColumn();
} catch (\Throwable $e) {
    // Table doesn't exist or DB unreachable — migrations need to run.
    exit(1);
}

// Count migration files on disk.
$dir   = dirname(__DIR__) . '/migrations';
$files = glob($dir . '/Version*.php');
$total = is_array($files) ? count($files) : 0;

// If counts match, migrations are up-to-date.
exit($applied === $total ? 0 : 1);

