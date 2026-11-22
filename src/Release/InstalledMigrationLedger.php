<?php

declare(strict_types=1);

namespace AdultCore\Release;

use AdultCore\Database\Schema;
use AdultCore\Installer\InstallationGate;
use AdultCore\Installer\InstallationState;
use PDO;
use RuntimeException;

/**
 * Read-only installed MySQL/MariaDB schema & migration-ledger audit.
 *
 * It does NOT execute migrations, modify tables, compare a future candidate's
 * migration scripts, drain DB writers or authorize a source release.
 */
final class InstalledMigrationLedger
{
    private const MAX_TABLES = 250;
    private const MAX_MIGRATIONS = 500;

    /** @return array<string,mixed> */
    public static function inspect(string $installedRoot, PDO $pdo): array
    {
        $root = realpath($installedRoot);
        if ($root === false || $root === '/' || is_link($installedRoot) || !is_dir($root)) {
            throw new RuntimeException('Installed application root is unsafe.');
        }
        $gateStatus = (new InstallationGate($root))->status();
        if (!in_array($gateStatus, [
            InstallationGate::READY, InstallationGate::SOURCE_UPDATE_MAINTENANCE,
        ], true)) {
            throw new RuntimeException('Read-only DB audit requires a complete signed installation.');
        }
        $appKeyFile = $root.'/config/app-key.php';
        $installedFile = $root.'/storage/install-state.json';
        if (is_link($appKeyFile) || is_link($installedFile)
            || !is_file($appKeyFile) || !is_file($installedFile)
            || (filesize($appKeyFile) ?: 0) > 4096
            || (filesize($installedFile) ?: 0) > 4096) {
            throw new RuntimeException('Installed signed edition identity is unsafe.');
        }
        $appKey = require $appKeyFile;
        $raw = file_get_contents($installedFile);
        if (!is_string($appKey)
            || !is_string($raw)
            || !InstallationState::valid($raw, $appKey)) {
            throw new RuntimeException('Installed edition identity is not signed.');
        }
        $state = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        $edition = $state['edition'];

        // Read-only SQL and existing baseline schema checks. Never run schema
        // migrations here: DDL in MySQL auto-commits and cannot be rolled back.
        Schema::verify($pdo);
        $installations = $pdo->query('SELECT id, edition FROM ac_installations ORDER BY id LIMIT 3')
            ->fetchAll(PDO::FETCH_ASSOC);
        if (count($installations) !== 1
            || (int) $installations[0]['id'] !== 1
            || $installations[0]['edition'] !== $edition) {
            throw new RuntimeException('Database installation edition differs from signed site.');
        }

        $tables = $pdo->query(
            'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        )->fetchAll(PDO::FETCH_ASSOC);
        $adultTables = [];
        foreach ($tables as $entry) {
            $tableName = $entry['TABLE_NAME'] ?? null;
            if (!is_string($tableName) || !str_starts_with($tableName, 'ac_')) continue;
            if (count($adultTables) >= self::MAX_TABLES
                || preg_match('/^ac_[a-z0-9_]{1,60}$/D', $tableName) !== 1
                || !is_string($entry['ENGINE'] ?? null)
                || strcasecmp($entry['ENGINE'], 'InnoDB') !== 0) {
                throw new RuntimeException('Adult Core schema contains unsafe or non-InnoDB tables.');
            }
            $adultTables[] = $tableName;
        }
        sort($adultTables, SORT_STRING);
        if (count($adultTables) < 5) {
            throw new RuntimeException('Adult Core schema inventory is incomplete.');
        }

        $records = $pdo->query(
            'SELECT version, applied_at FROM ac_schema_migrations ORDER BY version'
        )->fetchAll(PDO::FETCH_ASSOC);
        if (count($records) > self::MAX_MIGRATIONS) {
            throw new RuntimeException('Installed migration ledger exceeds safe audit bounds.');
        }
        $canonical = [];
        foreach ($records as $record) {
            $version = $record['version'] ?? null;
            $stamp = $record['applied_at'] ?? null;
            if (!is_string($version)
                || preg_match('/^[0-9A-Za-z][0-9A-Za-z._:-]{0,79}$/D', $version) !== 1
                || !is_string($stamp)
                || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $stamp) !== 1) {
                throw new RuntimeException('Installed migration ledger has malformed entries.');
            }
            $canonical[] = [$version, $stamp];
        }
        return [
            'schema' => 'adult-core-installed-migration-ledger-audit-v1',
            'read_only' => true,
            'installed_edition_matches_database' => true,
            'installed_edition' => $edition,
            'migration_records_verified' => count($canonical),
            'migration_ledger_sha256' => hash('sha256', json_encode(
                $canonical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            )),
            'innodb_adult_core_tables' => count($adultTables),
            'candidate_schema_compatibility_verified' => false,
            'database_writers_quiesced' => false,
            'migration_rollback_implemented' => false,
            'ready_for_activation' => false,
            'production_ready' => false,
        ];
    }
}
