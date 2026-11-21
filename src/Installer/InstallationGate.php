<?php

declare(strict_types=1);

namespace AdultCore\Installer;

use RuntimeException;

/**
 * Single source of truth for the site's install/cleanup access policy.
 *
 * The signed 'installed' state is written only after successful DB/admin
 * bootstrap by the installation service.
 * install/ intentionally lives ABOVE the web document root so it can be
 * removed in one operation without leaving runnable installer scripts.
 */
final class InstallationGate
{
    public const SETUP_REQUIRED = 'setup_required';
    public const CLEANUP_REQUIRED = 'cleanup_required';
    public const READY = 'ready';
    public const INSTALLER_MISSING = 'installer_missing';
    public const INVALID_STATE = 'invalid_state';
    public const EDITION_UPGRADE_PENDING = 'edition_upgrade_pending';
    public const BACKUP_RESTORE_PENDING = 'backup_restore_pending';
    public const BACKUP_MAINTENANCE = 'backup_maintenance';

    public function __construct(private readonly string $projectRoot)
    {
    }

    public function status(): string
    {
        // Never serve a half-restored site, even if an operator removed install/ early.
        if (file_exists($this->projectRoot . '/storage/.backup-restore-pending')
            || is_link($this->projectRoot . '/storage/.backup-restore-pending')) {
            return self::BACKUP_RESTORE_PENDING;
        }
        // An interrupted cross-DB/filesystem edition transition must never serve requests.
        // Recovery is deliberately CLI-only: no browser path can waive this marker.
        if (file_exists($this->projectRoot . '/storage/.edition-upgrade-pending')
            || is_link($this->projectRoot . '/storage/.edition-upgrade-pending')) {
            return self::EDITION_UPGRADE_PENDING;
        }
        if (file_exists($this->projectRoot . '/storage/.backup-maintenance')
            || is_link($this->projectRoot . '/storage/.backup-maintenance')) {
            return self::BACKUP_MAINTENANCE;
        }
        return $this->installedStatus();
    }

    /** CLI-only backups may run while HTTP is locked for maintenance. */
    public function eligibleForBackup(): bool
    {
        if (in_array($this->status(), [self::BACKUP_RESTORE_PENDING, self::EDITION_UPGRADE_PENDING], true)) {
            return false;
        }
        return $this->installedStatus() === self::READY;
    }

    private function installedStatus(): string
    {
        $installDirectoryPresent = is_dir($this->projectRoot . '/install');
        $stateFile = $this->projectRoot . '/storage/install-state.json';

        if (!is_file($stateFile)) {
            return $installDirectoryPresent ? self::SETUP_REQUIRED : self::INSTALLER_MISSING;
        }

        // Never trust a bare JSON marker. Validity requires the private app
        // key, an installed database configuration, and a signed v2 state.
        $keyFile = $this->projectRoot . '/config/app-key.php';
        $dbFile = $this->projectRoot . '/config/database.php';
        if (!is_readable($stateFile) || !is_file($keyFile) || !is_readable($keyFile)
            || !is_file($dbFile) || !is_readable($dbFile)) {
            return self::INVALID_STATE;
        }
        $raw = file_get_contents($stateFile);
        if (!is_string($raw)) {
            return self::INVALID_STATE;
        }
        $key = require $keyFile;
        if (!is_string($key) || !InstallationState::valid($raw, $key)) {
            return self::INVALID_STATE;
        }
        $config = require $dbFile;
        if (!is_array($config) || ($config['driver'] ?? null) !== 'mysql'
            || !isset($config['database'], $config['username'])) {
            return self::INVALID_STATE;
        }

        return $installDirectoryPresent ? self::CLEANUP_REQUIRED : self::READY;
    }

    /** Fail closed for future HTTP routes, workers and admin entry points. */
    public function assertSiteAccessible(): void
    {
        if ($this->status() !== self::READY) {
            throw new RuntimeException('Site access blocked until installation is complete and install/ has been removed.');
        }
    }
}
