<?php

declare(strict_types=1);

namespace AdultCore\Licensing;

use AdultCore\Installer\AtomicFile;
use RuntimeException;

/** Install-time activation, server-side renewal, and online Cajun Web Pro revocation enforcement. */
final class LicenseManager
{
    public const REFRESH_SECONDS = 3600;
    public const TRANSIENT_GRACE_SECONDS = 72 * 3600;

    public function __construct(private readonly string $root, private readonly ?CajunWebClient $client = null) {}

    private function authority(): CajunWebClient { return $this->client ?? new CajunWebClient(); }

    /** Only a verified, genuine authorized response permits first-time setup. */
    public function verifyForInstall(string $key, string $domain): array
    {
        $key = CajunWebClient::key($key);
        $domain = CajunWebClient::domain($domain);
        $result = $this->authority()->validate($key, $domain);
        if ($result !== 'valid') {
            throw new RuntimeException($result === 'denied'
                ? 'License not authorized for Adult_Core on this domain. Register this domain in Cajun Web Pro.'
                : 'Cajun Web Pro validation is temporarily unavailable. Installation was not started.');
        }
        return ['license_key' => $key, 'domain' => $domain, 'sku' => CajunWebClient::SKU];
    }

    /** Only used once, before the install completion marker is written. */
    public function persistInitial(array $record, string $appKey): void
    {
        $path = $this->root . '/config/license.php';
        AtomicFile::write($path, $this->configCode($record));
        AtomicFile::write($this->root . '/storage/license-cache.json', LicenseState::encode(
            $record['license_key'], $record['domain'], $appKey, 'valid', time(), time()
        ));
    }

    /** License updates require a reauthenticated administrator+CSRF in the controller. */
    public function replaceLicense(string $key, string $domain): void
    {
        $record = $this->verifyForInstall($key, $domain);
        $appKey = $this->appKey();
        if ($appKey === null) throw new RuntimeException('Installation secret unavailable.');
        $lock = $this->root . '/storage/.license-update.lock';
        $fp = @fopen($lock, 'c+');
        if ($fp === false) throw new RuntimeException('License update lock unavailable.');
        try {
            if (!flock($fp, LOCK_EX)) throw new RuntimeException('License update lock unavailable.');
            $this->replacePrivate($this->root . '/config/license.php', $this->configCode($record));
            $this->replacePrivate($this->root . '/storage/license-cache.json', LicenseState::encode(
                $record['license_key'], $record['domain'], $appKey, 'valid', time(), time()
            ));
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /** @return 'valid'|'license_required'|'domain_mismatch'|'denied'|'unavailable' */
    public function status(?string $host = null): string
    {
        $record = $this->config();
        if ($record === null) return 'license_required';
        try {
            $incoming = $host ?? (string) ($_SERVER['HTTP_HOST'] ?? '');
            // An HTTP Host may include a port on local test servers. Do not allow URLs.
            if (!preg_match('/^([^:]+?)(?::[0-9]{1,5})?$/D', $incoming, $hostMatch)) return 'domain_mismatch';
            $actualHost = CajunWebClient::domain($hostMatch[1]);
        } catch (\Throwable) { return 'domain_mismatch'; }
        if (!hash_equals($record['domain'], $actualHost)) return 'domain_mismatch';
        $appKey = $this->appKey();
        if ($appKey === null) return 'license_required';
        $cached = $this->cache($record, $appKey);
        $now = time();
        if ($cached !== null) {
            if ($cached['verdict'] === 'denied' && $cached['checked_at'] + self::REFRESH_SECONDS > $now) return 'denied';
            if ($cached['verdict'] === 'valid' && $cached['checked_at'] + self::REFRESH_SECONDS > $now) return 'valid';
        }
        // A single in-flight network refresh per installation. No nonblocking
        // request can bypass the grace bound or a recorded explicit denial.
        $fp = @fopen($this->root . '/storage/.license-refresh.lock', 'c+');
        if ($fp === false) return $this->transientVerdict($cached, $now);
        try {
            if (!flock($fp, LOCK_EX | LOCK_NB)) return $this->transientVerdict($cached, $now);
            $cached = $this->cache($record, $appKey);
            if ($cached !== null && $cached['checked_at'] + self::REFRESH_SECONDS > $now) return $cached['verdict'];
            $verdict = $this->authority()->validate($record['license_key'], $record['domain']);
            if ($verdict === 'valid' || $verdict === 'denied') {
                $last = $verdict === 'valid' ? $now : ($cached['last_success'] ?? 0);
                $raw = LicenseState::encode($record['license_key'], $record['domain'], $appKey, $verdict, $now, $last);
                try { $this->replacePrivate($this->root . '/storage/license-cache.json', $raw); }
                catch (\Throwable) { return 'unavailable'; }
                return $verdict;
            }
            return $this->transientVerdict($cached, $now);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /** @return array{license_key:string,domain:string,sku:string}|null */
    public function config(): ?array
    {
        $path = $this->root . '/config/license.php';
        if (!is_file($path) || is_link($path) || !is_readable($path)) return null;
        try {
            $value = require $path;
            if (!is_array($value) || ($value['sku'] ?? null) !== CajunWebClient::SKU) return null;
            return [
                'license_key' => CajunWebClient::key((string) ($value['license_key'] ?? '')),
                'domain' => CajunWebClient::domain((string) ($value['domain'] ?? '')),
                'sku' => CajunWebClient::SKU,
            ];
        } catch (\Throwable) { return null; }
    }

    private function appKey(): ?string
    {
        $path = $this->root . '/config/app-key.php';
        if (!is_file($path) || !is_readable($path) || is_link($path)) return null;
        try {
            $secret = require $path;
            return is_string($secret) && preg_match('/^[a-f0-9]{64}$/D', $secret) ? $secret : null;
        } catch (\Throwable) { return null; }
    }

    private function cache(array $record, string $appKey): ?array
    {
        $path = $this->root . '/storage/license-cache.json';
        if (!is_file($path) || !is_readable($path) || is_link($path)) return null;
        $raw = @file_get_contents($path);
        return is_string($raw) ? LicenseState::decode($raw, $record['license_key'], $record['domain'], $appKey) : null;
    }

    private function transientVerdict(?array $cached, int $now): string
    {
        if ($cached !== null && $cached['verdict'] === 'denied') return 'denied';
        return $cached !== null && $cached['verdict'] === 'valid' && $cached['last_success'] > 0
            && $cached['last_success'] + self::TRANSIENT_GRACE_SECONDS > $now ? 'valid' : 'unavailable';
    }

    private function configCode(array $record): string
    {
        return "<?php\n// Cajun Web Pro license (private; NEVER commit, display or log this key).\nreturn " . var_export($record, true) . ";\n";
    }

    private function replacePrivate(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir) || is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new RuntimeException('Private licensing storage unavailable.');
        }
        $temp = tempnam($dir, '.license-');
        if ($temp === false) throw new RuntimeException('Private licensing storage unavailable.');
        try {
            if (!chmod($temp, 0600) || file_put_contents($temp, $contents, LOCK_EX) !== strlen($contents)
                || !rename($temp, $path)) throw new RuntimeException('Could not save private license state.');
        } finally {
            if (is_file($temp)) unlink($temp);
        }
    }
}
