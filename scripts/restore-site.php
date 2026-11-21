<?php

declare(strict_types=1);

/** Hard disaster recovery into empty, same-version source + empty database ONLY. */
if (PHP_SAPI!=='cli'||count($argv)!==3||($argv[1]??'')!=='--confirm-empty-target'||!str_ends_with($argv[2],'.acfull')) {
    fwrite(STDERR,"Usage: AC_BACKUP_KEY=<64-hex-offline-key> php scripts/restore-site.php --confirm-empty-target /private/backup.acfull\nTarget: exact same source version, intact install/, no installed site files, preconfigured NEW private config/database.php, completely empty database.\n");exit(2);
}
require dirname(__DIR__).'/src/Support/Autoload.php';
use AdultCore\Database\Connection;
use AdultCore\Backup\SiteBackup;
try {
    $key=getenv('AC_BACKUP_KEY');
    $stats=(new SiteBackup(dirname(__DIR__)))->restore(Connection::open(dirname(__DIR__)),$argv[2],is_string($key)?$key:'');
    echo "Recovered {$stats['tables']} tables, {$stats['rows']} rows and {$stats['files']} private files.\n";
    echo "Verify database/content, host capabilities, license domain and external backups. Remove install/ manually only after verifying recovery.\n";
} catch (Throwable $e) {
    fwrite(STDERR,"Restore failed: ".$e->getMessage()."\nIf a recovery marker exists, DO NOT delete it to reopen the site. Wipe this failed disposable target and retry with a fresh empty DB.\n");exit(1);
}
