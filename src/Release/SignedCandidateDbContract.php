<?php

declare(strict_types=1);

namespace AdultCore\Release;

use PDO;
use RuntimeException;
use ZipArchive;

/**
 * READ-ONLY signed source database-contract comparator.
 *
 * Contract declarations are authenticated as ZIP bytes by the publisher's
 * detached signature. They are NOT proof that PHP code has no ad hoc SQL.
 * No SQL migrations, file replacement or source activation are performed.
 */
final class SignedCandidateDbContract
{
    public const CONTRACT_PATH = 'src/Database/release-schema-contract.json';
    private const MAX_CONTRACT_BYTES = 4096;
    private const MAX_MIGRATIONS = 200;

    /**
     * Inspect two independently publisher-signed release ZIPs.
     * @return array<string,mixed>
     */
    public static function compare(
        string $baselineZip, string $baselineManifest, string $baselineSignature,
        string $candidateZip, string $candidateManifest, string $candidateSignature,
        string $trustedPublicKeyPem
    ): array {
        $old = SourceArchive::verifiedInventory(
            $baselineZip, $baselineManifest, $baselineSignature, $trustedPublicKeyPem
        );
        $new = SourceArchive::verifiedInventory(
            $candidateZip, $candidateManifest, $candidateSignature, $trustedPublicKeyPem
        );
        if (!version_compare($new['version'], $old['version'], '>')) {
            throw new RuntimeException('Candidate must be newer than the independently signed baseline.');
        }
        $baselineContract = self::contract($baselineZip, $old['files']);
        $candidateContract = self::contract($candidateZip, $new['files']);

        // A declared migration list is append-only; replacing/removing a
        // migration ID or decreasing the revision requires manual recovery.
        if ($candidateContract['revision'] < $baselineContract['revision']) {
            throw new RuntimeException('Candidate database contract revision regressed.');
        }
        $baselineMigrations = $baselineContract['declared_migrations'];
        $candidateMigrations = $candidateContract['declared_migrations'];
        foreach ($baselineMigrations as $i => $id) {
            if (($candidateMigrations[$i] ?? null) !== $id) {
                throw new RuntimeException('Candidate rewrites or removes an earlier declared migration.');
            }
        }
        $newMigrations = array_slice($candidateMigrations, count($baselineMigrations));
        $revisionChanged = $candidateContract['revision'] !== $baselineContract['revision'];

        $oldFiles = self::map($old['files']);
        $newFiles = self::map($new['files']);
        $dbSensitive = [];
        $applicationChanged = [];
        foreach ($newFiles as $path => $hash) {
            if (!isset($oldFiles[$path]) || !hash_equals($oldFiles[$path], $hash)) {
                if (str_starts_with($path, 'src/') && str_ends_with($path, '.php')) {
                    $applicationChanged[] = $path;
                }
                if (self::dbSensitive($path)) $dbSensitive[] = $path;
            }
        }
        foreach ($oldFiles as $path => $_) {
            if (!isset($newFiles[$path])) {
                // Removal of a PHP migration or schema implementation is
                // inherently unsafe, even if its contract was not revised.
                if (str_starts_with($path, 'src/') && str_ends_with($path, '.php')) {
                    $applicationChanged[] = $path;
                }
                if (self::dbSensitive($path)) $dbSensitive[] = $path;
            }
        }
        sort($dbSensitive, SORT_STRING);
        sort($applicationChanged, SORT_STRING);
        $contractChanged = $revisionChanged || $newMigrations !== [];
        return [
            'schema' => 'adult-core-signed-candidate-db-contract-review-v1',
            'read_only' => true,
            'both_source_archives_authenticated' => true,
            'baseline_version' => $old['version'],
            'candidate_version' => $new['version'],
            'contract_family' => $candidateContract['family'],
            'baseline_schema_revision' => $baselineContract['revision'],
            'candidate_schema_revision' => $candidateContract['revision'],
            'baseline_declared_migrations' => $baselineMigrations,
            'new_declared_migrations' => $newMigrations,
            'db_sensitive_source_changes' => $dbSensitive,
            'other_php_application_changes_count' => count($applicationChanged),
            'declared_database_contract_changed' => $contractChanged,
            'manual_database_compatibility_review_required' =>
                $contractChanged || $dbSensitive !== [] || $applicationChanged !== [],
            'candidate_schema_compatibility_verified' => false,
            'migrations_executed' => false,
            'source_activation_authorized' => false,
            'production_ready' => false,
        ];
    }

