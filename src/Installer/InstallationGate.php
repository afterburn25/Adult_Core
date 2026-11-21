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
    public const LICENSE_UPDATE_PENDING = 'license_update_pending';
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
        // Interrupted license replacement may have left the private config and
        // signed cache from different generations. All HTTP access is blocked.
        if (file_exists($this->projectRoot . '/storage/.license-update-pending')
            || is_link($this->projectRoot . '/storage/.license-update-pending')) {
            return self::LICENSE_UPDATE_PENDING;
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
        if (in_array($this->status(), [self::BACKUP_RESTORE_PENDING, self::EDITION_UPGRADE_PENDING, self::LICENSE_UPDATE_PENDING], true)) {
            return false;
        }
        // An add-on activation can also leave two private entitlement files
        // from different generations. Never back up that inconsistent pair.
        $moduleDir = $this->projectRoot . '/storage/packages/module-licenses';
        if (is_link($moduleDir)) return false;
        if (is_dir($moduleDir)) {
            if (!is_readable($moduleDir)) return false;
            if (glob($moduleDir . '/.pending-*') ?: []) return false;
        }
        return $this->installedStatus() === self::READY;
    }

    private function installedStatus(): string
    {
        $installDirectoryPresent = is_dir($this->projectRoot . '/install');
        $stateFile = $this->projectRoot . '/storage/install-state.json';

        $keyFile = $this->projectRoot . '/config/app-key.php';
        $dbFile = $this->projectRoot . '/config/database.php';
        $licenseFile = $this->projectRoot . '/config/license.php';
        $licenseCache = $this->projectRoot . '/storage/license-cache.json';
        if (!is_file($stateFile) || is_link($stateFile)) {
            // A directory, symlink or other unexpected object at the marker
            // path is corruption, not an empty installation.
            if (file_exists($stateFile) || is_link($stateFile)) return self::INVALID_STATE;
            // A missing marker plus generated private files is an incomplete
            // installation, NOT a fresh site. Never offer the setup form or
            // silently reinitialize an existing database in this state.
            foreach ([$keyFile, $dbFile, $licenseFile, $licenseCache] as $privateFile) {
                if (file_exists($privateFile) || is_link($privateFile)) {
                    return self::INVALID_STATE;
                }
            }
            return $installDirectoryPresent ? self::SETUP_REQUIRED : self::INSTALLER_MISSING;
        }

        // Never trust a bare JSON marker. Validity requires the private app
        // key, an installed database configuration, and a signed v2 state.
        // Refuse symlinks so an untrusted mount cannot redirect secret reads.
        if (!is_readable($stateFile) || is_link($keyFile) || is_link($dbFile)
            || !is_file($keyFile) || !is_readable($keyFile)
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
