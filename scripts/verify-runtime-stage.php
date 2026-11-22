<?php

declare(strict_types=1);

/** Read-only independent verification of inert, private runtime-only source. */
require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Release\RuntimeSourceStageVerifier;

if (PHP_SAPI !== 'cli' || count($argv) !== 6) {
    fwrite(STDERR, "Usage: php scripts/verify-runtime-stage.php private-runtime-directory source.zip manifest.json manifest.sig trusted-public.pem\n");
    exit(64);
}
[, $stage, $zip, $manifestPath, $signaturePath, $keyPath] = $argv;
try {
    foreach ([$manifestPath => 262144, $signaturePath => 8192, $keyPath => 8192] as $path => $max) {
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('A signed runtime verification input is absent or unsafe.');
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 1 || $size > $max) {
            throw new RuntimeException('Signed runtime verification input has invalid size.');
        }
    }
    $manifest = file_get_contents($manifestPath);
    $signature = file_get_contents($signaturePath);
    $key = file_get_contents($keyPath);
    if (!is_string($manifest) || !is_string($signature) || !is_string($key)) {
        throw new RuntimeException('Cannot read publisher verification inputs.');
    }
    $result = RuntimeSourceStageVerifier::verify(
        $stage, $zip, $manifest, $signature, $key
    );
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    fwrite(STDERR, "Runtime stage verified; no installer or deployment activation authorized.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Private runtime stage NOT VERIFIED: " . $error->getMessage() . "\n");
    exit(1);
}
