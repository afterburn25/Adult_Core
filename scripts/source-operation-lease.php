<?php

declare(strict_types=1);

/**
 * Private operator CLI ONLY. Coordination does not stop HTTP requests/DB jobs.
 *
 * Acquire prints a sensitive one-time release token: save it in a secure
 * operator vault, never public logs. Release token is supplied via STDIN,
 * not process arguments or URLs.
 */
require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Release\SourceOperationLease;

$operation = $argv[1] ?? null;
if (PHP_SAPI !== 'cli' || !in_array($operation, ['acquire', 'status', 'release'], true)
    || count($argv) !== 4) {
    fwrite(STDERR, "Usage: php scripts/source-operation-lease.php acquire|status|release installed-source-root private-coordination-directory\n");
    fwrite(STDERR, "To release, pipe a privately saved release token into standard input (not a command-line argument).\n");
    exit(64);
}

try {
    $site = $argv[2];
    $private = $argv[3];
    if ($operation === 'acquire') {
        $result = SourceOperationLease::acquire($site, $private);
        fwrite(STDERR, "Store the release_token in an operator-only vault. Coordination does NOT pause the website.\n");
    } elseif ($operation === 'status') {
        $result = SourceOperationLease::status($site, $private);
    } else {
        $input = stream_get_contents(STDIN, 80);
        if (!is_string($input) || strlen(trim($input)) !== 64) {
            throw new RuntimeException('A 64-digit hex release token is required via STDIN.');
        }
        SourceOperationLease::release($site, $private, trim($input));
        $result = [
            'schema' => 'adult-core-source-operation-lease-v1',
            'status' => 'released',
            'site_maintenance_enforced' => false,
            'ready_for_activation' => false,
        ];
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Source operation coordination REFUSED: " . $error->getMessage() . "\n");
    exit(1);
}
