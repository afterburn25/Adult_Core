<?php

declare(strict_types=1);

namespace AdultCore\Package;

use AdultCore\Module\ModuleCompatibility;
use JsonException;
use PharData;
use Phar;
use RuntimeException;
use Throwable;

/**
 * Verifies an offline-signed, immutable, data-only extension ZIP.
 * No PHP/JS execution, extracted symlinks, URL downloads, or trust-on-first-use.
 */
final class PackageVerifier
{
    public const MAX_ARCHIVE_BYTES = 8_388_608;
    public const MAX_TOTAL_BYTES = 16_777_216;
    public const MAX_ENTRY_BYTES = 4_194_304;
    public const MAX_FILES = 80;

    public function __construct(private readonly string $trustedPublisherDir)
    {
    }

    /** @return array{manifest: array<string, mixed>, files: array<string, string>, signature: string, raw_manifest: string} */
    public function inspect(string $zipFile): array
    {
        if (!class_exists(PharData::class) || !is_file($zipFile) || is_link($zipFile)
            || !is_readable($zipFile) || (filesize($zipFile) ?: 0) > self::MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('Package is unavailable, too large, or PHP Phar ZIP support is disabled.');
        }
        try {
            $archive = new PharData($zipFile, 0, null, Phar::ZIP);
        } catch (Throwable) {
            throw new RuntimeException('Package is not a readable ZIP archive.');
        }
        $entries = [];
        $size = 0;
        foreach (new \RecursiveIteratorIterator($archive, \RecursiveIteratorIterator::LEAVES_ONLY) as $info) {
            $prefix = 'phar://' . str_replace('\\', '/', $zipFile) . '/';
            $name = $info->getPathname();
            if (!str_starts_with($name, $prefix)) {
                throw new RuntimeException('Unsafe archive entry.');
            }
            $name = substr($name, strlen($prefix));
            self::assertArchivePath($name);
            if ($info->isLink() || !$info->isFile() || isset($entries[$name])) {
                throw new RuntimeException('Package contains unsupported links or duplicate files.');
            }
            if (count($entries) >= self::MAX_FILES || $info->getSize() > self::MAX_ENTRY_BYTES) {
                throw new RuntimeException('Package exceeds entry limits.');
            }
            $size += $info->getSize();
            if ($size > self::MAX_TOTAL_BYTES) {
                throw new RuntimeException('Package exceeds uncompressed size limit.');
            }
            $data = $info->getContent();
            if (!is_string($data) || strlen($data) !== $info->getSize()) {
                throw new RuntimeException('Could not read archive entry.');
            }
            $entries[$name] = $data;
        }
        if (!isset($entries['package.json'], $entries['package.sig'])) {
            throw new RuntimeException('Signed package manifest is missing.');
        }
        $manifest = $this->verifyManifest($entries['package.json'], $entries['package.sig']);
        $expected = ['package.json', 'package.sig'];
        $files = [];
        foreach ($manifest['files'] as $entry) {
            $path = $entry['path'];
            $zipPath = 'payload/' . $path;
            if (!isset($entries[$zipPath]) || !hash_equals($entry['sha256'], hash('sha256', $entries[$zipPath]))) {
                throw new RuntimeException('Package content differs from the signed file manifest.');
            }
            $expected[] = $zipPath;
            $files[$path] = $entries[$zipPath];
        }
        if (count($entries) !== count($expected) || array_diff(array_keys($entries), $expected) !== []) {
            throw new RuntimeException('Package contains unsigned or unexpected files.');
        }
        return ['manifest' => $manifest, 'files' => $files, 'signature' => $entries['package.sig'], 'raw_manifest' => $entries['package.json']];
    }

