<?php

declare(strict_types=1);

namespace AdultCore\Licensing;

use AdultCore\Installer\InstallationState;
use RuntimeException;
use Throwable;

/**
 * CLI-only, fail-closed rollback of an interrupted two-file license replacement.
 * The private signed pending marker contains the previous licensed identity and
 * previous signed-cache bytes. It is never a source of new license authority.
 */
final class LicenseUpdateRecovery
{
    public const MARKER = '/storage/.license-update-pending';

    /** @param array<string,mixed> $record */
    public static function snapshot(array $record, ?string $cache, string $appKey): string
    {
        $identity = self::identity($record);
        if (!preg_match('/^[a-f0-9]{64}$/D', $appKey)
            || ($cache !== null && strlen($cache) > 8192)) {
            throw new RuntimeException('Invalid license rollback snapshot.');
        }
        $payload = [
            'schema' => 1,
            'license_key' => $identity['license_key'],
            'domain' => $identity['domain'],
            'sku' => $identity['sku'],
            'cache_base64' => $cache === null ? null : base64_encode($cache),
        ];
        $payload['mac'] = hash_hmac('sha256',
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            hex2bin($appKey));
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @return array{license_key:string,domain:string,sku:string,edition:string,cache:?string} */
    public static function decode(string $raw, string $appKey, string $installedEdition): array
    {
        if (strlen($raw) > 16384 || !preg_match('/^[a-f0-9]{64}$/D', $appKey)) {
            throw new RuntimeException('Invalid license rollback snapshot.');
        }
        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($data) || array_is_list($data) || count($data) !== 6
                || ($data['schema'] ?? null) !== 1
                || !is_string($data['mac'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $data['mac']) !== 1
                || !array_key_exists('cache_base64', $data)
                || ($data['cache_base64'] !== null && !is_string($data['cache_base64']))) {
                throw new RuntimeException('Invalid license rollback snapshot.');
            }
            $identity = self::identity($data);
            if ($identity['edition'] !== $installedEdition) {
                throw new RuntimeException('Rollback edition differs from signed installation.');
            }
            $cache = $data['cache_base64'] === null ? null : base64_decode($data['cache_base64'], true);
            if ($cache === false || ($cache !== null && strlen($cache) > 8192)) {
                throw new RuntimeException('Invalid license rollback cache.');
            }
            $mac = $data['mac'];
            unset($data['mac']);
            $expected = hash_hmac('sha256',
                json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                hex2bin($appKey));
            if (!hash_equals($expected, $mac)) {
                throw new RuntimeException('Rollback snapshot signature invalid.');
            }
            return $identity + ['cache' => $cache];
        } catch (Throwable) {
            throw new RuntimeException('Invalid or tampered license rollback snapshot.');
        }
    }

    /**
     * Must be invoked from the server console. An interruption during rollback
     * leaves the marker in place and a repeat invocation can finish restoration.
     * No issuer calls, charges or new license grants are made.
     */
    public function __construct(private readonly string $root) {}

