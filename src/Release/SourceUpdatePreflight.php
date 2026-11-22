<?php

declare(strict_types=1);

namespace AdultCore\Release;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Offline, read-only transition preflight for an independently signed previous
 * release and a separately authenticated candidate PRIVATE stage.
 *
 * No deployment, rollback, compatibility certification or write capability.
 */
final class SourceUpdatePreflight
{
    private const MAX_SCAN_ENTRIES = 5000;

    /**
     * Candidate installer, developer/test assets, licensed add-ons and local
     * configuration are NEVER part of this runtime transition inventory.
     */
    public static function runtimePath(string $path): bool
    {
        return $path === 'VERSION'
            || str_starts_with($path, 'src/')
            || str_starts_with($path, 'public/')
            || str_starts_with($path, 'config/editions/');
    }

    /**
     * @return array<string,mixed>
     */
    public static function inspect(
        string $installedRoot,
        string $stagedCandidate,
        string $baselineZip,
        string $baselineManifest,
        string $baselineSignature,
        string $candidateZip,
        string $candidateManifest,
        string $candidateSignature,
        string $trustedPublicKeyPem
    ): array {
        // Both releases MUST authenticate independently before reading an
        // installed source path or relying on staging receipt metadata.
        $baseline = SourceArchive::verifiedInventory(
            $baselineZip, $baselineManifest, $baselineSignature, $trustedPublicKeyPem
        );
        $candidate = SourceArchive::verifiedInventory(
            $candidateZip, $candidateManifest, $candidateSignature, $trustedPublicKeyPem
        );
        $stage = SourceStageVerifier::verify(
            $stagedCandidate, $candidateZip, $candidateManifest,
            $candidateSignature, $trustedPublicKeyPem
        );
        if (!$stage['stage_verified'] || $stage['version'] !== $candidate['version']) {
            throw new RuntimeException('Candidate stage verification failed.');
        }
        if (!version_compare($candidate['version'], $baseline['version'], '>')) {
            throw new RuntimeException('Candidate release must be newer than the signed installed baseline.');
        }

        // Review both publisher-signed database declarations within the
        // SAME offline transition. A missing/reordered/forged DB contract
        // cannot be silently skipped by an operator using this preflight.
        // This flags migration risk; it is NEVER a SQL compatibility proof.
        $databaseReview = SignedCandidateDbContract::compare(
            $baselineZip, $baselineManifest, $baselineSignature,
            $candidateZip, $candidateManifest, $candidateSignature,
            $trustedPublicKeyPem
        );

        $root = realpath($installedRoot);
        if ($root === false || $root === '/' || !is_dir($root) || is_link($installedRoot)) {
            throw new RuntimeException('Installed application source root is unsafe.');
        }
        if ($root === realpath($stagedCandidate)) {
            throw new RuntimeException('A staged source cannot be the installed application root.');
        }
        $localVersion = self::regularFile($root, 'VERSION');
        if ($localVersion === null) {
            throw new RuntimeException('Installed application has no VERSION source marker.');
        }
        $bytes = file_get_contents($localVersion);
        if (!is_string($bytes) || trim($bytes) !== $baseline['version']) {
            throw new RuntimeException('Installed version does not match signed baseline.');
        }

        $previous = self::runtimeMap($baseline['files']);
        $next = self::runtimeMap($candidate['files']);
        if (!isset($previous['VERSION'], $previous['public/index.php'],
                   $previous['src/Support/Autoload.php'])
            || !isset($next['VERSION'], $next['public/index.php'],
                       $next['src/Support/Autoload.php'])) {
            throw new RuntimeException('Signed runtime inventory is incomplete.');
        }

        // ALL previous runtime files must be byte-identical to the baseline.
        // This prevents overwriting private local patches or changed code.
        foreach ($previous as $relative => $digest) {
            $path = self::regularFile($root, $relative);
            if ($path === null) {
                throw new RuntimeException('Installed signed runtime source is missing.');
            }
            $size = filesize($path);
            if (!is_int($size) || $size > SourceArchive::MAX_ENTRY_BYTES) {
                throw new RuntimeException('Installed signed runtime file is oversized.');
            }
            $hash = hash_file('sha256', $path);
            if (!is_string($hash) || !hash_equals($digest, $hash)) {
                throw new RuntimeException('Installed runtime differs from signed baseline.');
            }
        }
        self::rejectUnmanagedExecutable($root, $previous);

        $added = [];
        $changed = [];
        $unchanged = 0;
        foreach ($next as $relative => $digest) {
            if (!isset($previous[$relative])) {
                // An update must never take over an unowned local path.
                if (self::regularFile($root, $relative) !== null) {
                    throw new RuntimeException('New signed runtime path conflicts with local data.');
                }
                $added[] = $relative;
            } elseif (!hash_equals($previous[$relative], $digest)) {
                $changed[] = $relative;
            } else {
                ++$unchanged;
            }
        }
        // File removals require separate, audited migration and rollback
        // handling. Never silently ignore an old executable.
        foreach ($previous as $relative => $_) {
            if (!isset($next[$relative])) {
                throw new RuntimeException('Candidate would remove an installed source file.');
            }
        }

        return [
            'schema' => 'adult-core-signed-runtime-preflight-v1',
            'read_only' => true,
            'source_integrity_verified' => true,
            'candidate_stage_verified' => true,
            'ready_for_activation' => false,
            'rollback_implemented' => false,
            'licensing_checked' => false,
            'migration_compatibility_checked' => false,
            'signed_database_contract_checked' => $databaseReview['both_source_archives_authenticated'],
            'declared_database_contract_changed' => $databaseReview['declared_database_contract_changed'],
            'new_declared_migration_ids' => $databaseReview['new_declared_migrations'],
            'database_sensitive_source_changes' => $databaseReview['db_sensitive_source_changes'],
            'manual_database_review_required' =>
                $databaseReview['manual_database_compatibility_review_required'],
            'installed_database_migrations_checked' => false,
            'installed_version' => $baseline['version'],
            'candidate_version' => $candidate['version'],
            'installed_runtime_files_checked' => count($previous),
            'candidate_runtime_files' => count($next),
            'archive_entries_excluded' => count($candidate['files']) - count($next),
            'installer_excluded' => true,
            'would_add' => $added,
            'would_replace' => $changed,
            'unchanged_count' => $unchanged,
        ];
    }

