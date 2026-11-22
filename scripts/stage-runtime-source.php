<?php

declare(strict_types=1);

/**
 * Prepare an inert, PRIVATE copy of signed runtime source files only.
 * Does not install, switch webroots or modify a running website.
 */
require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Release\RuntimeSourceStage;

if (PHP_SAPI !== 'cli' || count($argv) !== 8) {
    fwrite(STDERR, "Usage: php scripts/stage-runtime-source.php installed-root signed-full-source-stage source.zip manifest.json manifest.sig trusted-public.pem new-private-runtime-stage\n");
    exit(64);
}
[, $installed, $sourceStage, $zip, $manifestPath, $signaturePath, $keyPath, $destination] = $argv;
try {
    foreach ([$manifestPath => 262144, $signaturePath => 8192, $keyPath => 8192] as $path => $limit) {
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('Runtime source signing input is missing or unsafe.');
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 1 || $size > $limit) {
            throw new RuntimeException('Runtime source signing input exceeds its permitted size.');
        }
    }
    $manifest = file_get_contents($manifestPath);
    $signature = file_get_contents($signaturePath);
    $public = file_get_contents($keyPath);
    if (!is_string($manifest) || !is_string($signature) || !is_string($public)) {
        throw new RuntimeException('Cannot read trusted runtime source verification input.');
    }
    $report = RuntimeSourceStage::prepare(
        $installed, $sourceStage, $zip, $manifest, $signature, $public, $destination
    );
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    fwrite(STDERR, "Private runtime source staged only; no installer, live update or execution authorized.\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Private runtime source staging REFUSED: " . $e->getMessage() . "\n");
    exit(1);
}
