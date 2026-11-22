<?php

declare(strict_types=1);

/** Authenticates and audits an inert private stage. No mutation or activation. */
require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Release\SourceStageVerifier;

if (PHP_SAPI !== 'cli' || count($argv) !== 6) {
    fwrite(STDERR, "Usage: php scripts/verify-source-stage.php staged-directory source.zip manifest.json manifest.sig independently-trusted-public.pem\n");
    exit(64);
}
[, $stage, $zip, $manifestPath, $signaturePath, $keyPath] = $argv;
try {
    foreach ([$manifestPath => 262144, $signaturePath => 8192, $keyPath => 8192] as $p => $max) {
        if (!is_file($p) || is_link($p)) {
            throw new RuntimeException('A signed verification input is missing or unsafe.');
        }
        $size = filesize($p);
        if (!is_int($size) || $size < 1 || $size > $max) {
            throw new RuntimeException('Signed verification input has an invalid length.');
        }
    }
    $manifest = file_get_contents($manifestPath);
    $signature = file_get_contents($signaturePath);
    $key = file_get_contents($keyPath);
    if (!is_string($manifest) || !is_string($signature) || !is_string($key)) {
        throw new RuntimeException('Signed verification inputs cannot be read.');
    }
    $report = SourceStageVerifier::verify($stage, $zip, $manifest, $signature, $key);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    fwrite(STDERR, "Private staged source checked; no installation, execution or update authorized.\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Private staged source NOT VERIFIED: " . $e->getMessage() . "\n");
    exit(1);
}
