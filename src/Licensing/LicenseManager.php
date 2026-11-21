<?php

declare(strict_types=1);

namespace AdultCore\Licensing;

use AdultCore\Installer\AtomicFile;
use AdultCore\Installer\InstallationState;
use RuntimeException;

/** Install-time activation, server-side renewal, and online Cajun Web Pro revocation enforcement. */
final class LicenseManager
{
    public const REFRESH_SECONDS = 3600;
    public const TRANSIENT_GRACE_SECONDS = 72 * 3600;

    private ?string $lastAuthorityResponse = null;

    public function __construct(private readonly string $root, private readonly ?CajunWebClient $client = null) {}

    /** Last response from this request only; never persisted or shown with a license key. */
    public function lastAuthorityResponse(): ?string { return $this->lastAuthorityResponse; }


    private function authority(): CajunWebClient { return $this->client ?? new CajunWebClient(); }

    /** Only a verified, genuine authorized response permits first-time setup. */
    public function verifyForInstall(string $key, string $domain): array
    {
        $key = CajunWebClient::key($key);
        $domain = CajunWebClient::domain($domain);
        $product = $this->authority()->resolve($key, $domain);
        if ($product === null) {
            throw new RuntimeException('License not authorized for Adult_Core Lite or Pro on this domain. Check your Cajun Web Pro registration.');
        }
        return ['license_key' => $key, 'domain' => $domain] + $product;
    }

    /** Only used once, before the install completion marker is written. */
    public function persistInitial(array $record, string $appKey): void
    {
        $path = $this->root . '/config/license.php';
        AtomicFile::write($path, $this->configCode($record));
        AtomicFile::write($this->root . '/storage/license-cache.json', LicenseState::encode(
            $record['license_key'], $record['domain'], $appKey, 'valid', time(), time(), $record['sku']
        ));
    }

