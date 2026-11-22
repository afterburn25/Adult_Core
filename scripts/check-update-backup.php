<?php

declare(strict_types=1);

/**
 * CLI-only proof an encrypted full-site backup belongs to THIS exact
 * installed release/edition/site. Read-only; never restores or updates.
 * Offline AES-256 backup key is passed via STDIN, not argv or URL.
 */
require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Release\SourceBackupEvidence;

if (PHP_SAPI !== 'cli' || count($argv) !== 3) {
    fwrite(STDERR, "Usage: php scripts/check-update-backup.php installed-root /private/vault/recovery.acfull < /private/backup-key\n");
    exit(64);
}
try {
    $input = stream_get_contents(STDIN, 80);
    $key = is_string($input) ? trim($input) : '';
    if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) {
        throw new RuntimeException('An offline 64-hex backup key is required via STDIN.');
    }
    $report = SourceBackupEvidence::inspect($argv[1], $argv[2], $key);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    fwrite(STDERR, "Encrypted backup identity verified only. No restore drill, migration, worker drain or update authorized.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Release backup evidence REFUSED: " . $error->getMessage() . "\n");
    exit(1);
}
