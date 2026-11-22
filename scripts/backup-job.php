<?php

declare(strict_types=1);

/**
 * Cron-compatible opt-in backup + verify + private vault copy + safe retention.
 * Traffic/worker quiescence is an operator prerequisite; a marker locks *new* HTTP requests.
 */
if (PHP_SAPI !== 'cli' || !in_array(count($argv), [3, 4], true)) {
    fwrite(STDERR, "Usage: AC_BACKUP_KEY=<offline-key> AC_BACKUP_WRITES_PAUSED=YES php scripts/backup-job.php /private/staging /private/offsite-vault [retain=7]\n");
    exit(2);
}
require dirname(__DIR__).'/src/Support/Autoload.php';
use AdultCore\Backup\BackupVault;
use AdultCore\Backup\BackupVerifier;
use AdultCore\Backup\SiteBackup;
use AdultCore\Database\Connection;
use AdultCore\Installer\InstallationGate;
use AdultCore\Release\BackupMaintenanceAdmission;

$root = dirname(__DIR__);
$lock = null;
$ownsLock = false;
$ownsMarker = false;
$backupFinishToken = null;
$exitCode = 0;
$lockPath = $root.'/storage/.backup-job.lock';
try {
    if (getenv('AC_BACKUP_WRITES_PAUSED') !== 'YES') {
        throw new RuntimeException('Operator must first stop in-flight writes, uploads and workers, then confirm AC_BACKUP_WRITES_PAUSED=YES.');
    }
    $key = getenv('AC_BACKUP_KEY');
    $key = is_string($key) ? $key : '';
    if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) throw new RuntimeException('Use a separate 256-bit backup key.');
    $retain = $argv[3] ?? '7';
    if (!preg_match('/^[1-9][0-9]{0,2}$/D', $retain) || (int)$retain > 365) {
        throw new RuntimeException('Retention must be 1 through 365.');
    }
    $rootReal = realpath($root);
    $staging = realpath($argv[1]);
    $vault = realpath($argv[2]);
    if ($rootReal === false || $staging === false || $vault === false
        || !is_dir($staging) || !is_dir($vault)
        || is_link($argv[1]) || is_link($argv[2])
        || $staging === $vault
        || $staging === $rootReal || $vault === $rootReal
        || str_starts_with($staging, $rootReal.'/') || str_starts_with($vault, $rootReal.'/')) {
        throw new RuntimeException('Staging and vault must be different existing private directories outside the site root.');
    }
    foreach ([$staging, $vault] as $directory) {
        $mode = fileperms($directory);
        if (!is_int($mode) || ($mode & 0077) !== 0) throw new RuntimeException('Staging and vault must exclude other-user access.');
    }
    $gate = new InstallationGate($root);
    if ($gate->status() !== InstallationGate::READY) throw new RuntimeException('Site is not installed and ready for maintenance.');
    $pdo = Connection::open($root);
    $edition = (string)$pdo->query('SELECT edition FROM ac_installations WHERE id=1')->fetchColumn();
    if ($edition === 'pro' && getenv('AC_PRO_JOBS_STOPPED') !== 'YES') {
        throw new RuntimeException('Pro worker processes must be stopped and AC_PRO_JOBS_STOPPED=YES set by the operator.');
    }
    $lock = @fopen($lockPath, 'x+b');
    if (!is_resource($lock)) throw new RuntimeException('Backup lock already exists; inspect for a crashed job.');
    $ownsLock = true;
    @chmod($lockPath, 0600);
    if (!flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Backup lock is held.');
    // Obtain the same EXCLUSIVE local guard used by signed Pro receipts
    // and dynamic HTTP requests BEFORE publishing backup maintenance.
    // Refuse if either a participating request/receipt is still active.
    $admission = BackupMaintenanceAdmission::begin($root);
    $backupFinishToken = $admission['finish_token'];
    $ownsMarker = true;
    if ($gate->status() !== InstallationGate::BACKUP_MAINTENANCE || !$gate->eligibleForBackup()) {
        throw new RuntimeException('Unable to isolate signed installation during maintenance.');
    }
    $name = 'adult-core-staging-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(6)).'.acfull';
    $archive = $staging.'/'.$name;
    $stats = (new SiteBackup($root))->create($pdo, $archive, $key);
    $verified = (new BackupVerifier())->verify($archive, $key);
    foreach ($stats as $field => $value) {
        if (($verified[$field] ?? null) !== $value) throw new RuntimeException('Backup totals do not match authenticated archive.');
    }
    $sent = (new BackupVault($root))->ship($archive, $vault, $key, (int)$retain);
    echo "Encrypted {$edition} backup complete: {$verified['tables']} tables, {$verified['rows']} rows, {$verified['files']} files.\n";
    echo "Vault copy: {$sent['archive']} (SHA-256 {$sent['sha256']}); pruned {$sent['pruned']}, preserved invalid {$sent['skipped_invalid']}.\n";
    echo "Verified staging copy retained at {$archive}. Confirm remote replication and test an isolated restore.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Backup job failed: ".$e->getMessage()."\n");
    $exitCode = 1;
} finally {
    // A caught failure safely clears *our own* maintenance lock. A hard crash leaves
    // the marker in place, deliberately blocking HTTP until an operator investigates.
    if ($ownsMarker) {
        try {
            BackupMaintenanceAdmission::finish($root, $backupFinishToken);
        } catch (Throwable $cleanup) {
            fwrite(STDERR, "Backup maintenance cannot safely reopen the site: "
                . $cleanup->getMessage() . "\n");
            $exitCode = 1;
        }
    }
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    if ($ownsLock) @unlink($lockPath);
}
if ($exitCode !== 0) exit($exitCode);
