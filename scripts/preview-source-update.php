<?php

declare(strict_types=1);

/** Offline, read-only comparison of a pinned-key signed ZIP and installed source. */
require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Release\SourceDelta;

$jsonOutput = count($argv) === 7 && ($argv[6] ?? null) === '--json';
if (PHP_SAPI !== 'cli' || !(count($argv) === 6 || $jsonOutput)) {
    fwrite(STDERR, "Usage: php scripts/preview-source-update.php candidate.zip manifest.json manifest.sig trusted-public.pem installed-source-root [--json]\n");
    exit(64);
}
[, $archive, $manifestPath, $signaturePath, $publicKeyPath, $root] = $argv;
try {
    foreach ([$manifestPath => 262144, $signaturePath => 8192, $publicKeyPath => 8192] as $path => $limit) {
        if (!is_file($path) || is_link($path)) throw new RuntimeException('Detached signature input missing or unsafe.');
        $size = filesize($path);
        if (!is_int($size) || $size < 1 || $size > $limit) {
            throw new RuntimeException('Detached signature input exceeds the safe bound.');
        }
    }
    $manifest = file_get_contents($manifestPath);
    $signature = file_get_contents($signaturePath);
    $public = file_get_contents($publicKeyPath);
    if (!is_string($manifest) || !is_string($signature) || !is_string($public)) {
        throw new RuntimeException('Could not read signature inputs.');
    }
    $report = SourceDelta::preview($root, $archive, $manifest, $signature, $public);
    if ($jsonOutput) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    } else {
        echo "Read-only signed source preview: ", $report['installed_version'],
            " -> ", $report['candidate_version'], "\n",
            "Unchanged: ", $report['unchanged_count'],
            "; would add: ", count($report['would_add']),
            "; would replace: ", count($report['would_replace']), "\n",
            "No files changed, deletions assessed, or update authorized.\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Source update preview refused: " . $e->getMessage() . "\n");
    exit(1);
}
