<?php

declare(strict_types=1);

namespace AdultCore\Release;

use RuntimeException;
use ZipArchive;

/**
 * Authenticated source ZIP -> NEW PRIVATE directory only.
 * Never replaces, executes, updates, or migrates a running installation.
 */
final class SourceStage
{
    private const PENDING = '.ac-stage-pending';
    private const RECEIPT = '.ac-stage-complete.json';

    /** @return array{schema:string,status:string,version:string,file_count:int,read_only_installed:bool,install_authorized:bool,stage_path:string} */
    public static function prepare(
        string $installedRoot,
        string $archivePath,
        string $manifestJson,
        string $signatureBase64,
        string $trustedPublicKeyPem,
        string $stagePath
    ): array {
        // Validate the current installation and authenticate all archive paths
        // before any local writes; refuse local source symlink boundaries.
        $preview = SourceDelta::preview(
            $installedRoot, $archivePath, $manifestJson, $signatureBase64, $trustedPublicKeyPem
        );
        $inventory = SourceArchive::verifiedInventory(
            $archivePath, $manifestJson, $signatureBase64, $trustedPublicKeyPem
        );
        $root = realpath($installedRoot);
        if ($root === false || is_link($installedRoot)) {
            throw new RuntimeException('Installed source root is unsafe.');
        }
        self::assertPrivateParent($stagePath, $root);
        foreach ($inventory['files'] as $record) {
            if (str_starts_with($record['path'], '.ac-stage-')) {
                throw new RuntimeException('Signed source conflicts with staging metadata.');
            }
        }
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Signed source ZIP is no longer readable.');
        }
        try {
            if (file_exists($stagePath) || is_link($stagePath)
                || !mkdir($stagePath, 0700) || !chmod($stagePath, 0700)) {
                throw new RuntimeException('Staging destination must be a new private directory.');
            }
            // A crash or failed write leaves this marker. Never silently reuse
            // or auto-delete a partial staging tree.
            $pending = $stagePath . '/' . self::PENDING;
            self::writeNew($pending, "incomplete\n");
            $total = 0;
            foreach ($inventory['files'] as $record) {
                self::stageOne($zip, $stagePath, $record['path'], $record['sha256'], $total);
            }
            $receipt = [
                'schema' => 'adult-core-private-source-stage-v1',
                'version' => $inventory['version'],
                'file_count' => count($inventory['files']),
                'source_inventory_sha256' => hash('sha256', $manifestJson),
                'read_only_installed' => true,
                'install_authorized' => false,
                'status' => 'staged_only',
            ];
            $bytes = json_encode(
                $receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . "\n";
            $temp = $stagePath . '/.ac-stage-receipt-pending';
            self::writeNew($temp, $bytes);
            if (!rename($temp, $stagePath . '/' . self::RECEIPT)) {
                throw new RuntimeException('Cannot publish private staging receipt.');
            }
            if (!unlink($pending)) {
                throw new RuntimeException('Cannot clear incomplete staging marker.');
            }
            return [
                'schema' => 'adult-core-private-source-stage-v1',
                'status' => 'staged_only',
                'version' => (string)$preview['candidate_version'],
                'file_count' => count($inventory['files']),
                'read_only_installed' => true,
                'install_authorized' => false,
                'stage_path' => $stagePath,
            ];
        } finally {
            $zip->close();
        }
    }

    private static function assertPrivateParent(string $destination, string $installedRoot): void
    {
        if ($destination === '' || $destination[0] !== '/'
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,80}$/D', basename($destination))
            || in_array(basename($destination), ['.', '..'], true)
            || str_contains($destination, '//')
            || str_contains($destination, '/../')
            || str_contains($destination, '/./')
            || is_link(dirname($destination))) {
            throw new RuntimeException('Staging destination path is unsafe.');
        }
        $parent = realpath(dirname($destination));
        if ($parent === false || !is_dir($parent) || !is_writable($parent)
            || $parent === '/' || $parent === $installedRoot
            || str_starts_with($parent, $installedRoot . '/')
            || $destination !== $parent . '/' . basename($destination)) {
            throw new RuntimeException('Staging must be outside the installed application.');
        }
        $mode = fileperms($parent);
        if (!is_int($mode) || ($mode & 0077) !== 0) {
            throw new RuntimeException('Staging parent must be private (0700 or stricter).');
        }
        if (file_exists($destination) || is_link($destination)) {
            throw new RuntimeException('Staging destination already exists.');
        }
    }

    private static function stageOne(
        ZipArchive $zip, string $root, string $relative,
        string $expectedHash, int &$total
    ): void {
        $parts = explode('/', $relative);
        $dir = $root;
        foreach (array_slice($parts, 0, -1) as $part) {
            $dir .= '/' . $part;
            if (is_link($dir)) {
                throw new RuntimeException('Staging tree contains a symbolic-link directory.');
            }
            if (!is_dir($dir) && (!mkdir($dir, 0700) || !chmod($dir, 0700))) {
                throw new RuntimeException('Cannot create private staging directory.');
            }
            if (!is_dir($dir)) {
                throw new RuntimeException('Staging source path collision.');
            }
        }
        $file = $dir . '/' . end($parts);
        $input = $zip->getStream($relative);
        if (!is_resource($input)) {
            throw new RuntimeException('Signed source entry cannot be reopened.');
        }
        $output = @fopen($file, 'x+b');
        if (!is_resource($output)) {
            fclose($input);
            throw new RuntimeException('Cannot create exclusive private staged source file.');
        }
        $hash = hash_init('sha256');
        $fileBytes = 0;
        try {
            if (!chmod($file, 0600)) {
                throw new RuntimeException('Cannot protect staged source file permissions.');
            }
            while (!feof($input)) {
                $chunk = fread($input, 65536);
                if (!is_string($chunk) || ($chunk === '' && !feof($input))) {
                    throw new RuntimeException('Signed source entry could not be read.');
                }
                $size = strlen($chunk);
                $fileBytes += $size;
                $total += $size;
                if ($fileBytes > SourceArchive::MAX_ENTRY_BYTES
                    || $total > SourceArchive::MAX_UNPACKED_BYTES) {
                    throw new RuntimeException('Staging exceeds signed source size limit.');
                }
                if ($size > 0 && fwrite($output, $chunk) !== $size) {
                    throw new RuntimeException('Staged source entry is partially written.');
                }
                hash_update($hash, $chunk);
            }
            if (!hash_equals($expectedHash, hash_final($hash))) {
                throw new RuntimeException('Signed source changed during staging.');
            }
            if (!fflush($output) || (function_exists('fsync') && !fsync($output))) {
                throw new RuntimeException('Could not flush private staged file.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    private static function writeNew(string $path, string $bytes): void
    {
        $handle = @fopen($path, 'x+b');
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not create exclusive stage metadata.');
        }
        try {
            if (!chmod($path, 0600) || fwrite($handle, $bytes) !== strlen($bytes)
                || !fflush($handle)
                || (function_exists('fsync') && !fsync($handle))) {
                throw new RuntimeException('Could not safely finish stage metadata.');
            }
        } finally {
            fclose($handle);
        }
    }
}
