<?php

declare(strict_types=1);

/**
 * CLI-only, read-only signed Pro receipt snapshot while source HTTP
 * maintenance is already owned by the operator. STDIN contains TWO lines:
 * 64-hex source lease token, then protected Pro job HMAC secret.
 * Never runs or cancels jobs, drains external workers, changes SQL or updates.
 */
require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Pro\ProSourceMaintenanceReceiptSnapshot;

if (PHP_SAPI !== 'cli' || count($argv) !== 3) {
    fwrite(STDERR, "Usage: php scripts/check-pro-source-receipts.php installed-root private-coordination-root < private-two-line-credentials\n");
    exit(64);
}

try {
    $raw = stream_get_contents(STDIN, 8192);
    if (!is_string($raw) || !str_contains($raw, "\n")) {
        throw new RuntimeException('Source lease token and protected Pro HMAC secret are required on STDIN.');
    }
    [$token, $secret] = explode("\n", $raw, 2);
    $token = rtrim($token, "\r");
    $secret = rtrim($secret, "\r\n");
    if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1
        || strlen($secret) < 32 || strlen($secret) > 4096) {
        throw new RuntimeException('Private source lease token or Pro receipt HMAC secret is invalid.');
    }
    $report = ProSourceMaintenanceReceiptSnapshot::inspect(
        $argv[1], $argv[2], $token, $secret
    );
    echo json_encode(
        $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ), "\n";
    fwrite(STDERR, "Local signed Pro receipt snapshot only. NO actual worker or SQL drain, source activation, or production approval.\n");
} catch (Throwable $error) {
    fwrite(STDERR, "Pro source maintenance snapshot REFUSED: " . $error->getMessage() . "\n");
    exit(1);
}
