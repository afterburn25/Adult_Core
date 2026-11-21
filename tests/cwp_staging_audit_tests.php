<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Licensing\CajunWebClient;
use AdultCore\Licensing\CwpStagingAudit;

$checks = 0;
$assert = static function (string $label, bool $condition) use (&$checks): void {
    $checks++;
    if (!$condition) throw new RuntimeException('Staging audit test failed: ' . $label);
};
$reject = static function (string $label, Closure $fn) use ($assert): void {
    try { $fn(); $assert($label, false); }
    catch (InvalidArgumentException) { $assert($label, true); }
};

$assert('Staging path allowed', CwpStagingAudit::endpoint('https://staging.cajunwebpro.com/api/validate.php') === 'https://staging.cajunwebpro.com/api/validate.php');
$assert('Nested approved subdomain allowed', CwpStagingAudit::endpoint('https://audit.staging.cajunwebpro.com:443/api/validate.php') === 'https://audit.staging.cajunwebpro.com/api/validate.php');
$reject('Primary domain opt-in required', static fn() => CwpStagingAudit::endpoint('https://cajunwebpro.com/api/validate.php'));
$assert('Explicit primary domain approved', CwpStagingAudit::endpoint('https://cajunwebpro.com/api/validate.php', true) === CajunWebClient::ENDPOINT);
foreach ([
    'http://staging.cajunwebpro.com/api/validate.php',
    'https://staging.cajunwebpro.com.evil.example/api/validate.php',
    'https://cajunwebpro.com@evil.example/api/validate.php',
    'https://evil.example@staging.cajunwebpro.com/api/validate.php',
    'https://staging.cajunwebpro.com:8443/api/validate.php',
    'https://staging.cajunwebpro.com/api/validate.php?secret=123',
    'https://staging.cajunwebpro.com/api/validate.php#fragment',
    'https://staging.cajunwebpro.com/api/validate.php/extra',
    'https://127.0.0.1/api/validate.php',
    'https://staging.cajunwebpro.com\\@evil.example/api/validate.php',
    'https://staging.cajunwebpro.com/api/%76alidate.php',
    "https://staging.cajunwebpro.com/api/validate.php\n",
    'https://staging..cajunwebpro.com/api/validate.php',
] as $bad) {
    $reject('Untrusted or malformed endpoint refused ' . substr($bad, 0, 24), static fn() => CwpStagingAudit::endpoint($bad, true));
}

