<?php

declare(strict_types=1);

namespace AdultCore\Release;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Independent read-only audit for PRIVATE, installer-free runtime staging.
 * A verified stage is NOT an installation, updater, or deployment approval.
 */
final class RuntimeSourceStageVerifier
{
    /** @return array<string,mixed> */
    public static function verify(
        string $runtimeStage,
        string $sourceZip,
        string $signedManifestJson,
        string $signatureBase64,
        string $trustedPublicKeyPem
    ): array {
        // Authenticate publisher bytes BEFORE inspecting stage contents.
        $signed = SourceArchive::verifiedInventory(
            $sourceZip, $signedManifestJson, $signatureBase64, $trustedPublicKeyPem
        );
        $root = realpath($runtimeStage);
        if ($root === false || $root === '/' || !is_dir($root) || is_link($runtimeStage)) {
            throw new RuntimeException('Runtime stage is missing or has an unsafe root.');
        }
        self::requirePrivate($root);

        $expected = [];
        $directories = [];
        foreach ($signed['files'] as $record) {
            if (!SourceUpdatePreflight::runtimePath($record['path'])) continue;
            $relative = $record['path'];
            $expected[$relative] = $record['sha256'];
            $parts = explode('/', $relative);
            $prefix = [];
            foreach (array_slice($parts, 0, -1) as $part) {
                $prefix[] = $part;
                $directories[implode('/', $prefix)] = true;
            }
        }
        if (count($expected) < 3 || count($expected) >= count($signed['files'])) {
            throw new RuntimeException('Authenticated runtime source inventory is invalid.');
        }
        foreach (['VERSION', 'public/index.php', 'src/Support/Autoload.php'] as $required) {
            if (!isset($expected[$required])) {
                throw new RuntimeException('Authenticated runtime stage lacks required source.');
            }
        }
        $receipt = $root . '/.ac-runtime-stage-complete.json';
        if (is_link($receipt) || !is_file($receipt)
            || file_exists($root . '/.ac-runtime-stage-pending')
            || is_link($root . '/.ac-runtime-stage-pending')) {
            throw new RuntimeException('Runtime stage is incomplete or lacks an exact completion receipt.');
        }
        self::requirePrivate($receipt);
        $size = filesize($receipt);
        if (!is_int($size) || $size < 1 || $size > 4096) {
            throw new RuntimeException('Runtime stage receipt exceeds allowed size.');
        }
        $bytes = file_get_contents($receipt);
        if (!is_string($bytes)) {
            throw new RuntimeException('Runtime stage receipt is unreadable.');
        }
        try {
            $data = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('Runtime stage receipt is malformed.');
        }
        if (!is_array($data)
            || ($data['schema'] ?? null) !== 'adult-core-runtime-source-stage-v1'
            || ($data['status'] ?? null) !== 'runtime_stage_only'
            || ($data['version'] ?? null) !== $signed['version']
            || ($data['file_count'] ?? null) !== count($expected)
            || ($data['excluded_archive_entries'] ?? null)
                !== count($signed['files']) - count($expected)
            || ($data['signed_manifest_sha256'] ?? null) !== hash('sha256', $signedManifestJson)
            || ($data['read_only_installed'] ?? null) !== true
            || ($data['installer_excluded'] ?? null) !== true
            || ($data['install_authorized'] ?? null) !== false
            || ($data['ready_for_activation'] ?? null) !== false) {
            throw new RuntimeException('Runtime staging receipt does not match the signed release.');
        }

        $seen = [];
        $total = 0;
        $tree = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($tree as $entry) {
            $relative = substr($entry->getPathname(), strlen($root) + 1);
            if ($relative === '' || $entry->isLink()) {
                throw new RuntimeException('Runtime stage contains a symbolic link.');
            }
            self::requirePrivate($entry->getPathname());
            if ($entry->isDir()) {
                if (!isset($directories[$relative])) {
                    throw new RuntimeException('Runtime stage contains an unrecognized directory.');
                }
                continue;
            }
            if (!$entry->isFile()) {
                throw new RuntimeException('Runtime stage contains a non-regular file.');
            }
            if ($relative === '.ac-runtime-stage-complete.json') continue;
            if (!isset($expected[$relative]) || isset($seen[$relative])) {
                throw new RuntimeException('Runtime stage contains an extra or unapproved source file.');
            }
            $length = $entry->getSize();
            if ($length < 0 || $length > SourceArchive::MAX_ENTRY_BYTES
                || $total > SourceArchive::MAX_UNPACKED_BYTES - $length) {
                throw new RuntimeException('Runtime stage file size exceeds safe source bounds.');
            }
            $total += $length;
            $hash = hash_file('sha256', $entry->getPathname());
            if (!is_string($hash) || !hash_equals($expected[$relative], $hash)) {
                throw new RuntimeException('Runtime stage source differs from publisher-signed content.');
            }
            $seen[$relative] = true;
        }
        if (count($seen) !== count($expected)) {
            throw new RuntimeException('Runtime stage is missing signed source files.');
        }
        return [
            'schema' => 'adult-core-runtime-stage-verification-v1',
            'runtime_stage_verified' => true,
            'version' => $signed['version'],
            'runtime_files_verified' => count($seen),
            'excluded_archive_entries' => count($signed['files']) - count($seen),
            'installer_excluded' => true,
            'ready_for_activation' => false,
            'install_authorized' => false,
        ];
    }

    private static function requirePrivate(string $path): void
    {
        clearstatcache(true, $path);
        $mode = fileperms($path);
        if (!is_int($mode) || ($mode & 0077) !== 0) {
            throw new RuntimeException('Runtime stage file or directory is not private.');
        }
    }
}
