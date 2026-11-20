<?php

declare(strict_types=1);

// Emergency recovery is intentionally not web-routable. Operator must have
// private filesystem and database access. No browser or unauthenticated route.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--rollback' || count($argv) !== 2) {
    fwrite(STDERR, "Usage (server console only): php scripts/recover-edition-upgrade.php --rollback\n");
    exit(2);
}
require dirname(__DIR__) . '/src/Support/Autoload.php';
try {
    (new \AdultCore\Licensing\EditionUpgrade(dirname(__DIR__)))->rollback();
    echo "Previous Lite edition metadata restored; verify the website and retain your external backups.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Recovery could not complete. Site remains locked; restore a known-good external backup and contact the operator.\n");
    exit(1);
}
