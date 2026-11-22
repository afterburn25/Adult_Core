<?php

declare(strict_types=1);

namespace AdultCore\Release;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Read-only integrity audit of a completed PRIVATE source stage.
 * It cannot activate an update or certify a live PHP deployment.
 */
final class SourceStageVerifier
{
    /** @return array{schema:string,stage_verified:bool,ready_for_install:bool,version:string,verified_files:int,unexpected_files:int} */
    public static function verify(
        string $stagePath,
        string $archivePath,
        string $manifestJson,
        string $signatureBase64,
        string $trustedPublicKeyPem
    ): array {
        // Authentication FIRST: a locally supplied manifest cannot grant
        // permission to browse arbitrary files in a staging directory.
        $signed = SourceArchive::verifiedInventory(
            $archivePath, $manifestJson, $signatureBase64, $trustedPublicKeyPem
        );
        $root = realpath($stagePath);
        if ($root === false || $root === '/' || !is_dir($root) || is_link($stagePath)) {
            throw new RuntimeException('Source staging root is missing or unsafe.');
        }
        self::assertPrivate($root);
        $expected = [];
        $dirs = [];
        foreach ($signed['files'] as $record) {
            $expected[$record['path']] = $record['sha256'];
            $parts = explode('/', $record['path']);
            $prefix = [];
            foreach (array_slice($parts, 0, -1) as $part) {
                $prefix[] = $part;
                $dirs[implode('/', $prefix)] = true;
            }
        }
        $receiptPath = $root . '/.ac-stage-complete.json';
        if (is_link($receiptPath) || !is_file($receiptPath)
            || file_exists($root . '/.ac-stage-pending')
            || is_link($root . '/.ac-stage-pending')) {
            throw new RuntimeException('Source stage is incomplete or lacks trusted completion evidence.');
        }
        $receipt = file_get_contents($receiptPath);
        if (!is_string($receipt) || strlen($receipt) > 4096) {
            throw new RuntimeException('Private staging receipt cannot be inspected safely.');
        }
        try {
            $decoded = json_decode($receipt, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('Staging receipt is malformed.');
        }
        if (!is_array($decoded)
            || ($decoded['schema'] ?? null) !== 'adult-core-private-source-stage-v1'
            || ($decoded['status'] ?? null) !== 'staged_only'
            || ($decoded['install_authorized'] ?? null) !== false
            || ($decoded['read_only_installed'] ?? null) !== true
            || ($decoded['version'] ?? null) !== $signed['version']
            || ($decoded['file_count'] ?? null) !== count($signed['files'])
            || ($decoded['source_inventory_sha256'] ?? null) !== hash('sha256', $manifestJson)) {
            throw new RuntimeException('Source staging receipt does not match authenticated release.');
        }
        $seen = [];
        $tree = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($tree as $entry) {
            $relative = substr($entry->getPathname(), strlen($root) + 1);
            if ($relative === '' || $entry->isLink()) {
                throw new RuntimeException('Staged source tree contains an unsafe symbolic link.');
            }
            self::assertPrivate($entry->getPathname());
            if ($entry->isDir()) {
                if (!isset($dirs[$relative])) {
                    throw new RuntimeException('Staged source tree contains an unexpected directory.');
                }
                continue;
            }
            if (!$entry->isFile()) {
                throw new RuntimeException('Staged source tree contains a non-regular file.');
            }
            if ($relative === '.ac-stage-complete.json') continue;
            if (!isset($expected[$relative]) || isset($seen[$relative])) {
                throw new RuntimeException('Staged source tree contains an unlisted file.');
            }
            $size = $entry->getSize();
            if ($size < 0 || $size > SourceArchive::MAX_ENTRY_BYTES) {
                throw new RuntimeException('Staged source file exceeds its verified size limit.');
            }
            $hash = hash_file('sha256', $entry->getPathname());
            if (!is_string($hash) || !hash_equals($expected[$relative], $hash)) {
                throw new RuntimeException('Private staged source file does not match its signed hash.');
            }
            $seen[$relative] = true;
        }
        if (count($seen) !== count($expected)) {
            throw new RuntimeException('Private staged source tree is missing signed files.');
        }
        return [
            'schema' => 'adult-core-private-stage-verification-v1',
            'stage_verified' => true,
            'ready_for_install' => false,
            'version' => $signed['version'],
            'verified_files' => count($seen),
            'unexpected_files' => 0,
        ];
    }

    private static function assertPrivate(string $path): void
    {
        clearstatcache(true, $path);
        $mode = fileperms($path);
        if (!is_int($mode) || ($mode & 0077) !== 0) {
            throw new RuntimeException('Staged source permissions are not private.');
        }
    }
}