    /**
     * @param list<array{path:string,sha256:string}> $records
     * @return array<string,string>
     */
    private static function runtimeMap(array $records): array
    {
        $map = [];
        foreach ($records as $entry) {
            if (self::runtimePath($entry['path'])) {
                $map[$entry['path']] = $entry['sha256'];
            }
        }
        return $map;
    }

    /**
     * Does not follow symlinks in any component of the signed relative path.
     * Missing filenames may be treated as candidate additions by the caller.
     */
    private static function regularFile(string $root, string $relative): ?string
    {
        $parts = explode('/', $relative);
        $dir = $root;
        foreach (array_slice($parts, 0, -1) as $part) {
            $dir .= '/' . $part;
            if (is_link($dir)) {
                throw new RuntimeException('Installed source contains a symlink directory.');
            }
            if (!file_exists($dir)) return null;
            if (!is_dir($dir)) {
                throw new RuntimeException('Installed source directory collides with a file.');
            }
        }
        $path = $dir . '/' . end($parts);
        if (is_link($path)) {
            throw new RuntimeException('Installed source contains a symlink file.');
        }
        if (!file_exists($path)) return null;
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Installed source path is not a readable regular file.');
        }
        return $path;
    }

    /**
     * Reject unsigned PHP executables under core source and public trees.
     * This intentionally does not walk customer storage or private media.
     *
     * @param array<string,string> $baseline
     */
    private static function rejectUnmanagedExecutable(string $root, array $baseline): void
    {
        $inspected = 0;
        foreach (['src', 'public'] as $folder) {
            $base = $root . '/' . $folder;
            if (is_link($base) || !is_dir($base)) {
                throw new RuntimeException('Required installed executable tree is missing or unsafe.');
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($iterator as $entry) {
                if (++$inspected > self::MAX_SCAN_ENTRIES) {
                    throw new RuntimeException('Installed executable scan exceeds safety limit.');
                }
                if ($entry->isLink()) {
                    throw new RuntimeException('Installed executable tree contains a symlink.');
                }
                if ($entry->isDir()) continue;
                if (!$entry->isFile()) {
                    throw new RuntimeException('Installed source includes an unsupported file type.');
                }
                $relative = substr($entry->getPathname(), strlen($root) + 1);
                if (preg_match('/\.(?:php|phtml|phar)(?:[0-9])?$/iD', $relative) === 1
                    && !isset($baseline[$relative])) {
                    throw new RuntimeException('Installed executable is absent from signed baseline.');
                }
            }
        }
    }
}
