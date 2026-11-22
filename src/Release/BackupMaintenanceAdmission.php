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
    private const MAX_MARKER_BYTES = 2048;

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
            // Generate the finish capability only in this running backup job.
            // The marker stores its SHA-256, never the raw token. Crashes keep
            // the marker blocking HTTP until a separate manual recovery.
            $finishToken = bin2hex(random_bytes(32));
            $record = [
                'schema' => 'adult-core-backup-maintenance-owner-v1',
                'status' => 'backup_in_progress',
                'installed_root_sha256' => hash('sha256', $root),
                'finish_token_sha256' => hash('sha256', $finishToken),
                'created_utc' => gmdate('Y-m-d\\TH:i:s\\Z'),
            ];
            $content = json_encode(
                $record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . "\n";
            // Exclusive source coordination remains held from the READY
            // check until the private marker has been fully persisted.
            $fp = @fopen($marker, 'x+b');
            if (!is_resource($fp)) {
                throw new RuntimeException('Cannot create an exclusive backup maintenance marker.');
            }
            try {
                if (!chmod($marker, 0600)
                    || fwrite($fp, $content) !== strlen($content)
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
                'schema' => 'adult-core-cooperative-backup-maintenance-v2',
                'finish_token' => $finishToken,
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
     * Only the original backup job knows the one-time finish token.
     * Corrupted/replaced markers and crashed jobs are NOT auto-cleared.
     */
    public static function finish(string $installedRoot, string $finishToken): void
    {
        $root = self::site($installedRoot);
        if (preg_match('/^[a-f0-9]{64}$/D', $finishToken) !== 1) {
            throw new RuntimeException('Valid backup maintenance owner token is required.');
        }
        $exclusive = SourceHttpRequestLock::exclusive($root);
        try {
            $marker = self::privateStorage($root) . '/' . self::MARKER;
            clearstatcache(true, $marker);
            $mode = @fileperms($marker);
            $size = @filesize($marker);
            if (is_link($marker) || !is_file($marker) || !is_readable($marker)
                || !is_int($mode) || ($mode & 0077) !== 0
                || !is_int($size) || $size < 1 || $size > self::MAX_MARKER_BYTES) {
                throw new RuntimeException('Backup maintenance marker is missing, changed or corrupted.');
            }
            $raw = file_get_contents($marker);
            try {
                $record = is_string($raw)
                    ? json_decode($raw, true, 8, JSON_THROW_ON_ERROR) : null;
            } catch (\JsonException) {
                $record = null;
            }
            if (!is_array($record) || array_keys($record) !== [
                'schema', 'status', 'installed_root_sha256',
                'finish_token_sha256', 'created_utc',
            ] || $record['schema'] !== 'adult-core-backup-maintenance-owner-v1'
                || $record['status'] !== 'backup_in_progress'
                || $record['installed_root_sha256'] !== hash('sha256', $root)
                || !is_string($record['finish_token_sha256'])
                || preg_match('/^[a-f0-9]{64}$/D', $record['finish_token_sha256']) !== 1
                || !hash_equals($record['finish_token_sha256'], hash('sha256', $finishToken))
                || !is_string($record['created_utc'])
                || preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z$/D', $record['created_utc']) !== 1) {
                throw new RuntimeException('Backup maintenance marker is not owned by this backup job.');
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
