<?php

declare(strict_types=1);

/**
 * Offline, read-only, fail-closed transition inventory check.
 * This intentionally NEVER switches an active website or authorizes updating.
 */
require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Release\SourceUpdatePreflight;
use AdultCore\Database\Connection;
use AdultCore\Installer\InstallationGate;

if (PHP_SAPI !== 'cli' || !in_array(count($argv), [10, 11], true)
    || (count($argv) === 11 && $argv[10] !== '--verify-installed-db')) {
    fwrite(STDERR, "Usage: php scripts/preflight-source-transition.php installed-root staged-candidate old.zip old.manifest.json old.manifest.sig new.zip new.manifest.json new.manifest.sig trusted-public.pem [--verify-installed-db]\n");
    exit(64);
}

[, $root, $stage, $oldZip, $oldManifestPath, $oldSigPath,
    $newZip, $newManifestPath, $newSigPath, $trustedKeyPath] = $argv;

try {
    foreach ([
        $oldManifestPath => 262144, $oldSigPath => 8192,
        $newManifestPath => 262144, $newSigPath => 8192, $trustedKeyPath => 8192,
    ] as $path => $limit) {
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('Detached release verification input is missing or unsafe.');
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 1 || $size > $limit) {
            throw new RuntimeException('Detached verification input has an invalid size.');
        }
    }
    $oldJson = file_get_contents($oldManifestPath);
    $oldSignature = file_get_contents($oldSigPath);
    $newJson = file_get_contents($newManifestPath);
    $newSignature = file_get_contents($newSigPath);
    $key = file_get_contents($trustedKeyPath);
    if (!is_string($oldJson) || !is_string($oldSignature)
        || !is_string($newJson) || !is_string($newSignature)
        || !is_string($key)) {
        throw new RuntimeException('Detached source verification input cannot be read.');
    }
    // The opt-in live ledger audit requires a complete, signed installation
    // before loading private installed DB config. No credentials are printed.
    $pdo = null;
    if (count($argv) === 11) {
        $status = (new InstallationGate($root))->status();
        if (!in_array($status, [
            InstallationGate::READY, InstallationGate::SOURCE_UPDATE_MAINTENANCE,
        ], true)) {
            throw new RuntimeException('Installed DB audit requires a complete signed installation.');
        }
        $pdo = Connection::open($root);
    }
    $report = SourceUpdatePreflight::inspect(
        $root, $stage, $oldZip, $oldJson, $oldSignature,
        $newZip, $newJson, $newSignature, $key, $pdo
    );
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    fwrite(STDERR, "Signed source preflight completed. NO activation, rollback, migration or production authorization.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Signed source transition REFUSED: " . $error->getMessage() . "\n");
    exit(1);
}
