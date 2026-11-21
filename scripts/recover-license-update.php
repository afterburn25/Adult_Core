<?php

declare(strict_types=1);

// Only the hosting operator may restore an interrupted license replacement.
// This tool makes no payment or licensing API request and never prints keys.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--rollback' || count($argv) !== 2) {
    fwrite(STDERR, "Usage (server console only): php scripts/recover-license-update.php --rollback\n");
    exit(2);
}
require dirname(__DIR__) . '/src/Support/Autoload.php';

try {
    (new \AdultCore\Licensing\LicenseUpdateRecovery(dirname(__DIR__)))->rollback();
    echo "Previous license files restored. Check license status from the administrator panel after verifying your entitlement.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "License rollback could not complete. The website remains locked. Restore a trusted external backup or contact the operator.\n");
    exit(1);
}
