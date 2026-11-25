<?php

declare(strict_types=1);

/** CLI-only encrypted, coordinated and verified manual site backup. */
if (PHP_SAPI!=='cli'||count($argv)!==2||!str_ends_with($argv[1],'.acfull')) {
    fwrite(STDERR,"Usage: AC_BACKUP_KEY=<64-hex-offline-key> AC_BACKUP_WRITES_PAUSED=YES php scripts/backup-site.php /private/outside-site/backup.acfull\nFor Pro, also stop all Pro workers and set AC_PRO_JOBS_STOPPED=YES.\n");exit(2);
}
require dirname(__DIR__).'/src/Support/Autoload.php';
use AdultCore\Backup\SiteBackup;
try {
    $key=getenv('AC_BACKUP_KEY');
    $report=(new SiteBackup(dirname(__DIR__)))->createCoordinated(
        $argv[1],is_string($key)?$key:'',
        getenv('AC_BACKUP_WRITES_PAUSED')==='YES',
        getenv('AC_PRO_JOBS_STOPPED')==='YES'
    );
    echo "Encrypted private {$report['edition']} site backup created and verified: {$report['tables']} tables, {$report['rows']} rows, {$report['files']} files.\n";
    echo "SHA-256: {$report['sha256']}\n";
    echo "Keep the key separately OFFLINE. Copy archive off-server. Test restoration into an empty isolated host and DB.\n";
} catch (Throwable $e) {
    fwrite(STDERR,"Backup failed: ".$e->getMessage()."\nInspect any retained archive or maintenance marker before retrying.\n");exit(1);
}
