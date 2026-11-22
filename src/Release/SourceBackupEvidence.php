<?php

declare(strict_types=1);

namespace AdultCore\Release;

use AdultCore\Backup\BackupVerifier;
use AdultCore\Installer\InstallationGate;
use AdultCore\Installer\InstallationState;
use RuntimeException;

/**
 * Read-only backup identity gate for FUTURE signed-source updates.
 *
 * Proves a separately encrypted, private, fully authenticated .acfull archive
 * is from THIS installation, edition and current source version. No restore,
 * migration, backup freshness certification, or activation is permitted.
 */
final class SourceBackupEvidence
{
    /** @return array<string,mixed> */
    public static function inspect(
        string $installedRoot, string $encryptedArchive, string $offlineBackupKey
    ): array {
        $root = realpath($installedRoot);
        if ($root === false || $root === '/' || !is_dir($root) || is_link($installedRoot)) {
            throw new RuntimeException('Signed installed source root is unsafe.');
        }
        $gate = (new InstallationGate($root))->status();
        if (!in_array($gate, [
            InstallationGate::READY, InstallationGate::SOURCE_UPDATE_MAINTENANCE,
        ], true)) {
            throw new RuntimeException('Backup source identity cannot be checked during installation/recovery.');
        }

        $versionPath = $root . '/VERSION';
        if (is_link($versionPath) || !is_file($versionPath)
            || !is_readable($versionPath) || (filesize($versionPath) ?: 0) > 128) {
            throw new RuntimeException('Installed release VERSION is missing or unsafe.');
        }
        $version = file_get_contents($versionPath);
        if (!is_string($version) || preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,79}$/D', trim($version)) !== 1) {
            throw new RuntimeException('Installed version marker is invalid.');
        }
        $version = trim($version);

        $appKeyFile = $root . '/config/app-key.php';
        $stateFile = $root . '/storage/install-state.json';
        if (is_link($appKeyFile) || is_link($stateFile)
            || !is_file($appKeyFile) || !is_file($stateFile)
            || (filesize($appKeyFile) ?: 0) > 4096
            || (filesize($stateFile) ?: 0) > 4096) {
            throw new RuntimeException('Signed private site identity is unavailable.');
        }
        // InstallationGate has already verified this PHP config as a signed
        // installed site, and existing code loads the same trusted local file.
        $appKey = require $appKeyFile;
        $state = file_get_contents($stateFile);
        if (!is_string($appKey)
            || preg_match('/^[a-f0-9]{64}$/D', $appKey) !== 1
            || !is_string($state) || !InstallationState::valid($state, $appKey)) {
            throw new RuntimeException('Signed private installed-site identity is invalid.');
        }
        $metadata = json_decode($state, true, 8, JSON_THROW_ON_ERROR);
        $edition = $metadata['edition'];

        // A backup must be in a separate private owner-only vault, outside the
        // running app tree, not a file inside a public document root.
        $archive = realpath($encryptedArchive);
        $parent = realpath(dirname($encryptedArchive));
        if ($archive === false || $parent === false || $parent === '/'
            || is_link($encryptedArchive) || is_link(dirname($encryptedArchive))
            || !is_file($archive) || !is_readable($archive)
            || $archive === $root || str_starts_with($archive, $root . '/')
            || $parent === $root || str_starts_with($parent, $root . '/')
            || str_starts_with($root, $parent . '/')) {
            throw new RuntimeException('Encrypted recovery archive must reside in a separate private vault.');
        }
        $parentMode = fileperms($parent);
        $fileMode = fileperms($archive);
        if (!is_int($parentMode) || ($parentMode & 0077) !== 0
            || !is_int($fileMode) || ($fileMode & 0077) !== 0) {
            throw new RuntimeException('Encrypted recovery archive and vault must be private.');
        }
        // Full semantic verification authenticates every database record and
        // each private file, plus checks exact random installed app-key match.
        $verified = (new BackupVerifier())->verify($archive, $offlineBackupKey, $appKey);
        if ($verified['source_version'] !== $version
            || $verified['edition'] !== $edition) {
            throw new RuntimeException('Recovery backup version or edition differs from installed source.');
        }
        return [
            'schema' => 'adult-core-source-backup-evidence-v1',
            'read_only' => true,
            'encrypted_archive_verified' => true,
            'same_installed_site_verified' => true,
            'installed_version' => $version,
            'installed_edition' => $edition,
            'backup_sha256' => $verified['sha256'],
            'database_tables_verified' => $verified['tables'],
            'private_files_verified' => $verified['files'],
            'private_bytes_verified' => $verified['file_bytes'],
            'backup_age_certified' => false,
            'restore_drill_completed' => false,
            'database_writers_quiesced' => false,
            'source_activation_authorized' => false,
            'production_ready' => false,
        ];
    }
}
