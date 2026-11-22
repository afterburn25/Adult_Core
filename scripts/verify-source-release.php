<?php

declare(strict_types=1);

/**
 * Read-only, OFFLINE source ZIP authenticity and every-entry hash verification.
 * The public key must come from an independently trusted, pinned source.
 */
require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Release\SourceArchive;

if (PHP_SAPI !== 'cli' || count($argv) !== 5) {
    fwrite(STDERR, "Usage: php scripts/verify-source-release.php source.zip manifest.json manifest.sig trusted-public-key.pem\n");
    exit(64);
}
[, $archive, $manifestPath, $signaturePath, $publicKeyPath] = $argv;
try {
    foreach ([$manifestPath => 262144, $signaturePath => 8192, $publicKeyPath => 8192] as $path => $max) {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('A detached verification input is missing or unsafe.');
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 1 || $size > $max) {
            throw new RuntimeException('A detached verification input has an invalid size.');
        }
    }
    $manifest = file_get_contents($manifestPath);
    $signature = file_get_contents($signaturePath);
    $public = file_get_contents($publicKeyPath);
    if (!is_string($manifest) || !is_string($signature) || !is_string($public)) {
        throw new RuntimeException('Could not read signed verification inputs.');
    }
    $version = SourceArchive::verify($archive, $manifest, $signature, $public);
    echo "Verified detached source release {$version} (all ZIP entries authenticated; no installation performed).\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Source release NOT VERIFIED: " . $e->getMessage() . "\n");
    exit(1);
}
