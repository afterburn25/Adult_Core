<?php
/** Operator-only, read-only HTTPS acceptance tool; no real payment operations. */
declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Licensing\CajunWebClient;
use AdultCore\Licensing\CwpStagingAudit;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
$options = getopt('', [
    'endpoint:', 'domain:', 'sku:', 'key-file:', 'confirm-readonly', 'allow-primary-host', 'help'
]);
if (isset($options['help'])) {
    echo "Usage: php scripts/audit-cwp-staging.php --endpoint=https://staging.cajunwebpro.com/api/validate.php \\
  --domain=licensed.example --sku=SOFTWARE-ADULT-CORE-LITE-MONTH \\
  --key-file=/private/staging-license-key.txt --confirm-readonly\n";
    exit(0);
}
if (!array_key_exists('confirm-readonly', $options)) {
    fwrite(STDERR, "No validation requests sent. Explicit --confirm-readonly is required.\n");
    exit(64);
}
try {
    foreach (['endpoint', 'domain', 'sku', 'key-file'] as $field) {
        if (!is_string($options[$field] ?? null) || trim($options[$field]) === '') {
            throw new InvalidArgumentException('Missing required staging audit option.');
        }
    }
    $endpoint = CwpStagingAudit::endpoint($options['endpoint'], isset($options['allow-primary-host']));
    $key = CwpStagingAudit::readKey($options['key-file'], dirname(__DIR__));
    // Endpoint is explicitly validated and is never derived from an HTTP request.
    $client = new CajunWebClient(static fn(string $body): array => CajunWebClient::postToEndpoint($endpoint, $body));
    $report = CwpStagingAudit::run($client, $key, $options['domain'], strtoupper($options['sku']));
    echo json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit(match ($report['outcome']) { 'passed' => 0, 'failed' => 1, default => 2 });
} catch (Throwable $error) {
    // Never print exceptions, request bodies, license keys or URLs containing secrets.
    fwrite(STDERR, "Staging acceptance could not run. Verify private key permissions, chosen SKU, domain and approved HTTPS endpoint.\n");
    exit(64);
}
