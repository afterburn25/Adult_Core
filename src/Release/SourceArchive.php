<?php

declare(strict_types=1);

namespace AdultCore\Release;

use RuntimeException;
use ZipArchive;

/**
 * Strict, offline inventory of a unified Lite/Pro source distribution ZIP.
 * Does not unpack, execute, install, activate, or contact any license server.
 */
final class SourceArchive
{
    public const MAX_ARCHIVE_BYTES = 64 * 1024 * 1024;
    public const MAX_UNPACKED_BYTES = 128 * 1024 * 1024;
    public const MAX_ENTRY_BYTES = 16 * 1024 * 1024;
    public const MAX_ENTRIES = 2000;

    private const REQUIRED = [
        'VERSION',
        'public/index.php',
        'public/.htaccess',
        'install/index.php',
        'config/editions/lite.json',
        'config/editions/pro.json',
        'src/Support/Autoload.php',
    ];

    /**
     * @return array{version:string, files:list<array{path:string,sha256:string}>}
     */
    public static function inventory(string $archivePath): array
    {
        if (!is_file($archivePath) || is_link($archivePath)) {
            throw new RuntimeException('Release ZIP is unavailable or unsafe.');
        }
        $archiveBytes = filesize($archivePath);
        if (!is_int($archiveBytes) || $archiveBytes < 1 || $archiveBytes > self::MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('Release ZIP exceeds the offline verification limit.');
        }
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Release ZIP could not be opened.');
        }
        try {
            if ($zip->numFiles < count(self::REQUIRED) || $zip->numFiles > self::MAX_ENTRIES) {
                throw new RuntimeException('Release ZIP has an invalid number of files.');
            }
            $files = [];
            $caseInsensitive = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $entry = $zip->statIndex($i);
                $name = is_array($entry) ? ($entry['name'] ?? null) : null;
                if (!is_string($name) || !self::safePath($name)
                    || isset($files[$name]) || isset($caseInsensitive[strtolower($name)])) {
                    throw new RuntimeException('Release ZIP contains an unsafe or duplicate path.');
                }
                $caseInsensitive[strtolower($name)] = true;
                $size = $entry['size'] ?? null;
                if (!is_int($size) || $size < 0 || $size > self::MAX_ENTRY_BYTES
                    || $total > self::MAX_UNPACKED_BYTES - $size) {
                    throw new RuntimeException('Release ZIP contains an oversized entry.');
                }
                $total += $size;
                // Unix ZIP external file-type bits must describe a normal file.
                // In particular, symlinks must never be signed for installation.
                $opsys = 0;
                $attributes = 0;
                if (!$zip->getExternalAttributesIndex($i, $opsys, $attributes)) {
                    throw new RuntimeException('Release ZIP file attributes are unreadable.');
                }
                if ($opsys === 3) {
                    $type = ($attributes >> 16) & 0170000;
                    $perms = ($attributes >> 16) & 07777;
                    if (($type !== 0 && $type !== 0100000) || ($perms & 07022) !== 0) {
                        throw new RuntimeException('Release ZIP contains unsafe Unix file permissions.');
                    }
                }
                $stream = $zip->getStream($name);
                if (!is_resource($stream)) {
                    throw new RuntimeException('Release ZIP entry cannot be read.');
                }
                $hash = hash_init('sha256');
                $read = 0;
                try {
                    while (!feof($stream)) {
                        $chunk = fread($stream, 65536);
                        if (!is_string($chunk) || ($chunk === '' && !feof($stream))) {
                            throw new RuntimeException('Release ZIP entry is unreadable.');
                        }
                        $read += strlen($chunk);
                        if ($read > $size) {
                            throw new RuntimeException('Release ZIP entry size mismatch.');
                        }
                        hash_update($hash, $chunk);
                    }
                } finally {
                    fclose($stream);
                }
                if ($read !== $size) {
                    throw new RuntimeException('Release ZIP entry is truncated.');
                }
                $files[$name] = hash_final($hash);
            }
            foreach (self::REQUIRED as $required) {
                if (!isset($files[$required])) {
                    throw new RuntimeException('Release ZIP lacks required unified source files.');
                }
            }
            $versionBytes = $zip->getFromName('VERSION');
            $version = is_string($versionBytes) ? trim($versionBytes) : '';
            if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[a-z0-9.-]+)?$/D', $version)) {
                throw new RuntimeException('Release ZIP version is invalid.');
            }
            ksort($files, SORT_STRING);
            $records = [];
            foreach ($files as $path => $sha256) {
                $records[] = ['path' => $path, 'sha256' => $sha256];
            }
            return ['version' => $version, 'files' => $records];
        } finally {
            $zip->close();
        }
    }

    public static function manifestJson(string $archivePath): string
    {
        $inventory = self::inventory($archivePath);
        return json_encode([
            'format' => 'adult-core-release-v1',
            'version' => $inventory['version'],
            'files' => $inventory['files'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * Only an independently trusted public key validates this detached
     * signature. A valid manifest alone does not authorize an update.
     */
    public static function verify(
        string $archivePath,
        string $signedManifestJson,
        string $signatureBase64,
        string $trustedPublicKeyPem
    ): string {
        // Verify authenticity FIRST, before trusting any declared digest/path.
        $signed = SignedManifest::verify($signedManifestJson, trim($signatureBase64), $trustedPublicKeyPem);
        $actual = self::inventory($archivePath);
        if ($signed['version'] !== $actual['version']
            || count($signed['files']) !== count($actual['files'])) {
            throw new RuntimeException('Signed release does not match ZIP inventory.');
        }
        $expected = [];
        foreach ($signed['files'] as $record) {
            $expected[$record['path']] = $record['sha256'];
        }
        foreach ($actual['files'] as $record) {
            if (!isset($expected[$record['path']])
                || !hash_equals($expected[$record['path']], $record['sha256'])) {
                throw new RuntimeException('Signed release does not match ZIP contents.');
            }
        }
        return $actual['version'];
    }

    private static function safePath(string $path): bool
    {
        if (strlen($path) > 200
            || !preg_match('#^(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+$#D', $path)
            || str_contains($path, '..')
            || preg_match('#(?:^|/)(?:storage|private|vendor|\.git)(?:/|$)#i', $path) === 1
            || preg_match('#(?:^|/)\.env(?:\..*)?$#i', $path) === 1
            || preg_match('#^config/[^/]+\.php$#i', $path) === 1
            || preg_match('/\.(?:pem|key|log|pyc|pyo)$/iD', $path) === 1) {
            return false;
        }
        return true;
    }
}
