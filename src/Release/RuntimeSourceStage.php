<?php

declare(strict_types=1);

namespace AdultCore\Release;

use RuntimeException;
use Throwable;

/**
 * Copies ONLY signed runtime source files from a verified PRIVATE full-source
 * stage to a new, private runtime-only stage. Never activates or installs.
 *
 * Immutable publisher files are distinguished from mutable customer data,
 * removed browser installer, modules, themes and developer-only artifacts.
 */
final class RuntimeSourceStage
{
    /** @return array<string,mixed> */
    public static function prepare(
        string $installedRoot,
        string $fullSourceStage,
        string $sourceZip,
        string $signedManifestJson,
        string $signatureBase64,
        string $trustedPublicKeyPem,
        string $destination
    ): array {
        // Require a complete, independently authenticated source stage.
        SourceStageVerifier::verify(
            $fullSourceStage, $sourceZip,
            $signedManifestJson, $signatureBase64, $trustedPublicKeyPem
        );
        $inventory = SourceArchive::verifiedInventory(
            $sourceZip, $signedManifestJson, $signatureBase64, $trustedPublicKeyPem
        );
        $site = realpath($installedRoot);
        $source = realpath($fullSourceStage);
        if ($site === false || $site === '/' || $source === false
            || is_link($installedRoot) || is_link($fullSourceStage)
            || !is_dir($site) || !is_dir($source)
            || $site === $source) {
            throw new RuntimeException('Runtime staging requires distinct, existing source boundaries.');
        }
        $destParent = self::privateDestination($destination, $site, $source);

        $files = [];
        foreach ($inventory['files'] as $record) {
            if (SourceUpdatePreflight::runtimePath($record['path'])) {
                $files[] = $record;
            }
        }
        if (count($files) < 3
            || count($files) >= count($inventory['files'])) {
            throw new RuntimeException('Signed runtime source selection is invalid.');
        }
        $paths = array_column($files, 'path');
        foreach (['VERSION', 'public/index.php', 'src/Support/Autoload.php'] as $required) {
            if (!in_array($required, $paths, true)) {
                throw new RuntimeException('Signed runtime stage lacks a required source file.');
            }
        }
        $excluded = count($inventory['files']) - count($files);

        // Exclusive mkdir keeps complete and incomplete staging trees immutable.
        if (file_exists($destination) || is_link($destination)
            || !mkdir($destination, 0700)
            || !chmod($destination, 0700)) {
            throw new RuntimeException('Runtime stage destination must be new and private.');
        }
        self::writeExclusive($destination . '/.ac-runtime-stage-pending', "incomplete\n");
        $total = 0;
        foreach ($files as $record) {
            self::copySigned(
                $source, $destination,
                $record['path'], $record['sha256'], $total
            );
        }
        $receipt = [
            'schema' => 'adult-core-runtime-source-stage-v1',
            'status' => 'runtime_stage_only',
            'version' => $inventory['version'],
            'file_count' => count($files),
            'excluded_archive_entries' => $excluded,
            'signed_manifest_sha256' => hash('sha256', $signedManifestJson),
            'read_only_installed' => true,
            'installer_excluded' => true,
            'install_authorized' => false,
            'ready_for_activation' => false,
        ];
        $receiptData = json_encode(
            $receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
        $temp = $destination . '/.ac-runtime-receipt-pending';
        self::writeExclusive($temp, $receiptData);
        if (!rename($temp, $destination . '/.ac-runtime-stage-complete.json')
            || !unlink($destination . '/.ac-runtime-stage-pending')) {
            throw new RuntimeException('Private runtime staging completion could not be committed.');
        }
        return [
            'schema' => 'adult-core-runtime-source-stage-v1',
            'status' => 'runtime_stage_only',
            'version' => $inventory['version'],
            'runtime_files' => count($files),
            'excluded_archive_entries' => $excluded,
            'installer_excluded' => true,
            'install_authorized' => false,
            'ready_for_activation' => false,
            'stage_path' => $destination,
        ];
    }

    private static function privateDestination(string $destination, string $site, string $source): string
    {
        if ($destination === '' || $destination[0] !== '/'
            || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,80}$/D', basename($destination))
            || in_array(basename($destination), ['.', '..'], true)
            || str_contains($destination, '//') || str_contains($destination, '/./')
            || str_contains($destination, '/../')
            || is_link(dirname($destination))) {
            throw new RuntimeException('Runtime stage destination is unsafe.');
        }
        $parent = realpath(dirname($destination));
        if ($parent === false || $parent === '/' || !is_dir($parent)
            || !is_writable($parent)
            || $destination !== $parent . '/' . basename($destination)
            || $parent === $site || str_starts_with($parent, $site . '/')
            || $parent === $source || str_starts_with($parent, $source . '/')
            || file_exists($destination) || is_link($destination)) {
            throw new RuntimeException('Runtime stage must be outside installed and signed source trees.');
        }
        $mode = fileperms($parent);
        if (!is_int($mode) || ($mode & 0077) !== 0) {
            throw new RuntimeException('Runtime stage parent must be private (0700 or stricter).');
        }
        return $parent;
    }

