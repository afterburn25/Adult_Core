<?php

declare(strict_types=1);

/**
 * Read-only installed SQL ledger evidence under owned source HTTP maintenance.
 * The current source-operation lease token is read from STDIN (not argv).
 * It does NOT certify external SQL quiescence or allow update activation.
 */
require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Database\Connection;
use AdultCore\Release\SourceMaintenanceDatabaseEvidence;

if (PHP_SAPI !== 'cli' || count($argv) !== 3) {
    fwrite(STDERR, "Usage: php scripts/check-source-maintenance-db.php installed-root private-coordination-root < /private/source-lease-token\n");
    exit(64);
}
try {
    $raw = stream_get_contents(STDIN, 80);
    $token = is_string($raw) ? trim($raw) : '';
    if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
        throw new RuntimeException('A 64-hex source-operation token is required via STDIN.');
    }
    $report = SourceMaintenanceDatabaseEvidence::inspect(
        $argv[1], $argv[2], $token, Connection::open($argv[1])
    );
    echo json_encode(
        $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ), "\n";
    fwrite(STDERR, "Read-only local maintenance evidence only. External DB writers and Pro workers are NOT quiesced. No source activation.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Source maintenance database evidence REFUSED: ".$error->getMessage()."\n");
    exit(1);
}
