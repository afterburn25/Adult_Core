<?php

declare(strict_types=1);

/**
 * CLI-only source maintenance for Adult Core dynamic HTTP application routes.
 *
 * It is NOT a source updater, full web-server block, worker drain, or
 * database maintenance guarantee. Begin/end require a private lease token
 * supplied through STDIN rather than process arguments or URLs.
 */
require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Release\SourceUpdateMaintenance;

$command = $argv[1] ?? null;
if (PHP_SAPI !== 'cli' || !in_array($command, ['begin', 'status', 'end'], true)
    || count($argv) !== 4) {
    fwrite(STDERR, "Usage: php scripts/source-http-maintenance.php begin|status|end installed-source-root private-coordination-directory\n");
    fwrite(STDERR, "Begin/end require the current source lease release token via STDIN.\n");
    exit(64);
}
try {
    $root = $argv[2];
    $coord = $argv[3];
    if ($command === 'status') {
        $result = SourceUpdateMaintenance::status($root, $coord);
    } else {
        $bytes = stream_get_contents(STDIN, 80);
        $token = is_string($bytes) ? trim($bytes) : '';
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new RuntimeException('A 64-hex private lease token is required via STDIN.');
        }
        if ($command === 'begin') {
            $result = SourceUpdateMaintenance::begin($root, $coord, $token);
        } else {
            SourceUpdateMaintenance::end($root, $coord, $token);
            $result = [
                'schema' => 'adult-core-source-http-maintenance-v1',
                'status' => 'inactive',
                'site_http_front_controller_blocked' => false,
                'ready_for_activation' => false,
            ];
        }
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    fwrite(STDERR, "Source HTTP maintenance only. Static web files and independent workers may remain accessible; no live update authorized.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Source HTTP maintenance REFUSED: " . $error->getMessage() . "\n");
    exit(1);
}
