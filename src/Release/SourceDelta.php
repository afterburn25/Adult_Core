<?php

declare(strict_types=1);

namespace AdultCore\Release;

use RuntimeException;

/**
 * Authenticated, read-only comparison of candidate source files against a
 * local application tree. Never installs, executes or deletes anything.
 */
final class SourceDelta
{
    /** @return array<string,mixed> */
    public static function preview(
        string $installedRoot,
        string $archivePath,
        string $manifestJson,
        string $signatureBase64,
        string $trustedPublicKeyPem
    ): array {
        // Authenticate before using *any* candidate archive path locally.
        $verified = SourceArchive::verifiedInventory(
            $archivePath, $manifestJson, $signatureBase64, $trustedPublicKeyPem
        );
        $root = realpath($installedRoot);
        if ($root === false || $root === '/' || !is_dir($root) || is_link($installedRoot)) {
            throw new RuntimeException('Existing source installation root is unavailable or unsafe.');
        }
        // Requiring these real files avoids confusing public/ with the app
        // root, but does not certify any live installation or legal controls.
        foreach (['VERSION', 'public/index.php', 'src/Support/Autoload.php'] as $marker) {
            $dir = $root;
            $parts = explode('/', $marker);
            foreach (array_slice($parts, 0, -1) as $part) {
                $dir .= '/' . $part;
                if (is_link($dir) || !is_dir($dir)) {
                    throw new RuntimeException('Existing source root has an invalid directory boundary.');
                }
            }
            $file = $dir . '/' . end($parts);
            if (is_link($file) || !is_file($file) || !is_readable($file)) {
                throw new RuntimeException('Existing source root lacks required source files.');
            }
        }
        $storedVersion = file_get_contents($root . '/VERSION');
        $version = is_string($storedVersion) ? trim($storedVersion) : '';
        if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[a-z0-9.-]+)?$/D', $version)) {
            throw new RuntimeException('Existing source version is invalid.');
        }

        $wouldAdd = [];
        $wouldReplace = [];
        $same = 0;
        foreach ($verified['files'] as $file) {
            $result = self::compareOne($root, $file['path'], $file['sha256']);
            if ($result === 'add') {
                $wouldAdd[] = $file['path'];
            } elseif ($result === 'replace') {
                $wouldReplace[] = $file['path'];
            } else {
                ++$same;
            }
        }
        return [
            'schema' => 'adult-core-source-delta-v1',
            'read_only' => true,
            'complete_update_plan' => false,
            'deletions_assessed' => false,
            'installed_version' => $version,
            'candidate_version' => $verified['version'],
            'source_files' => count($verified['files']),
            'unchanged_count' => $same,
            'would_add' => $wouldAdd,
            'would_replace' => $wouldReplace,
        ];
    }

    /** @return 'same'|'add'|'replace' */
    private static function compareOne(string $root, string $relative, string $expectedHash): string
    {
        // Path comes ONLY from SourceArchive's signed, normalized inventory.
        $parts = explode('/', $relative);
        $dir = $root;
        foreach (array_slice($parts, 0, -1) as $part) {
            $dir .= '/' . $part;
            if (is_link($dir)) {
                throw new RuntimeException('Existing source contains a symbolic-link directory.');
            }
            if (!file_exists($dir)) return 'add';
            if (!is_dir($dir)) {
                throw new RuntimeException('Existing source contains a directory/file collision.');
            }
        }
        $path = $dir . '/' . end($parts);
        if (is_link($path)) {
            throw new RuntimeException('Existing source contains a symbolic-link file.');
        }
        if (!file_exists($path)) return 'add';
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Existing source contains an unsafe file boundary.');
        }
        $size = filesize($path);
        if (!is_int($size) || $size > SourceArchive::MAX_ENTRY_BYTES) {
            return 'replace'; // No unbounded hashing.
        }
        $actual = hash_file('sha256', $path);
        if (!is_string($actual)) {
            throw new RuntimeException('Existing source file cannot be hashed.');
        }
        return hash_equals($expectedHash, $actual) ? 'same' : 'replace';
    }
}