    private static function copySigned(
        string $source, string $destination,
        string $relative, string $expected, int &$total
    ): void {
        // The normalized path comes ONLY from authenticated SourceArchive.
        $sourcePath = $source . '/' . $relative;
        if (is_link($sourcePath) || !is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new RuntimeException('Signed source stage entry disappeared or changed type.');
        }
        $parts = explode('/', $relative);
        $dir = $destination;
        foreach (array_slice($parts, 0, -1) as $segment) {
            $dir .= '/' . $segment;
            if (is_link($dir) || (file_exists($dir) && !is_dir($dir))) {
                throw new RuntimeException('Runtime destination directory is unsafe.');
            }
            if (!is_dir($dir) && (!mkdir($dir, 0700) || !chmod($dir, 0700))) {
                throw new RuntimeException('Cannot create private runtime source directory.');
            }
        }
        $input = @fopen($sourcePath, 'rb');
        if (!is_resource($input)) {
            throw new RuntimeException('Cannot reopen signed stage for runtime copy.');
        }
        $output = @fopen($dir . '/' . end($parts), 'x+b');
        if (!is_resource($output)) {
            fclose($input);
            throw new RuntimeException('Cannot exclusively create private runtime source file.');
        }
        $hash = hash_init('sha256');
        $fileBytes = 0;
        try {
            if (!chmod($dir . '/' . end($parts), 0600)) {
                throw new RuntimeException('Cannot protect runtime source file permissions.');
            }
            while (!feof($input)) {
                $chunk = fread($input, 65536);
                if (!is_string($chunk) || ($chunk === '' && !feof($input))) {
                    throw new RuntimeException('Signed source changed while copying.');
                }
                $n = strlen($chunk);
                $fileBytes += $n;
                $total += $n;
                if ($fileBytes > SourceArchive::MAX_ENTRY_BYTES
                    || $total > SourceArchive::MAX_UNPACKED_BYTES) {
                    throw new RuntimeException('Runtime stage exceeds signed source size bounds.');
                }
                if ($n > 0 && fwrite($output, $chunk) !== $n) {
                    throw new RuntimeException('Runtime staged source write was incomplete.');
                }
                hash_update($hash, $chunk);
            }
            if (!hash_equals($expected, hash_final($hash))) {
                throw new RuntimeException('Runtime staged bytes differ from signed publisher files.');
            }
            if (!fflush($output) || (function_exists('fsync') && !fsync($output))) {
                throw new RuntimeException('Could not flush staged runtime source file.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    private static function writeExclusive(string $path, string $data): void
    {
        $handle = @fopen($path, 'x+b');
        if (!is_resource($handle)) {
            throw new RuntimeException('Cannot create private runtime staging metadata.');
        }
        try {
            if (!chmod($path, 0600)
                || fwrite($handle, $data) !== strlen($data)
                || !fflush($handle)
                || (function_exists('fsync') && !fsync($handle))) {
                throw new RuntimeException('Cannot finish private runtime stage metadata.');
            }
        } finally {
            fclose($handle);
        }
    }
}
