<?php

declare(strict_types=1);

namespace AdultCore\Licensing;

use InvalidArgumentException;

/** Read-only acceptance probe for an already-issued staging license; never performs commerce. */
final class CwpStagingAudit
{
    public const NEGATIVE_DOMAIN = 'unregistered-audit-domain.invalid';

    public static function endpoint(string $url, bool $allowPrimary = false): string
    {
        if ($url === '' || strlen($url) > 512 || str_contains($url, '\\') || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new InvalidArgumentException('A trusted Cajun Web Pro HTTPS endpoint is required.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || !is_string($parts['host'] ?? null)
            || ($parts['path'] ?? '') !== '/api/validate.php'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new InvalidArgumentException('Use an HTTPS Cajun Web Pro /api/validate.php endpoint without credentials, ports or query parameters.');
        }
        $host = strtolower($parts['host']);
        if ($host === 'cajunwebpro.com') {
            if (!$allowPrimary) throw new InvalidArgumentException('The primary Cajun Web Pro host requires explicit operator approval.');
        } elseif (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+cajunwebpro\.com$/D', $host)) {
            throw new InvalidArgumentException('Only cajunwebpro.com and its HTTPS subdomains are allowed.');
        }
        return 'https://' . $host . '/api/validate.php';
    }

    /** Keys are supplied via a private file outside the checkout, never argv or environment. */
    public static function readKey(string $file, string $sourceRoot): string
    {
        if ($file === '' || is_link($file) || !is_file($file) || !is_readable($file)) {
            throw new InvalidArgumentException('Supply a readable, regular private license-key file.');
        }
        $real = realpath($file);
        $source = realpath($sourceRoot);
        if ($real === false || $source === false || str_starts_with($real, rtrim($source, '/') . '/')) {
            throw new InvalidArgumentException('The key file must be outside the Adult_Core checkout.');
        }
        $mode = @fileperms($real);
        $size = @filesize($real);
        if ($mode === false || ($mode & 0077) !== 0 || $size === false || $size < 8 || $size > 1024) {
            throw new InvalidArgumentException('The key file must be private (chmod 600) and contain only one key.');
        }
        $raw = @file_get_contents($real);
        if (!is_string($raw) || str_contains(trim($raw), "\n") || str_contains(trim($raw), "\r")) {
            throw new InvalidArgumentException('The private key file contains invalid data.');
        }
        return CajunWebClient::key(trim($raw));
    }

    /**
     * Five bounded read-only validations, never a card charge or customer mutation.
     * Caller must ensure the negative domain is not independently licensed.
     * @return array{outcome:string,sku:string,edition:string,term:string,checks:array<string,string>,api_calls:int}
     */
    public static function run(CajunWebClient $client, string $key, string $domain, string $sku): array
    {
        $identity = CajunWebClient::identity($sku);
        if ($identity === null) throw new InvalidArgumentException('Choose one of the four Adult_Core license products.');
        $domain = CajunWebClient::domain($domain);
        if ($domain === self::NEGATIVE_DOMAIN) throw new InvalidArgumentException('The requested domain must not be the negative-test domain.');
        $key = CajunWebClient::key($key);
        // A single negative cross-product check is insufficient: the key
        // might also be granted one of the two other Lite/Pro products.
        // Check all four exact SKUs and the independent domain-negative control.
        // Five bounded calls fit the issuer's ten-per-minute/IP default limit
        // when operators avoid concurrent or repeated audit invocations.
        $checks = ['correct_product_domain' => $client->validate($key, $domain, $sku)];
        $otherProducts = [];
        foreach (CajunWebClient::products() as $otherSku => $_identity) {
            if ($otherSku === $sku) continue;
            $otherProducts[$otherSku] = $client->validate($key, $domain, $otherSku);
        }
        $checks['other_products'] = $otherProducts;
        $checks['unregistered_domain'] = $client->validate($key, self::NEGATIVE_DOMAIN, $sku);
        $results = [$checks['correct_product_domain'], ...array_values($otherProducts), $checks['unregistered_domain']];
        $outcome = in_array('transient', $results, true) ? 'inconclusive'
            : ($checks['correct_product_domain'] === 'valid'
                && !in_array('valid', $otherProducts, true)
                && $checks['unregistered_domain'] === 'denied' ? 'passed' : 'failed');
        return [
            'outcome' => $outcome, 'sku' => $sku, 'edition' => $identity['edition'],
            'term' => $identity['term'], 'checks' => $checks, 'api_calls' => count($results),
        ];
    }
}