    /** License updates require a reauthenticated administrator+CSRF in the controller. */
    public function replaceLicense(string $key, string $domain): void
    {
        $marker = $this->root . LicenseUpdateRecovery::MARKER;
        if (file_exists($marker) || is_link($marker)) {
            throw new RuntimeException('Interrupted license update requires server-console rollback.');
        }
        $record = $this->verifyForInstall($key, $domain);
        $appKey = $this->appKey();
        if ($appKey === null) throw new RuntimeException('Installation secret unavailable.');
        // Never change a live edition by replacing a key; signed installation
        // state, DB migrations and verified Pro host requirements must agree.
        $installed = $this->signedEdition($appKey);
        if ($installed === null || $record['edition'] !== $installed) {
            throw new RuntimeException('This license belongs to another edition. An edition upgrade requires a separate verified migration; no files were changed.');
        }
        $lock = $this->root . '/storage/.license-update.lock';
        if (is_link($lock)) throw new RuntimeException('Unsafe license update lock file.');
        $fp = @fopen($lock, 'c+');
        if ($fp === false) throw new RuntimeException('License update lock unavailable.');
        try {
            if (!flock($fp, LOCK_EX)) throw new RuntimeException('License update lock unavailable.');
            if (file_exists($marker) || is_link($marker)) {
                throw new RuntimeException('Interrupted license update requires server-console rollback.');
            }
            // A running refresh must not race the two-file replacement.
            $refreshPath = $this->root . '/storage/.license-refresh.lock';
            if (is_link($refreshPath)) throw new RuntimeException('Unsafe license refresh lock file.');
            $refresh = @fopen($refreshPath, 'c+');
            if (!is_resource($refresh)) throw new RuntimeException('License refresh lock unavailable.');
            try {
                if (!flock($refresh, LOCK_EX)) throw new RuntimeException('License refresh lock unavailable.');
                $licensePath = $this->root . '/config/license.php';
                $cachePath = $this->root . '/storage/license-cache.json';
                $this->assertPrivateReplaceable($licensePath);
                $this->assertPrivateReplaceable($cachePath);
                $previous = $this->config();
                if ($previous === null || $previous['edition'] !== $installed) {
                    throw new RuntimeException('Current installed license could not be verified.');
                }
                $oldCache = null;
                if (is_file($cachePath)) {
                    $oldCache = @file_get_contents($cachePath);
                    if (!is_string($oldCache)) throw new RuntimeException('Private license cache unreadable.');
                }
                // Publish a signed rollback snapshot BEFORE either file changes.
                // If interrupted, the site stays locked until CLI recovery.
                AtomicFile::write($marker, LicenseUpdateRecovery::snapshot($previous, $oldCache, $appKey));
                $this->replacePrivate($licensePath, $this->configCode($record));
                $this->replacePrivate($cachePath, LicenseState::encode(
                    $record['license_key'], $record['domain'], $appKey, 'valid', time(), time(), $record['sku']
                ));
                if (!@unlink($marker)) {
                    throw new RuntimeException('License update completed but recovery marker could not be cleared.');
                }
            } finally {
                flock($refresh, LOCK_UN);
                fclose($refresh);
            }
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /** @return 'valid'|'license_required'|'domain_mismatch'|'denied'|'unavailable'
     * Force refresh is reserved for an authenticated, CSRF-protected administrator action.
     * It still honors domain checks, single-flight locking and signed cache storage.
     */
    public function status(?string $host = null, bool $forceRefresh = false): string
    {
        $this->lastAuthorityResponse = null;
        $marker = $this->root . LicenseUpdateRecovery::MARKER;
        if (file_exists($marker) || is_link($marker)) return 'unavailable';
        $record = $this->config();
        if ($record === null) return 'license_required';
        try {
            $incoming = $host ?? (string) ($_SERVER['HTTP_HOST'] ?? '');
            // An HTTP Host may include a port on local test servers, but
            // malformed/overflow ports and URL syntax must not pass domain
            // authorization even when the hostname itself matches.
            if (!preg_match('/^([A-Za-z0-9.-]+)(?::([0-9]{1,5}))?$/D', $incoming, $hostMatch)
                || (isset($hostMatch[2]) && ((int) $hostMatch[2] < 1 || (int) $hostMatch[2] > 65535))) {
                return 'domain_mismatch';
            }
            $actualHost = CajunWebClient::domain($hostMatch[1]);
        } catch (\Throwable) { return 'domain_mismatch'; }
        if (!hash_equals($record['domain'], $actualHost)) return 'domain_mismatch';
        $appKey = $this->appKey();
        if ($appKey === null) return 'license_required';
        // A symlinked lock signals unsafe private storage, even if a previous
        // signed positive verdict is still within its one-hour cache window.
        $refreshPath = $this->root . '/storage/.license-refresh.lock';
        if (is_link($refreshPath) || is_link($this->root . '/storage/.license-update.lock')) {
            return 'unavailable';
        }
        $cached = $this->cache($record, $appKey);
        $now = time();
        if ($cached !== null) {
            if (!$forceRefresh && $cached['verdict'] === 'denied' && $cached['checked_at'] + self::REFRESH_SECONDS > $now) return 'denied';
            if (!$forceRefresh && $cached['verdict'] === 'valid' && $cached['checked_at'] + self::REFRESH_SECONDS > $now) return 'valid';
        }
        // A single in-flight network refresh per installation. No nonblocking
        // request can bypass the grace bound or a recorded explicit denial.
        $fp = @fopen($refreshPath, 'c+');
        if ($fp === false) return $this->transientVerdict($cached, $now);
        try {
            if (!flock($fp, LOCK_EX | LOCK_NB)) return $this->transientVerdict($cached, $now);
            $cached = $this->cache($record, $appKey);
            if (!$forceRefresh && $cached !== null && $cached['checked_at'] + self::REFRESH_SECONDS > $now) return $cached['verdict'];
            $verdict = $this->authority()->validate($record['license_key'], $record['domain'], $record['sku']);
            $this->lastAuthorityResponse = $verdict;
            if ($verdict === 'valid' || $verdict === 'denied') {
                $last = $verdict === 'valid' ? $now : ($cached['last_success'] ?? 0);
                $raw = LicenseState::encode($record['license_key'], $record['domain'], $appKey, $verdict, $now, $last, $record['sku']);
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

    /** @return array{license_key:string,domain:string,sku:string,edition:string,term:string}|null */
    public function config(): ?array
    {
        $path = $this->root . '/config/license.php';
        if (!is_file($path) || is_link($path) || !is_readable($path)) return null;
        try {
            $value = require $path;
            if (!is_array($value) || !is_string($value['sku'] ?? null)) return null;
            $identity = CajunWebClient::identity($value['sku']);
            if ($identity === null) return null;
            $key = $this->appKey();
            // Before the first install marker, only the installation service
            // writes config. Once a marker exists it must be valid and edition-matched.
            if (is_file($this->root . '/storage/install-state.json')
                && ($key === null || $this->signedEdition($key) !== $identity['edition'])) return null;
            return [
                'license_key' => CajunWebClient::key((string) ($value['license_key'] ?? '')),
                'domain' => CajunWebClient::domain((string) ($value['domain'] ?? '')),
                'sku' => $value['sku'], 'edition' => $identity['edition'], 'term' => $identity['term'],
            ];
        } catch (\Throwable) { return null; }
    }

    /** Edition comes from the HMAC-signed marker, never an HTTP parameter. */
    private function signedEdition(string $appKey): ?string
    {
        $path = $this->root . '/storage/install-state.json';
        if (!is_file($path) || is_link($path)) return null;
        $raw = @file_get_contents($path);
        if (!is_string($raw) || !InstallationState::valid($raw, $appKey)) return null;
        $state = json_decode($raw, true);
        return in_array($state['edition'] ?? null, ['lite','pro'], true) ? $state['edition'] : null;
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
        return is_string($raw) ? LicenseState::decode($raw, $record['license_key'], $record['domain'], $appKey, $record['sku']) : null;
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

    private function assertPrivateReplaceable(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) || !is_writable($dir) || is_link($path)
            || (file_exists($path) && !is_file($path))) {
            throw new RuntimeException('Private licensing storage unavailable.');
        }
    }

    private function replacePrivate(string $path, string $contents): void
    {
        $this->assertPrivateReplaceable($path);
        $dir = dirname($path);
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
