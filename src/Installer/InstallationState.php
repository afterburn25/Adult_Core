<?php

declare(strict_types=1);

namespace AdultCore\Installer;

use JsonException;
use RuntimeException;

/** A signed completion marker; filesystem-only verification on every request. */
final class InstallationState
{
    public static function encode(string $edition, string $appKey, ?string $timestamp = null): string
    {
        if (!in_array($edition, ['lite', 'pro'], true) || preg_match('/^[a-f0-9]{64}$/D', $appKey) !== 1) {
            throw new RuntimeException('Invalid installation state inputs.');
        }
        $stamp = $timestamp ?? gmdate('Y-m-d\TH:i:s\Z');
        $state = [
            'schema_version' => 2,
            'status' => 'complete',
            'edition' => $edition,
            'installed_at' => $stamp,
        ];
        $state['signature'] = self::signature($state, $appKey);
        return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    public static function valid(string $raw, string $appKey): bool
    {
        if (strlen($raw) > 4096 || preg_match('/^[a-f0-9]{64}$/D', $appKey) !== 1) {
            return false;
        }
        try {
            $state = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }
        if (!is_array($state) || array_is_list($state) || count($state) !== 5
            || ($state['schema_version'] ?? null) !== 2 || ($state['status'] ?? null) !== 'complete'
            || !in_array($state['edition'] ?? null, ['lite', 'pro'], true)
            || !is_string($state['installed_at'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $state['installed_at']) !== 1
            || !is_string($state['signature'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $state['signature']) !== 1) {
            return false;
        }
        return hash_equals(self::signature($state, $appKey), $state['signature']);
    }

    /** @param array<string, mixed> $state */
    private static function signature(array $state, string $appKey): string
    {
        $payload = json_encode([
            $state['schema_version'], $state['status'], $state['edition'], $state['installed_at'],
        ], JSON_THROW_ON_ERROR);
        return hash_hmac('sha256', $payload, hex2bin($appKey));
    }
}