    /** @return array<string, mixed> */
    public function verifyManifest(string $raw, string $signature): array
    {
        if (strlen($raw) > 32768 || strlen($signature) > 2048) {
            throw new RuntimeException('Signature or manifest too large.');
        }
        try {
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Package manifest contains invalid JSON.');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new RuntimeException('Invalid package manifest.');
        }
        foreach (['id', 'publisher', 'name', 'version', 'minimum_core_version'] as $field) {
            if (!is_string($data[$field] ?? null) || trim($data[$field]) === '' || strlen($data[$field]) > 120) {
                throw new RuntimeException('Invalid package ' . $field . '.');
            }
        }
        if (!preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $data['id'])
            || !preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $data['publisher'])
            || !preg_match('/^\d+\.\d+\.\d+$/D', $data['version'])
            || !preg_match('/^\d+\.\d+\.\d+$/D', $data['minimum_core_version'])
            || !in_array($data['type'] ?? null, ['module', 'theme'], true)) {
            throw new RuntimeException('Invalid package identifier, version or type.');
        }
        foreach (['editions', 'required_capabilities', 'dependencies'] as $field) {
            if (!is_array($data[$field] ?? null) || !array_is_list($data[$field])) {
                throw new RuntimeException('Invalid package field ' . $field . '.');
            }
            foreach ($data[$field] as $item) {
                if (!is_string($item) || !preg_match('/^[a-z][a-z0-9_-]{1,63}$/D', $item)) {
                    throw new RuntimeException('Invalid package requirement.');
                }
            }
        }
        if ($data['editions'] === [] || array_diff($data['editions'], ['lite', 'pro']) !== []
            || count(array_unique($data['dependencies'])) !== count($data['dependencies'])) {
            throw new RuntimeException('Invalid edition or dependency list.');
        }
        if (!is_array($data['files'] ?? null) || !array_is_list($data['files']) || count($data['files']) > self::MAX_FILES - 2) {
            throw new RuntimeException('Invalid signed file list.');
        }
        $paths = [];
        foreach ($data['files'] as $file) {
            if (!is_array($file) || !is_string($file['path'] ?? null)
                || !is_string($file['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $file['sha256'])) {
                throw new RuntimeException('Invalid signed file descriptor.');
            }
            self::assertPayloadPath($file['path']);
            if (isset($paths[$file['path']])) {
                throw new RuntimeException('Duplicate signed file paths.');
            }
            $paths[$file['path']] = true;
        }
        // No user-uploaded key can implicitly become trusted; administrators provision
        // trust anchors out of band in the nonpublic config/trusted-publishers/ folder.
        $keyFile = $this->trustedPublisherDir . '/' . $data['publisher'] . '.pem';
        if (!is_file($keyFile) || is_link($keyFile) || !is_readable($keyFile)) {
            throw new RuntimeException('Publisher is not trusted on this installation.');
        }
        $key = file_get_contents($keyFile);
        $decoded = base64_decode(trim($signature), true);
        if (!is_string($key) || strlen($key) > 8192 || $decoded === false
            || openssl_verify($raw, $decoded, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('Package signature verification failed.');
        }
        return $data;
    }

    public function verifyInstalled(string $directory): array
    {
        if (!is_dir($directory) || is_link($directory)
            || !is_file($directory . '/package.json') || !is_file($directory . '/package.sig')) {
            throw new RuntimeException('Installed package is missing or invalid.');
        }
        $raw = file_get_contents($directory . '/package.json');
        $sig = file_get_contents($directory . '/package.sig');
        if (!is_string($raw) || !is_string($sig)) {
            throw new RuntimeException('Installed package metadata unreadable.');
        }
        $manifest = $this->verifyManifest($raw, $sig);
        $expected = ['package.json' => true, 'package.sig' => true];
        foreach ($manifest['files'] as $file) {
            $expected['payload/' . $file['path']] = true;
            $destination = $directory . '/payload/' . $file['path'];
            if (!is_file($destination) || is_link($destination) || filesize($destination) > self::MAX_ENTRY_BYTES
                || !hash_equals($file['sha256'], (string) hash_file('sha256', $destination))) {
                throw new RuntimeException('Installed package integrity check failed.');
            }
        }
        $base = rtrim($directory, '/') . '/';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        $seen = [];
        foreach ($iterator as $info) {
            if ($info->isLink() || !$info->isFile()) {
                throw new RuntimeException('Unexpected installed package path.');
            }
            $path = substr($info->getPathname(), strlen($base));
            if (!isset($expected[$path]) || isset($seen[$path])) {
                throw new RuntimeException('Installed package contains unsigned files.');
            }
            $seen[$path] = true;
        }
        if (count($seen) !== count($expected)) {
            throw new RuntimeException('Installed package files are missing.');
        }
        return $manifest;
    }

    private static function assertArchivePath(string $path): void
    {
        if (strlen($path) > 220 || str_contains($path, '\\') || str_contains($path, "\0")
            || str_starts_with($path, '/') || str_contains($path, '//')
            || preg_match('#(^|/)\.\.?(/|$)#', $path)
            || !preg_match('#^[A-Za-z0-9_./-]+$#D', $path)) {
            throw new RuntimeException('Unsafe archive path.');
        }
        if (!in_array($path, ['package.json', 'package.sig'], true) && !str_starts_with($path, 'payload/')) {
            throw new RuntimeException('Unexpected archive path.');
        }
        if (str_starts_with($path, 'payload/')) {
            self::assertPayloadPath(substr($path, 8));
        }
    }

    private static function assertPayloadPath(string $path): void
    {
        if (strlen($path) > 190 || str_contains($path, '\\') || str_contains($path, "\0")
            || str_starts_with($path, '/') || str_contains($path, '//')
            || preg_match('#(^|/)\.\.?(/|$)#', $path)
            || !preg_match('#^[A-Za-z0-9_./-]+$#D', $path)
            || !preg_match('/\.(?:css|json|txt|md|png|jpe?g|webp)$/iD', $path)) {
            throw new RuntimeException('Unsafe or unsupported package payload path.');
        }
    }
}
