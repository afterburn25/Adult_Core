<?php

declare(strict_types=1);

/** CLI only: authenticated source copied to a new PRIVATE, inert directory. */
require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Release\SourceStage;

if (PHP_SAPI !== 'cli' || count($argv) !== 7) {
    fwrite(STDERR, "Usage: php scripts/stage-source-release.php source.zip manifest.json manifest.sig trusted-public.pem installed-source-root new-private-stage-dir\n");
    exit(64);
}
[, $archive, $manifestPath, $sigPath, $publicPath, $root, $destination] = $argv;
try {
    foreach ([$manifestPath => 262144, $sigPath => 8192, $publicPath => 8192] as $path => $limit) {
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('Detached signature input absent or unsafe.');
        }
        $bytes = filesize($path);
        if (!is_int($bytes) || $bytes < 1 || $bytes > $limit) {
            throw new RuntimeException('Detached signature input exceeds safe size.');
        }
    }
    $manifest = file_get_contents($manifestPath);
    $sig = file_get_contents($sigPath);
    $public = file_get_contents($publicPath);
    if (!is_string($manifest) || !is_string($sig) || !is_string($public)) {
        throw new RuntimeException('Cannot read detached signing input.');
    }
    $result = SourceStage::prepare($root, $archive, $manifest, $sig, $public, $destination);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    fwrite(STDERR, "Private source staged only; no active installation changed or update authorized.\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Private release staging refused: " . $e->getMessage() . "\n");
    exit(1);
}
