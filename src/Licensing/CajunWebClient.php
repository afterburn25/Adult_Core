<?php

declare(strict_types=1);

namespace AdultCore\Licensing;

use InvalidArgumentException;

/** Cajun_Web's product-bound POST /api/validate.php contract. No license is issued here. */
final class CajunWebClient
{
    public const SKU = 'SOFTWARE-ADULT-CORE';
    public const ENDPOINT = 'https://cajunwebpro.com/api/validate.php';

    /** A test-only transport can be injected by a caller who already controls the PHP process. */
    public function __construct(private readonly ?\Closure $transport = null) {}

    public static function domain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if (str_starts_with($domain, 'www.')) $domain = substr($domain, 4);
        if (strlen($domain) < 4 || strlen($domain) > 253 || !str_contains($domain, '.')
            || filter_var($domain, FILTER_VALIDATE_IP) && $domain !== '127.0.0.1'
            || !preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $domain)) {
            throw new InvalidArgumentException('Enter a valid licensed hostname (without scheme, path or port).');
        }
        return $domain;
    }

    public static function key(string $key): string
    {
        $key = strtoupper(trim($key));
        if (strlen($key) < 8 || strlen($key) > 255 || preg_match('/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/D', $key) !== 1) {
            throw new InvalidArgumentException('Enter a valid Cajun Web Pro license key.');
        }
        return $key;
    }

    /** @return 'valid'|'denied'|'transient' */
    public function validate(string $key, string $domain): string
    {
        $key = self::key($key);
        $domain = self::domain($domain);
        // Cajun_Web rejects software entitlement checks missing the catalog SKU.
        // Do not send a prerelease version: CWP requires a registered published version to check updates.
        $payload = json_encode(['license_key' => $key, 'domain' => $domain, 'product_id' => self::SKU], JSON_THROW_ON_ERROR);
        try {
            $response = $this->transport !== null
                ? ($this->transport)($payload)
                : $this->request($payload);
            if (!is_array($response) || !is_int($response['status'] ?? null)
                || !is_string($response['body'] ?? null) || strlen($response['body']) > 4096) return 'transient';
            if ($response['status'] !== 200) return 'transient';
            $json = json_decode($response['body'], true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($json)) return 'transient';
            if (($json['authorized'] ?? null) === true) return 'valid';
            if (($json['authorized'] ?? null) === false) return 'denied';
        } catch (\Throwable) {
            // Network failure, 429, malformed reply, or authority outage must never be
            // misinterpreted as Cajun Web Pro definitively revoking a license.
        }
        return 'transient';
    }

    /** @return array{status:int,body:string} */
    private function request(string $body): array
    {
        // Fixed HTTPS endpoint; no user-supplied URLs, no HTTP redirects, TLS verification required.
        $context = stream_context_create([
            'http' => [
                'method' => 'POST', 'header' => "Content-Type: application/json\r\nAccept: application/json\r\nConnection: close\r\n",
                'content' => $body, 'timeout' => 4, 'ignore_errors' => true,
                'follow_location' => 0, 'max_redirects' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false],
        ]);
        $handle = @fopen(self::ENDPOINT, 'rb', false, $context);
        if (!is_resource($handle)) throw new \RuntimeException('License validation service unavailable.');
        try {
            $headers = stream_get_meta_data($handle)['wrapper_data'] ?? [];
            $statusLine = is_array($headers) ? ($headers[0] ?? '') : '';
            if (!is_string($statusLine) || !preg_match('#^HTTP/\S+\s+(\d{3})\b#', $statusLine, $match)) {
                throw new \RuntimeException('Invalid licensing response.');
            }
            $result = stream_get_contents($handle, 4097);
            if (!is_string($result)) throw new \RuntimeException('Invalid licensing payload.');
            return ['status' => (int) $match[1], 'body' => $result];
        } finally {
            fclose($handle);
        }
    }
}
