<?php

declare(strict_types=1);

namespace AdultCore\Licensing;

use JsonException;

/** Tamper-evident authority cache; never let a public URL write or sign a verdict. */
final class LicenseState
{
    public static function fingerprint(string $key, string $domain): string
    {
        return hash('sha256', CajunWebClient::SKU . "\0" . $domain . "\0" . $key);
    }

    public static function encode(string $key, string $domain, string $appKey, string $verdict, int $checkedAt, int $lastSuccess): string
    {
        if (!in_array($verdict, ['valid', 'denied'], true)) throw new \InvalidArgumentException('Invalid license verdict.');
        $data = [
            'schema' => 1, 'sku' => CajunWebClient::SKU,
            'fingerprint' => self::fingerprint($key, $domain),
            'verdict' => $verdict, 'checked_at' => $checkedAt, 'last_success' => $lastSuccess,
        ];
        $canonical = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $data['mac'] = hash_hmac('sha256', $canonical, $appKey);
        return json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /** @return array{verdict:string,checked_at:int,last_success:int}|null */
    public static function decode(string $raw, string $key, string $domain, string $appKey, ?int $now = null): ?array
    {
        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($data) || ($data['schema'] ?? null) !== 1 || ($data['sku'] ?? null) !== CajunWebClient::SKU
                || ($data['fingerprint'] ?? null) !== self::fingerprint($key, $domain)
                || !in_array($data['verdict'] ?? null, ['valid', 'denied'], true)
                || !is_int($data['checked_at'] ?? null) || !is_int($data['last_success'] ?? null)
                || !is_string($data['mac'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $data['mac'])) return null;
            $canonical = json_encode([
                'schema' => $data['schema'], 'sku' => $data['sku'], 'fingerprint' => $data['fingerprint'],
                'verdict' => $data['verdict'], 'checked_at' => $data['checked_at'],
                'last_success' => $data['last_success'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (!hash_equals(hash_hmac('sha256', $canonical, $appKey), $data['mac'])) return null;
            $now ??= time();
            if ($data['checked_at'] < 0 || $data['checked_at'] > $now + 300
                || $data['last_success'] < 0 || $data['last_success'] > $data['checked_at']) return null;
            return ['verdict' => $data['verdict'], 'checked_at' => $data['checked_at'], 'last_success' => $data['last_success']];
        } catch (\Throwable) {
            return null;
        }
    }
}
