<?php
/**
 * Test-only executable adapter for the loopback CWP API simulator.
 * No customer keys or payment credentials. No production endpoint override.
 */
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/Support/Autoload.php';

use AdultCore\Installer\InstallationState;
use AdultCore\Licensing\CajunWebClient;
use AdultCore\Licensing\LicenseManager;
use AdultCore\Licensing\LicenseState;

if (PHP_SAPI !== 'cli' || count($argv) !== 5) {
    fwrite(STDERR, "Test fixture needs operation, temp root, synthetic key, domain.\n");
    exit(2);
}
[$script, $action, $root, $key, $domain] = $argv;
$url = getenv('AC_TEST_CWP_URL');
$parts = is_string($url) ? parse_url($url) : false;
if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'http'
    || ($parts['host'] ?? '') !== '127.0.0.1'
    || ($parts['path'] ?? '') !== '/api/validate.php'
    || !isset($parts['port']) || !is_int($parts['port'])) {
    fwrite(STDERR, "Test fixture only accepts an HTTP loopback license endpoint.\n");
    exit(2);
}

$client = new CajunWebClient(static function (string $payload) use ($url): array {
    $context = stream_context_create(['http' => [
        'method' => 'POST',
        'content' => $payload,
        'header' => "Content-Type: application/json\r\nAccept: application/json\r\nConnection: close\r\n",
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 2,
    ]]);
    $body = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})\b#', $header, $match)) {
            $status = (int)$match[1];
            break;
        }
    }
    return ['status' => $status, 'body' => is_string($body) ? $body : ''];
});
$manager = new LicenseManager($root, $client);
$secret = str_repeat('a', 64); // Synthetic, private temporary test installation only.
try {
    switch ($action) {
        case 'resolve':
            $result = $client->resolve($key, $domain);
            break;
        case 'init':
            if (!is_dir($root . '/config')) mkdir($root . '/config', 0700, true);
            if (!is_dir($root . '/storage')) mkdir($root . '/storage', 0700, true);
            $result = $manager->verifyForInstall($key, $domain);
            file_put_contents($root . '/config/app-key.php', '<?php return ' . var_export($secret, true) . ';');
            $manager->persistInitial($result, $secret);
            file_put_contents($root . '/storage/install-state.json', InstallationState::encode($result['edition'], $secret));
            $result = ['sku' => $result['sku'], 'edition' => $result['edition'], 'term' => $result['term']];
            break;
        case 'status':
        case 'force':
            $state = $manager->status($domain, $action === 'force');
            $saved = $manager->config();
            $result = ['status' => $state, 'issuer' => $manager->lastAuthorityResponse(),
                'sku' => $saved['sku'] ?? null, 'term' => $saved['term'] ?? null];
            break;
        case 'wronghost':
            $result = ['status' => $manager->status('unregistered.example', true),
                'issuer' => $manager->lastAuthorityResponse()];
            break;
        case 'age':
            $saved = $manager->config();
            if ($saved === null) throw new RuntimeException('Test installation not initialized.');
            $checked = time() - LicenseManager::TRANSIENT_GRACE_SECONDS - 180;
            file_put_contents($root . '/storage/license-cache.json', LicenseState::encode(
                $saved['license_key'], $saved['domain'], $secret, 'valid', $checked, $checked, $saved['sku']
            ));
            $result = ['cache' => 'aged'];
            break;
        case 'replace':
            $manager->replaceLicense($key, $domain);
            $saved = $manager->config();
            $result = ['sku' => $saved['sku'] ?? null, 'edition' => $saved['edition'] ?? null,
                'term' => $saved['term'] ?? null];
            break;
        default:
            throw new InvalidArgumentException('Unrecognized fixture action.');
    }
    // License keys are never returned to the test runner, CI output or logs.
    if (is_array($result)) unset($result['license_key']);
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
} catch (Throwable $failure) {
    // Do not echo exception data (which could include provided keys).
    echo "{\"ok\":false}\n";
    exit(1);
}
