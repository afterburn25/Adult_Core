<?php

declare(strict_types=1);

/** CLI-only encrypted site backup; no HTTP or scheduled execution. */
if (PHP_SAPI!=='cli'||count($argv)!==2||!str_ends_with($argv[1],'.acfull')) {
    fwrite(STDERR,"Usage: AC_BACKUP_KEY=<64-hex-offline-key> php scripts/backup-site.php /path/outside-site/backup.acfull\n");exit(2);
}
require dirname(__DIR__).'/src/Support/Autoload.php';
use AdultCore\Database\Connection;
use AdultCore\Backup\SiteBackup;
try {
    $key=getenv('AC_BACKUP_KEY');
    $stats=(new SiteBackup(dirname(__DIR__)))->create(Connection::open(dirname(__DIR__)),$argv[1],is_string($key)?$key:'');
    echo "Encrypted private site backup created: {$stats['tables']} tables, {$stats['rows']} rows, {$stats['files']} files.\n";
    echo "Keep the key separately OFFLINE. Copy archive off-server. Test restoration into an empty isolated host and DB.\n";
} catch (Throwable $e) {
    fwrite(STDERR,"Backup failed: ".$e->getMessage()."\n");exit(1);
}
