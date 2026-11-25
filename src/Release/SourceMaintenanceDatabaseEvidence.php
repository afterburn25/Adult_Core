<?php

declare(strict_types=1);

namespace AdultCore\Release;

use PDO;
use RuntimeException;

/**
 * READ-ONLY database ledger evidence during token-owned source maintenance.
 *
 * The exclusive lock protects only cooperating local HTTP, receipt and worker
 * entry points. It does NOT block independent SQL clients, external workers,
 * remote nodes, or database transactions and NEVER authorizes activation.
 */
final class SourceMaintenanceDatabaseEvidence
{
    /** @return array<string,mixed> */
    public static function inspect(
        string $installedRoot, string $privateCoordinationRoot,
        string $releaseToken, PDO $pdo
    ): array {
        $root = realpath($installedRoot);
        if ($root === false || $root === '/' || !is_dir($root) || is_link($installedRoot)) {
            throw new RuntimeException('Installed database maintenance audit root is unsafe.');
        }

        // Do not begin a second connection's inspection inside a caller's
        // pending transaction. This is an observational audit, not a migration
        // or transactional consistency protocol.
        if ($pdo->inTransaction() || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('Maintenance database evidence needs an idle MySQL connection.');
        }
        $localLock = SourceHttpRequestLock::exclusive($root);
        try {
            // Verify both the private token lease AND the authenticated site
            // marker while cooperating local writers cannot enter.
            SourceUpdateMaintenance::assertOwnedMarker(
                $root, $privateCoordinationRoot, $releaseToken
            );
            $first = InstalledMigrationLedger::inspect($root, $pdo);
            $second = InstalledMigrationLedger::inspect($root, $pdo);
            foreach ([
                'installed_edition',
                'migration_records_verified',
                'migration_ledger_sha256',
                'innodb_adult_core_tables',
            ] as $field) {
                if ($first[$field] !== $second[$field]) {
                    throw new RuntimeException('Installed database ledger changed during maintenance review.');
                }
            }

            return [
                'schema' => 'adult-core-source-maintenance-db-evidence-v1',
                'read_only' => true,
                'signed_source_maintenance_owner_verified' => true,
                'cooperating_local_guard_exclusive_at_scan' => true,
                'signed_installed_edition_matches_database' => true,
                'installed_edition' => $second['installed_edition'],
                'migration_records_verified' => $second['migration_records_verified'],
                'migration_ledger_sha256' => $second['migration_ledger_sha256'],
                'innodb_adult_core_tables' => $second['innodb_adult_core_tables'],
                'two_ledger_reads_identical_at_scan' => true,
                'independent_database_writers_stopped' => false,
                'database_writers_quiesced' => false,
                'external_pro_workers_quiesced' => false,
                'candidate_schema_compatibility_verified' => false,
                'ready_for_activation' => false,
                'production_ready' => false,
            ];
        } finally {
            fclose($localLock);
        }
    }
}