    public function rollback(): void
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('Server console required.');
        $marker = $this->root . self::MARKER;
        if (!is_file($marker) || is_link($marker) || !is_readable($marker)) {
            throw new RuntimeException('A regular private pending license update is required.');
        }
        // Snapshot includes the previous private key: never process it if
        // group/other users could read it. The owner must secure permissions
        // and investigate exposure before retrying console recovery.
        $mode = @fileperms($marker);
        if ($mode === false || ($mode & 0077) !== 0) {
            throw new RuntimeException('Private rollback snapshot permissions are unsafe.');
        }
        $storage = $this->root . '/storage';
        if (is_link($storage . '/.license-update.lock')) {
            throw new RuntimeException('Unsafe license update lock file.');
        }
        $update = @fopen($storage . '/.license-update.lock', 'c+');
        if (!is_resource($update)) throw new RuntimeException('License update lock unavailable.');
        try {
            if (!flock($update, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another license update is running.');
            }
            if (is_link($storage . '/.license-refresh.lock')) {
                throw new RuntimeException('Unsafe license refresh lock file.');
            }
            $refresh = @fopen($storage . '/.license-refresh.lock', 'c+');
            if (!is_resource($refresh)) throw new RuntimeException('License refresh lock unavailable.');
            try {
                if (!flock($refresh, LOCK_EX)) throw new RuntimeException('License refresh lock unavailable.');
                $secretPath = $this->root . '/config/app-key.php';
                $statePath = $storage . '/install-state.json';
                if (is_link($secretPath) || !is_file($secretPath) || !is_readable($secretPath)
                    || is_link($statePath) || !is_file($statePath) || !is_readable($statePath)) {
                    throw new RuntimeException('Installed identity could not be verified.');
                }
                $appKey = require $secretPath;
                $signedState = @file_get_contents($statePath);
                if (!is_string($appKey) || !is_string($signedState)
                    || !InstallationState::valid($signedState, $appKey)) {
                    throw new RuntimeException('Installed identity could not be verified.');
                }
                $installedEdition = json_decode($signedState, true)['edition'] ?? null;
                $raw = @file_get_contents($marker);
                if (!is_string($raw) || !is_string($installedEdition)) {
                    throw new RuntimeException('Pending license snapshot unreadable.');
                }
                $previous = self::decode($raw, $appKey, $installedEdition);
                $licensePath = $this->root . '/config/license.php';
                $cachePath = $storage . '/license-cache.json';
                self::assertReplaceable($licensePath);
                self::assertReplaceable($cachePath);
                $record = [
                    'license_key' => $previous['license_key'],
                    'domain' => $previous['domain'],
                    'sku' => $previous['sku'],
                    'edition' => $previous['edition'],
                    'term' => CajunWebClient::identity($previous['sku'])['term'],
                ];
                $code = "<?php\n// Cajun Web Pro license (private; NEVER commit, display or log this key).\nreturn "
                    . var_export($record, true) . ";\n";
                // The marker remains in place until both restorations finish.
                self::replacePrivate($licensePath, $code);
                if ($previous['cache'] !== null) {
                    self::replacePrivate($cachePath, $previous['cache']);
                } elseif (file_exists($cachePath) && !@unlink($cachePath)) {
                    throw new RuntimeException('Could not remove replaced license cache.');
                }
                if (!@unlink($marker)) throw new RuntimeException('Could not clear pending license update.');
            } finally {
                flock($refresh, LOCK_UN);
                fclose($refresh);
            }
        } finally {
            flock($update, LOCK_UN);
            fclose($update);
        }
    }

    /** @param array<string,mixed> $record
     *  @return array{license_key:string,domain:string,sku:string,edition:string}
     */
    private static function identity(array $record): array
    {
        try {
            $sku = $record['sku'] ?? null;
            if (!is_string($sku) || ($product = CajunWebClient::identity($sku)) === null
                || !is_string($record['license_key'] ?? null)
                || !is_string($record['domain'] ?? null)) {
                throw new RuntimeException('Invalid rollback product.');
            }
            $key = CajunWebClient::key($record['license_key']);
            $domain = CajunWebClient::domain($record['domain']);
            if ($key !== $record['license_key'] || $domain !== $record['domain']) {
                throw new RuntimeException('Unnormalized rollback identity.');
            }
            return ['license_key' => $key, 'domain' => $domain, 'sku' => $sku,
                'edition' => $product['edition']];
        } catch (Throwable) {
            throw new RuntimeException('Invalid license rollback identity.');
        }
    }

    private static function assertReplaceable(string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) || !is_writable($directory) || is_link($path)
            || (file_exists($path) && !is_file($path))) {
            throw new RuntimeException('Private rollback destination unavailable.');
        }
    }

    private static function replacePrivate(string $path, string $data): void
    {
        self::assertReplaceable($path);
        $temporary = tempnam(dirname($path), '.license-rollback-');
        if ($temporary === false) throw new RuntimeException('Private rollback storage unavailable.');
        try {
            if (!chmod($temporary, 0600)
                || file_put_contents($temporary, $data, LOCK_EX) !== strlen($data)
                || !rename($temporary, $path)) {
                throw new RuntimeException('Could not restore private licensing file.');
            }
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
    }
}
