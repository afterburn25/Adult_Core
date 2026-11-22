<?php

declare(strict_types=1);

/**
 * OFFLINE maintainer signing only:
 * php scripts/sign-source-release.php source.zip /private/signing-key.pem manifest.json manifest.sig
 * The private key and both output files must be outside this application tree.
 */
require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Release\SourceArchive;

if (PHP_SAPI !== 'cli' || count($argv) !== 5) {
    fwrite(STDERR, "Usage: php scripts/sign-source-release.php source.zip private-key.pem manifest.json manifest.sig\n");
    exit(64);
}
[, $archive, $privatePath, $manifestPath, $signaturePath] = $argv;
$root = realpath(dirname(__DIR__));
$created = [];
try {
    if ($root === false || $manifestPath === $signaturePath
        || is_link($privatePath) || !is_file($privatePath)) {
        throw new RuntimeException('Unsafe signing input.');
    }
    $keyReal = realpath($privatePath);
    if ($keyReal === false || $keyReal === $root || str_starts_with($keyReal, $root . '/')) {
        throw new RuntimeException('Signing key must be outside the source tree.');
    }
    foreach ([$manifestPath, $signaturePath] as $output) {
        $dir = realpath(dirname($output));
        if (!str_starts_with($output, '/') || $dir === false
            || $dir === $root || str_starts_with($dir, $root . '/')
            || file_exists($output) || is_link($output)) {
            throw new RuntimeException('Detached signature outputs must be new files outside the source tree.');
        }
    }
    $rawKey = file_get_contents($privatePath);
    $private = is_string($rawKey) ? openssl_pkey_get_private($rawKey) : false;
    if ($private === false) {
        throw new RuntimeException('Signing key is unreadable or invalid.');
    }
    $details = openssl_pkey_get_details($private);
    if (!is_array($details) || !is_string($details['key'] ?? null)) {
        throw new RuntimeException('Signing key has no public verification key.');
    }
    $manifest = SourceArchive::manifestJson($archive);
    if (!openssl_sign($manifest, $signatureBytes, $private, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Release signing failed.');
    }
    $signature = base64_encode($signatureBytes) . "\n";
    // Roundtrip using the trusted public half before writing any artifacts.
    SourceArchive::verify($archive, $manifest, $signature, $details['key']);
    foreach ([$manifestPath => $manifest, $signaturePath => $signature] as $path => $content) {
        $handle = @fopen($path, 'x');
        if (!is_resource($handle)) {
            throw new RuntimeException('Could not create detached release signature output.');
        }
        $created[] = $path;
        try {
            if (fwrite($handle, $content) !== strlen($content)) {
                throw new RuntimeException('Could not finish detached release signature output.');
            }
        } finally {
            fclose($handle);
        }
    }
    echo "Detached source manifest and signature created. Keep the signing key private.\n";
} catch (Throwable $e) {
    foreach ($created as $path) {
        @unlink($path);
    }
    fwrite(STDERR, "Source release signing refused: " . $e->getMessage() . "\n");
    exit(1);
}
