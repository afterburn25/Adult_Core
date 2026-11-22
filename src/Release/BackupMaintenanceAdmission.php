<?php

declare(strict_types=1);

namespace AdultCore\Release;

use AdultCore\Installer\InstallationGate;
use RuntimeException;

/**
 * Local-host backup marker creation/removal coordinated with signed Pro
 * receipt writers and READY dynamic HTTP request lifetimes.
 *
 * This is an advisory interlock only. It does NOT stop actual Pro workers,
 * independent CLI scripts, database transactions, distributed nodes, or
 * backups from other processes.
 */
final class BackupMaintenanceAdmission
{
    private const MARKER = '.backup-maintenance';
    private const CONTENT =
        "Backup maintenance started. Inspect any interrupted backup before removing this marker.\n";

    /** @return array<string,mixed> */
    public static function begin(string $installedRoot): array
    {
        $root = self::site($installedRoot);
        $exclusive = SourceHttpRequestLock::exclusive($root);
        try {
            if ((new InstallationGate($root))->status() !== InstallationGate::READY) {
                throw new RuntimeException('Only an installed ready site can enter backup maintenance.');
            }
            $marker = self::privateStorage($root) . '/' . self::MARKER;
            if (file_exists($marker) || is_link($marker)) {
                throw new RuntimeException('Backup maintenance marker already exists or needs recovery.');
            }
            // Exclusive source coordination remains held from the READY
            // check until the private marker has been fully persisted.
            $fp = @fopen($marker, 'x+b');
            if (!is_resource($fp)) {
                throw new RuntimeException('Cannot create an exclusive backup maintenance marker.');
            }
            try {
                if (!chmod($marker, 0600)
                    || fwrite($fp, self::CONTENT) !== strlen(self::CONTENT)
                    || !fflush($fp)
                    || (function_exists('fsync') && !fsync($fp))) {
                    // Deliberately retain incomplete marker for manual
                    // recovery; a partial write still blocks the site.
                    throw new RuntimeException('Backup marker incomplete; operator recovery required.');
                }
            } finally {
                fclose($fp);
            }
            if ((new InstallationGate($root))->status() !== InstallationGate::BACKUP_MAINTENANCE) {
                throw new RuntimeException('Backup marker is present but another site gate needs recovery.');
            }
            return [
                'schema' => 'adult-core-cooperative-backup-maintenance-v1',
                'status' => 'backup_http_and_receipt_admission_blocked',
                'active_dynamic_requests_drained_at_entry' => true,
                'signed_receipt_writers_drained_at_entry' => true,
                'external_pro_workers_stopped' => false,
                'database_writers_quiesced' => false,
                'production_ready' => false,
            ];
        } finally {
            fclose($exclusive);
        }
    }

    /**
     * Only the same running backup-job code should call this on success/error
     * cleanup. Corrupted/replaced markers are intentionally NOT auto-cleared.
     */
    public static function finish(string $installedRoot): void
    {
        $root = self::site($installedRoot);
        $exclusive = SourceHttpRequestLock::exclusive($root);
        try {
            $marker = self::privateStorage($root) . '/' . self::MARKER;
            $mode = @fileperms($marker);
            $size = @filesize($marker);
            if (is_link($marker) || !is_file($marker)
                || !is_int($mode) || ($mode & 0077) !== 0
                || $size !== strlen(self::CONTENT)
                || file_get_contents($marker) !== self::CONTENT) {
                throw new RuntimeException('Backup maintenance marker is missing, changed or corrupted.');
            }
            if ((new InstallationGate($root))->status() !== InstallationGate::BACKUP_MAINTENANCE) {
                throw new RuntimeException('Another blocking recovery state prevents ending backup maintenance.');
            }
            if (!unlink($marker)) {
                throw new RuntimeException('Cannot safely clear finished backup maintenance marker.');
            }
            if ((new InstallationGate($root))->status() !== InstallationGate::READY) {
                throw new RuntimeException('Backup marker removed but installed site is not ready.');
            }
        } finally {
            fclose($exclusive);
        }
    }

    private static function site(string $root): string
    {
        $resolved = realpath($root);
        if ($resolved === false || $resolved === '/' || !is_dir($resolved) || is_link($root)) {
            throw new RuntimeException('Unsafe site root for cooperative backup maintenance.');
        }
        return $resolved;
    }

    private static function privateStorage(string $root): string
    {
        $storage = $root . '/storage';
        $mode = @fileperms($storage);
        if (is_link($storage) || !is_dir($storage) || !is_writable($storage)
            || !is_int($mode) || ($mode & 0077) !== 0) {
            throw new RuntimeException('Backup maintenance requires private owner-only site storage.');
        }
        return $storage;
    }
}
