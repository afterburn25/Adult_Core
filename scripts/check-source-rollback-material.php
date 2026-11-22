<?php

declare(strict_types=1);

/** Read-only, offline: verify separate signed previous/current source stages. */
require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Release\SourceRollbackReadiness;

if (PHP_SAPI !== 'cli' || count($argv) !== 11) {
    fwrite(STDERR, "Usage: php scripts/check-source-rollback-material.php installed-root previous-stage candidate-stage previous.zip previous.manifest.json previous.sig candidate.zip candidate.manifest.json candidate.sig trusted-public.pem\n");
    exit(64);
}
[, $root, $oldStage, $newStage, $oldZip, $oldManifestPath, $oldSigPath,
    $newZip, $newManifestPath, $newSigPath, $keyPath] = $argv;
try {
    foreach ([
        $oldManifestPath => 262144, $oldSigPath => 8192,
        $newManifestPath => 262144, $newSigPath => 8192, $keyPath => 8192,
    ] as $path => $maxSize) {
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('Signed rollback input is missing or unsafe.');
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 1 || $size > $maxSize) {
            throw new RuntimeException('Signed rollback input has an invalid size.');
        }
    }
    $oldJson = file_get_contents($oldManifestPath);
    $oldSig = file_get_contents($oldSigPath);
    $newJson = file_get_contents($newManifestPath);
    $newSig = file_get_contents($newSigPath);
    $key = file_get_contents($keyPath);
    if (!is_string($oldJson) || !is_string($oldSig)
        || !is_string($newJson) || !is_string($newSig) || !is_string($key)) {
        throw new RuntimeException('Signed rollback verification input cannot be read.');
    }
    $report = SourceRollbackReadiness::inspect(
        $root, $oldStage, $newStage,
        $oldZip, $oldJson, $oldSig,
        $newZip, $newJson, $newSig, $key
    );
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    fwrite(STDERR, "Previous source material verified; no rollback, installation or database change performed.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Previous source rollback material NOT VERIFIED: " . $error->getMessage() . "\n");
    exit(1);
}
