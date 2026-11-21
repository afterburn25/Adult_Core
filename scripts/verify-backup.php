<?php

declare(strict_types=1);

/** CLI-only deep backup inspection; does not import data, write files, or show secrets. */
if (PHP_SAPI !== 'cli' || count($argv) !== 2) {
    fwrite(STDERR, "Usage: AC_BACKUP_KEY=<64-hex-offline-key> php scripts/verify-backup.php /private/archive.acfull\n");
    exit(2);
}
require dirname(__DIR__).'/src/Support/Autoload.php';
try {
    $key = getenv('AC_BACKUP_KEY');
    $result = (new \AdultCore\Backup\BackupVerifier())->verify($argv[1], is_string($key) ? $key : '');
    echo "Verified {$result['edition']} {$result['source_version']} backup: {$result['tables']} tables, {$result['rows']} rows, {$result['files']} private files, SHA-256 {$result['sha256']}.\n";
    echo "This does not replace an independent recovery drill.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Archive verification failed: ".$e->getMessage()."\n");
    exit(1);
}
