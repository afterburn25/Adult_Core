<?php

declare(strict_types=1);

/**
 * Read-only signed release DB contract comparison. Requires two independent
 * detached source signatures and ONE pinned publisher public key. Never runs
 * SQL, fetches a license, updates files, or executes source code.
 */
require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Release\SignedCandidateDbContract;

if (PHP_SAPI !== 'cli' || count($argv) !== 8) {
    fwrite(STDERR,
        "Usage: php scripts/review-candidate-schema.php old.zip old.manifest.json old.sig new.zip new.manifest.json new.sig trusted-publisher.pem\n");
    exit(64);
}
try {
    [, $oldZip, $oldManifest, $oldSignature,
        $newZip, $newManifest, $newSignature, $keyPath] = $argv;
    foreach ([$oldManifest, $oldSignature, $newManifest, $newSignature, $keyPath] as $path) {
        if (is_link($path) || !is_file($path) || !is_readable($path)
            || (filesize($path) ?: 0) > 262144) {
            throw new RuntimeException('A signed release input is missing or unsafe.');
        }
    }
    $oldJson=file_get_contents($oldManifest);
    $oldSig=file_get_contents($oldSignature);
    $newJson=file_get_contents($newManifest);
    $newSig=file_get_contents($newSignature);
    $public=file_get_contents($keyPath);
    if (!is_string($oldJson) || !is_string($oldSig)
        || !is_string($newJson) || !is_string($newSig) || !is_string($public)) {
        throw new RuntimeException('Cannot read a required signed source input.');
    }
    $report=SignedCandidateDbContract::compare(
        $oldZip, $oldJson, $oldSig, $newZip, $newJson, $newSig, $public
    );
    echo json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR), "\n";
    fwrite(STDERR, "Source database declaration review only. No migration compatibility, installation, or production update approval.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Signed candidate database review REFUSED: ".$error->getMessage()."\n");
    exit(1);
}