$outside = sys_get_temp_dir() . '/ac-staging-audit-' . bin2hex(random_bytes(8));
if (!mkdir($outside, 0700)) throw new RuntimeException('Could not prepare audit test fixture.');
$keyPath = $outside . '/key';
$key = 'SYNTHETIC-KEY-ONLY-1234';
try {
    file_put_contents($keyPath, $key . "\n");
    chmod($keyPath, 0600);
    $assert('Private key read from outside source checkout', CwpStagingAudit::readKey($keyPath, dirname(__DIR__)) === $key);
    chmod($keyPath, 0644);
    $reject('World-readable license key refused', static fn() => CwpStagingAudit::readKey($keyPath, dirname(__DIR__)));
    chmod($keyPath, 0600);
    $alias = $outside . '/alias';
    symlink($keyPath, $alias);
    $reject('License key symlink refused', static fn() => CwpStagingAudit::readKey($alias, dirname(__DIR__)));
    unlink($alias);
    file_put_contents($keyPath, "FIRST-KEY\nSECOND-KEY\n");
    $reject('Multiple keys refused', static fn() => CwpStagingAudit::readKey($keyPath, dirname(__DIR__)));
    file_put_contents($keyPath, str_repeat('A', 1100));
    $reject('Oversize key refused', static fn() => CwpStagingAudit::readKey($keyPath, dirname(__DIR__)));
    file_put_contents($keyPath, "$key\n");

    $sku = CajunWebClient::SKU_LITE_MONTH;
    $domain = 'creator.example';
    $calls = [];
    $transport = static function (string $request) use (&$calls, $sku, $domain, $key): array {
        $in = json_decode($request, true, 8, JSON_THROW_ON_ERROR);
        $calls[] = $in;
        $allowed = $in['license_key'] === $key && $in['product_id'] === $sku && $in['domain'] === $domain;
        return ['status' => 200, 'body' => json_encode(['authorized' => $allowed], JSON_THROW_ON_ERROR)];
    };
    $report = CwpStagingAudit::run(new CajunWebClient($transport), $key, $domain, $sku);
    $assert('Matching product authorizes', $report['outcome'] === 'passed');
    $assert('Edition comes from product', $report['edition'] === 'lite');
    $assert('Monthly term is preserved', $report['term'] === 'month');
    $assert('Exactly five read-only validation calls', count($calls) === 5 && $report['api_calls'] === 5);
    $assert('Correct product first', $calls[0]['product_id'] === $sku && $calls[0]['domain'] === $domain);
    $otherSkus = array_values(array_filter(array_keys(CajunWebClient::products()), static fn(string $other): bool => $other !== $sku));
    $assert('All three other product checks are separately denied',
        array_keys($report['checks']['other_products']) === $otherSkus
        && count(array_filter($report['checks']['other_products'], static fn(string $answer): bool => $answer === 'denied')) === 3
        && array_column(array_slice($calls, 1, 3), 'product_id') === $otherSkus);
    $assert('Unregistered domain denied', $report['checks']['unregistered_domain'] === 'denied'
        && $calls[4]['domain'] === CwpStagingAudit::NEGATIVE_DOMAIN
        && $calls[4]['product_id'] === $sku);
    $assert('Report never includes the private key', !str_contains(json_encode($report, JSON_THROW_ON_ERROR), $key));

    // If any *one* of the three competing SKUs is incorrectly authorized,
    // the audit must fail rather than misreport a valid single-product grant.
    foreach (array_keys(CajunWebClient::products()) as $expectedSku) {
        foreach (array_keys(CajunWebClient::products()) as $unexpectedSku) {
            if ($unexpectedSku === $expectedSku) continue;
            $overbroad = new CajunWebClient(static function (string $request) use ($expectedSku, $unexpectedSku, $domain): array {
                $in = json_decode($request, true, 8, JSON_THROW_ON_ERROR);
                $authorized = $in['domain'] === $domain
                    && in_array($in['product_id'], [$expectedSku, $unexpectedSku], true);
                return ['status' => 200, 'body' => json_encode(['authorized' => $authorized], JSON_THROW_ON_ERROR)];
            });
            $result = CwpStagingAudit::run($overbroad, $key, $domain, $expectedSku);
            $assert('Cross-product grant never passes '.$expectedSku.' / '.$unexpectedSku,
                $result['outcome'] === 'failed'
                && $result['checks']['other_products'][$unexpectedSku] === 'valid');
        }
    }
    // A transient answer for any individual competing SKU is inconclusive,
    // not equivalent to a definitive denial.
    foreach (array_keys(CajunWebClient::products()) as $uncertainSku) {
        $uncertain = new CajunWebClient(static function (string $request) use ($sku, $domain, $uncertainSku): array {
            $in = json_decode($request, true, 8, JSON_THROW_ON_ERROR);
            if ($in['product_id'] === $uncertainSku && $in['domain'] === $domain) {
                return ['status' => 503, 'body' => '{}'];
            }
            return ['status' => 200, 'body' => json_encode([
                'authorized' => $in['product_id'] === $sku && $in['domain'] === $domain,
            ], JSON_THROW_ON_ERROR)];
        });
        $result = CwpStagingAudit::run($uncertain, $key, $domain, $sku);
        $assert('Staging control outage is inconclusive '.$uncertainSku, $result['outcome'] === 'inconclusive');
    }

    $allTrue = new CajunWebClient(static fn(string $request): array => ['status'=>200, 'body'=>'{"authorized":true}']);
    $assert('Master/overbroad grants fail negative controls', CwpStagingAudit::run($allTrue, $key, $domain, $sku)['outcome'] === 'failed');
    $allFalse = new CajunWebClient(static fn(string $request): array => ['status'=>200, 'body'=>'{"authorized":false}']);
    $assert('Unlicensed product is not accepted', CwpStagingAudit::run($allFalse, $key, $domain, $sku)['outcome'] === 'failed');
    foreach ([429, 503, 400, 302] as $status) {
        $client = new CajunWebClient(static fn(string $request): array => ['status'=>$status, 'body'=>'{"authorized":false}']);
        $assert("Issuer $status is inconclusive", CwpStagingAudit::run($client, $key, $domain, $sku)['outcome'] === 'inconclusive');
    }
    $malformed = new CajunWebClient(static fn(string $request): array => ['status'=>200, 'body'=>'{"authorized":"no"}']);
    $assert('Non-boolean verdict is inconclusive', CwpStagingAudit::run($malformed, $key, $domain, $sku)['outcome'] === 'inconclusive');
    $reject('Unregistered product rejected before contacting authority', static fn() => CwpStagingAudit::run($allTrue, $key, $domain, 'NOT-A-REAL-SKU'));
    $reject('Negative test domain cannot be used as licensed domain', static fn() => CwpStagingAudit::run($allTrue, $key, CwpStagingAudit::NEGATIVE_DOMAIN, $sku));
    $reject('Invalid domain rejected before contacting authority', static fn() => CwpStagingAudit::run($allTrue, $key, 'http://domain.example', $sku));
    // CLI refuses to probe without explicit confirmation and without reading a license key from argv.
    $cli = dirname(__DIR__) . '/scripts/audit-cwp-staging.php';
    $cmd = 'php ' . escapeshellarg($cli) . ' --sku=' . escapeshellarg($sku) . ' --key-file=' . escapeshellarg($keyPath) . ' --domain=' . escapeshellarg($domain);
    exec($cmd . ' 2>&1', $output, $exit);
    $assert('CLI requires explicit operator confirmation', $exit === 64);
    $assert('CLI never outputs the license key', !str_contains(implode("\n", $output), $key));
    $cmd .= ' --confirm-readonly --endpoint=' . escapeshellarg('https://bad.example/api/validate.php');
    exec($cmd . ' 2>&1', $output2, $exit2);
    $assert('CLI refuses untrusted HTTPS endpoint', $exit2 === 64);
    $assert('CLI never sends key to or prints untrusted endpoint', !str_contains(implode("\n", $output2), $key));
} finally {
    @unlink($keyPath);
    @rmdir($outside);
}

echo "Passed $checks read-only Cajun Web Pro staging audit checks.\n";
