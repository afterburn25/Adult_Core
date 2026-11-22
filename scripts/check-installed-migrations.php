<?php

declare(strict_types=1);

/**
 * Read-only installed MySQL/MariaDB ledger and edition consistency probe.
 * Database credentials come from trusted private installed configuration,
 * never from user-provided command arguments or the public website.
 * This cannot execute DDL/migrations or authorize a source update.
 */
require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Database\Connection;
use AdultCore\Release\InstalledMigrationLedger;

if (PHP_SAPI !== 'cli' || count($argv) !== 2) {
    fwrite(STDERR, "Usage: php scripts/check-installed-migrations.php /private/installed/Adult_Core\n");
    exit(64);
}
try {
    $pdo = Connection::open($argv[1]);
    $result = InstalledMigrationLedger::inspect($argv[1], $pdo);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    fwrite(STDERR, "Read-only installed migration ledger check. No DDL, candidate migration approval, worker drain, or update performed.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Installed migration ledger REFUSED: " . $error->getMessage() . "\n");
    exit(1);
}
