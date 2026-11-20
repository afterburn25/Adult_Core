<?php

declare(strict_types=1);

/**
 * Maintainer tool — never expose as a web endpoint.
 * Usage: php scripts/build-signed-package.php manifest.json payload-directory private-key.pem output.zip
 * Keep publisher PRIVATE keys out of the website tree and Git repository.
 */
require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Package\PackageVerifier;

if (PHP_SAPI !== 'cli' || count($argv) !== 5) {
    fwrite(STDERR, "Usage: php scripts/build-signed-package.php manifest.json payload-directory private-key.pem output.zip\n");
    exit(2);
}
[$script, $manifestPath, $payloadDir, $keyPath, $output] = $argv;
if (!str_ends_with(strtolower($output), '.zip') || file_exists($output) || is_link($output)) {
    fwrite(STDERR, "Output must be a new .zip file.\n");
    exit(2);
}
try {
    $manifest = json_decode((string) file_get_contents($manifestPath), true, 16, JSON_THROW_ON_ERROR);
    $private = openssl_pkey_get_private((string) file_get_contents($keyPath));
    if (!is_array($manifest) || $private === false || !is_dir($payloadDir)) {
        throw new RuntimeException('Package input is incomplete or invalid.');
    }
    $details = openssl_pkey_get_details($private);
    if (!is_array($details) || !is_string($details['key'] ?? null)) {
        throw new RuntimeException('Could not read signing key.');
    }
    $manifest['files'] = [];
    $files = [];
    $prefix = rtrim(realpath($payloadDir) ?: '', '/') . '/';
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($payloadDir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isLink() || !$file->isFile() || $file->getSize() > PackageVerifier::MAX_ENTRY_BYTES) {
            throw new RuntimeException('Payload contains an unsafe or oversized file.');
        }
        $relative = substr((string) $file->getRealPath(), strlen($prefix));
        $contents = file_get_contents($file->getPathname());
        if (!is_string($contents)) {
            throw new RuntimeException('Could not read payload file.');
        }
        $files[$relative] = $contents;
    }
    ksort($files);
    foreach ($files as $relative => $contents) {
        $manifest['files'][] = ['path' => $relative, 'sha256' => hash('sha256', $contents)];
    }
    $raw = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (!openssl_sign($raw, $signature, $private, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Could not sign manifest.');
    }
    // Validation uses the exact same production signature + manifest logic,
    // with a temporary trust anchor derived from the supplied private key.
    $trustDir = sys_get_temp_dir() . '/ac-sign-verify-' . bin2hex(random_bytes(8));
    mkdir($trustDir, 0700);
    try {
        $publisher = $manifest['publisher'] ?? '';
        if (!is_string($publisher) || preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $publisher) !== 1) {
            throw new RuntimeException('Invalid publisher ID.');
        }
        file_put_contents($trustDir . '/' . $publisher . '.pem', $details['key']);
        (new PackageVerifier($trustDir))->verifyManifest($raw, base64_encode($signature));
    } finally {
        foreach (glob($trustDir . '/*.pem') ?: [] as $f) {
            unlink($f);
        }
        rmdir($trustDir);
    }
    $zip = new PharData($output, 0, null, Phar::ZIP);
    $zip['package.json'] = $raw;
    $zip['package.sig'] = base64_encode($signature);
    foreach ($files as $relative => $contents) {
        $zip['payload/' . $relative] = $contents;
    }
    unset($zip);
    if (filesize($output) > PackageVerifier::MAX_ARCHIVE_BYTES) {
        unlink($output);
        throw new RuntimeException('Package exceeds the 8 MiB upload limit.');
    }
    echo "Created signed package: {$output}\n";
} catch (Throwable $e) {
    if (file_exists($output)) {
        unlink($output);
    }
    fwrite(STDERR, "Package creation failed: " . $e->getMessage() . "\n");
    exit(1);
}