    /**
     * Read-only installed DB + signed release cross-check. Ledger must already
     * include every declared baseline migration; never applies candidates.
     * @return array<string,mixed>
     */
    public static function inspectInstalled(
        string $installedRoot, PDO $pdo,
        string $baselineZip, string $baselineManifest, string $baselineSignature,
        string $candidateZip, string $candidateManifest, string $candidateSignature,
        string $trustedPublicKeyPem
    ): array {
        $ledger = InstalledMigrationLedger::inspect($installedRoot, $pdo);
        $plan = self::compare(
            $baselineZip, $baselineManifest, $baselineSignature,
            $candidateZip, $candidateManifest, $candidateSignature, $trustedPublicKeyPem
        );
        $root = realpath($installedRoot);
        if ($root === false || is_link($installedRoot)) {
            throw new RuntimeException('Installed source root is unsafe.');
        }
        $versionPath = $root.'/VERSION';
        if (is_link($versionPath) || !is_file($versionPath)
            || (filesize($versionPath) ?: 0) > 128) {
            throw new RuntimeException('Installed source VERSION is unsafe.');
        }
        $version = file_get_contents($versionPath);
        if (!is_string($version) || trim($version) !== $plan['baseline_version']) {
            throw new RuntimeException('Installed version differs from the signed database baseline.');
        }
        $old = SourceArchive::verifiedInventory(
            $baselineZip, $baselineManifest, $baselineSignature, $trustedPublicKeyPem
        );
        $baseline = self::contract($baselineZip, $old['files']);
        $stmt = $pdo->query('SELECT version FROM ac_schema_migrations ORDER BY version');
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($rows) || count($rows) !== $ledger['migration_records_verified']) {
            throw new RuntimeException('Installed migration ledger changed during review.');
        }
        foreach ($baseline['declared_migrations'] as $id) {
            if (!in_array($id, $rows, true)) {
                throw new RuntimeException('Installed database lacks a signed baseline migration record.');
            }
        }
        return $plan + [
            'installed_database_edition' => $ledger['installed_edition'],
            'installed_migration_ledger_sha256' => $ledger['migration_ledger_sha256'],
            'signed_baseline_migrations_present' => true,
            'installed_database_writers_quiesced' => false,
        ];
    }

    /** @param list<array{path:string,sha256:string}> $records
     *  @return array{format:string,family:string,revision:int,declared_migrations:list<string>}
     */
    private static function contract(string $zipPath, array $records): array
    {
        $map = self::map($records);
        if (!isset($map[self::CONTRACT_PATH])) {
            throw new RuntimeException('Publisher-signed release lacks required database contract.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Publisher-signed database contract archive is unreadable.');
        }
        try {
            $stats = $zip->statName(self::CONTRACT_PATH);
            if (!is_array($stats) || !is_int($stats['size'] ?? null)
                || $stats['size'] < 1 || $stats['size'] > self::MAX_CONTRACT_BYTES) {
                throw new RuntimeException('Publisher-signed database contract is oversized or missing.');
            }
            $raw = $zip->getFromName(self::CONTRACT_PATH);
        } finally {
            $zip->close();
        }
        if (!is_string($raw) || strlen($raw) > self::MAX_CONTRACT_BYTES
            || !hash_equals($map[self::CONTRACT_PATH], hash('sha256', $raw))) {
            throw new RuntimeException('Publisher-signed database contract differs from authenticated inventory.');
        }
        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('Publisher-signed database contract JSON is invalid.');
        }
        if (!is_array($data) || array_is_list($data) || count($data) !== 4
            || ($data['format'] ?? null) !== 'adult-core-db-contract-v1'
            || ($data['family'] ?? null) !== 'adult-core-mysql'
            || !is_int($data['revision'] ?? null)
            || $data['revision'] < 1 || $data['revision'] > 1000000
            || !is_array($data['declared_migrations'] ?? null)
            || !array_is_list($data['declared_migrations'])
            || count($data['declared_migrations']) > self::MAX_MIGRATIONS) {
            throw new RuntimeException('Publisher-signed database contract has invalid fields.');
        }
        $seen = [];
        foreach ($data['declared_migrations'] as $id) {
            if (!is_string($id) || preg_match('/^[a-z0-9][a-z0-9_.-]{0,79}$/D', $id) !== 1
                || isset($seen[$id])) {
                throw new RuntimeException('Publisher-signed database migration IDs are invalid.');
            }
            $seen[$id] = true;
        }
        return $data;
    }

    /** @param list<array{path:string,sha256:string}> $files @return array<string,string> */
    private static function map(array $files): array
    {
        $map = [];
        foreach ($files as $file) $map[$file['path']] = $file['sha256'];
        return $map;
    }

    private static function dbSensitive(string $path): bool
    {
        return str_starts_with($path, 'src/Database/')
            || (str_starts_with($path, 'src/')
                && preg_match('/(?:Schema|Migration|Migrator)\.php$/D', $path) === 1)
            || in_array($path, [
                'src/Installer/InstallService.php', 'src/Licensing/EditionUpgrade.php',
            ], true);
    }
}
