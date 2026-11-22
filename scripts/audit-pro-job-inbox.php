<?php

declare(strict_types=1);

/**
 * Offline read-only audit of bounded private Pro job receipt files.
 * Requires the installed site's job HMAC secret on STDIN (not argv or URL).
 * This does NOT certify any worker is drained or permit live source activation.
 */
require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Pro\ProJobReceiptAudit;

if (PHP_SAPI !== 'cli' || count($argv) !== 2) {
    fwrite(STDERR, "Usage: php scripts/audit-pro-job-inbox.php installed-source-root < /private/pro-job-hmac-secret\n");
    exit(64);
}
try {
    $input = stream_get_contents(STDIN, 4098);
    if (!is_string($input)) throw new RuntimeException('Private Pro job HMAC key input unavailable.');
    $secret = rtrim($input, "\r\n");
    if (strlen($secret) < 32 || strlen($secret) > 4096) {
        throw new RuntimeException('Private Pro job HMAC key length is invalid.');
    }
    $report = ProJobReceiptAudit::inspect($argv[1], $secret);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    fwrite(STDERR, "Local receipt audit only. External worker and database-write quiescence are NOT certified.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Pro signed receipt audit REFUSED: ".$error->getMessage()."\n");
    exit(1);
}
