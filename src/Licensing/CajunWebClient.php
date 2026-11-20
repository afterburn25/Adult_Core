<?php

declare(strict_types=1);

namespace AdultCore\Licensing;

use InvalidArgumentException;

/** Cajun Web Pro's product-bound API; Adult_Core never issues or charges licenses. */
final class CajunWebClient
{
    public const ENDPOINT = 'https://cajunwebpro.com/api/validate.php';
    public const SKU_LITE_ONCE = 'SOFTWARE-ADULT-CORE-LITE';
    public const SKU_LITE_MONTH = 'SOFTWARE-ADULT-CORE-LITE-MONTH';
    public const SKU_PRO_ONCE = 'SOFTWARE-ADULT-CORE-PRO';
    public const SKU_PRO_MONTH = 'SOFTWARE-ADULT-CORE-PRO-MONTH';
    // All four entries represent *one* installer; no edition selection is trusted from the browser.
    private const PRODUCT_MAP = [
        self::SKU_LITE_ONCE => ['edition' => 'lite', 'term' => 'once'],
        self::SKU_LITE_MONTH => ['edition' => 'lite', 'term' => 'month'],
        self::SKU_PRO_ONCE => ['edition' => 'pro', 'term' => 'once'],
        self::SKU_PRO_MONTH => ['edition' => 'pro', 'term' => 'month'],
    ];
    public function __construct(private readonly ?\Closure $transport = null) {}

    public static function products(): array { return self::PRODUCT_MAP; }

    public static function identity(string $sku): ?array { return self::PRODUCT_MAP[$sku] ?? null; }

    public static function domain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if (str_starts_with($domain, 'www.')) $domain = substr($domain, 4);
        if (strlen($domain) < 4 || strlen($domain) > 253 || !str_contains($domain, '.')
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
    public function validate(string $key, string $domain, string $sku): string
    {
        $key = self::key($key);
        $domain = self::domain($domain);
        if (!isset(self::PRODUCT_MAP[$sku]) && !ModuleLicenseManager::sku($sku)) {
            throw new InvalidArgumentException('Unknown or unsupported Cajun Web Pro product SKU.');
        }
        // Development version strings are not sent: Cajun Web Pro requires a registered published version.
        $payload = json_encode(['license_key' => $key, 'domain' => $domain, 'product_id' => $sku], JSON_THROW_ON_ERROR);
        try {
            $response = $this->transport !== null ? ($this->transport)($payload) : $this->request($payload);
            if (!is_array($response) || !is_int($response['status'] ?? null)
                || !is_string($response['body'] ?? null) || strlen($response['body']) > 4096) return 'transient';
            if ($response['status'] !== 200) return 'transient';
            $json = json_decode($response['body'], true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($json)) return 'transient';
            if (($json['authorized'] ?? null) === true) return 'valid';
            if (($json['authorized'] ?? null) === false) return 'denied';
        } catch (\Throwable) { /* Don't convert networking/transient failure to revocation. */ }
        return 'transient';
    }

    /** @return array{edition:string,term:string,sku:string}|null */
    public function resolve(string $key, string $domain): ?array
    {
        // API is product-bound, not a discovery endpoint. Maximum four attempts per initial key;
        // never guess from host type or an untrusted edition field.
        $valid = null;
        $transient = false;
        foreach (self::PRODUCT_MAP as $sku => $product) {
            $answer = $this->validate($key, $domain, $sku);
            if ($answer === 'valid') {
                if ($valid !== null) throw new \RuntimeException('License was authorized for multiple editions. Contact Cajun Web Pro.');
                $valid = ['edition' => $product['edition'], 'term' => $product['term'], 'sku' => $sku];
            } elseif ($answer === 'transient') $transient = true;
        }
        if ($transient) throw new \RuntimeException('Cajun Web Pro validation is temporarily unavailable. Installation was not started.');
        return $valid;
    }

    /** @return array{status:int,body:string} */
    private function request(string $body): array
    {
        $context = stream_context_create([
            'http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\nAccept: application/json\r\nConnection: close\r\n",
                'content' => $body, 'timeout' => 4, 'ignore_errors' => true,
                'follow_location' => 0, 'max_redirects' => 0],
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
        } finally { fclose($handle); }
    }
}
