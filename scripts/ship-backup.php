<?php

declare(strict_types=1);

/** CLI-only copy + rotation of cryptographically and semantically verified archives. */
if (PHP_SAPI !== 'cli' || !in_array(count($argv), [3, 4], true)) {
    fwrite(STDERR, "Usage: AC_BACKUP_KEY=<64-hex-offline-key> php scripts/ship-backup.php /private/archive.acfull /private/mounted-vault [retain=7]\n");
    exit(2);
}
require dirname(__DIR__).'/src/Support/Autoload.php';
try {
    $key = getenv('AC_BACKUP_KEY');
    $retention = $argv[3] ?? '7';
    if (!preg_match('/^(?:[1-9]|[1-9][0-9]{1,2})$/D', $retention)) throw new RuntimeException('Retention must be 1 through 365.');
    $result = (new \AdultCore\Backup\BackupVault(dirname(__DIR__)))->ship($argv[1], $argv[2], is_string($key) ? $key : '', (int)$retention);
    echo "Verified backup transferred to {$result['archive']}; retained {$result['retained']}, pruned {$result['pruned']}, preserved foreign/invalid/unreadable {$result['skipped_invalid']}.\n";
    echo "A directory is not proof of offsite storage. Verify your mount, replication, and independent restore.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Backup transfer failed: ".$e->getMessage()."\n");
    exit(1);
}
