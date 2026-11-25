<?php

declare(strict_types=1);

namespace AdultCore\Licensing;

use AdultCore\Capability\HostInspector;
use AdultCore\Database\Connection;
use AdultCore\Database\Schema;
use AdultCore\Database\ProvisioningLock;
use AdultCore\Edition\EditionSelector;
use AdultCore\Installer\AtomicFile;
use AdultCore\Installer\InstallationGate;
use AdultCore\Installer\InstallationState;
use AdultCore\Release\ConsoleRecoveryGuard;
use Closure;
use PDO;
use RuntimeException;
use Throwable;

/**
 * One-installation Lite -> Pro transition. No application tables, pages, users, media,
 * theme selections or package records are rebuilt, deleted or copied.
 *
 * Cross-filesystem/database atomicity is impossible; a private signed backup and
 * a maintenance sentinel make interrupted transitions fail closed until rollback.
 */
final class EditionUpgrade
{
    public const PENDING = '/storage/.edition-upgrade-pending';
    private const BACKUP = '/storage/.edition-upgrade-backup';
    private const FILES = ['license.php' => '/config/license.php', 'license-cache.json' => '/storage/license-cache.json',
        'install-state.json' => '/storage/install-state.json'];

    public function __construct(
        private readonly string $root,
        private readonly ?CajunWebClient $client = null,
        private readonly ?Closure $capabilities = null,
        private readonly ?Closure $afterDatabaseUpdate = null,
    ) {}

    /** For the admin read-only preflight; no vendor key needed. */
    public function prerequisites(): array
    {
        $docroot = PHP_SAPI === 'cli' ? $this->root . '/public'
            : (is_string($_SERVER['DOCUMENT_ROOT'] ?? null) ? $_SERVER['DOCUMENT_ROOT'] : null);
        $caps = $this->capabilities !== null ? ($this->capabilities)() : (new HostInspector($this->root))->inspect($docroot)['capabilities'];
        if (!is_array($caps) || !array_is_list($caps)) throw new RuntimeException('Host capability inspection unavailable.');
        return (new EditionSelector($this->root . '/config/editions'))->missing('pro', $caps);
    }

    /**
     * Revalidates the vendor entitlement and current database before any mutation.
     * @return array{edition:string,term:string,sku:string,domain:string}
     */
    public function preflight(string $candidateKey, string $domain, ?PDO $pdo = null): array
    {
        if (is_file($this->root . self::PENDING) || is_link($this->root . self::PENDING)
            || file_exists($this->root . self::BACKUP) || is_link($this->root . self::BACKUP)) {
            throw new RuntimeException('An earlier edition upgrade needs operator review or rollback. No changes were made.');
        }
        if ((new InstallationGate($this->root))->status() !== InstallationGate::READY) {
            throw new RuntimeException('Installation must be complete and install/ removed before upgrading.');
        }
        $current = (new LicenseManager($this->root))->config();
        if ($current === null || $current['edition'] !== 'lite') {
            throw new RuntimeException('Only a verified installed Lite edition can be upgraded to Pro.');
        }
        $domain = CajunWebClient::domain($domain);
        if (!hash_equals($current['domain'], $domain)) {
            throw new RuntimeException('Upgrade license must be registered to this existing installation domain.');
        }
        $missing = $this->prerequisites();
        if ($missing !== []) {
            throw new RuntimeException('Pro hosting is not ready: ' . implode(', ', $missing) . '. Nothing was changed.');
        }
        $pdo ??= Connection::open($this->root);
        $this->assertEdition($pdo, 'lite');
        Schema::verify($pdo);
        $record = (new LicenseManager($this->root, $this->client))->verifyForInstall($candidateKey, $domain);
        if ($record['edition'] !== 'pro') {
            throw new RuntimeException('Only a Cajun Web Pro Pro-edition license can upgrade this installation.');
        }
        if (!in_array($record['sku'], [CajunWebClient::SKU_PRO_ONCE, CajunWebClient::SKU_PRO_MONTH], true)) {
            throw new RuntimeException('Unrecognized Pro product.');
        }
        return ['edition' => 'pro', 'term' => $record['term'], 'sku' => $record['sku'], 'domain' => $domain];
    }

    /** Reauthentication, CSRF and rate limiting are enforced by the admin controller. */
    public function upgrade(string $candidateKey, string $domain): void
    {
        $this->locked(function () use ($candidateKey, $domain): void {
            $pdo = Connection::open($this->root);
            $databaseLock = ProvisioningLock::acquire($pdo);
            try {
                $plan = $this->preflight($candidateKey, $domain, $pdo);
                $key = CajunWebClient::key($candidateKey);
                $appKey = $this->appKey();
                $oldState = $this->existingState($appKey);
                $this->takeBackup($appKey);
                $pending = false;
                try {
                    AtomicFile::write($this->root . self::PENDING, "Upgrade in progress; do not remove this file manually.\n");
                    $pending = true;
                    // No schema change: preserve all existing data and installed_at.
                    $pdo->beginTransaction();
                    try {
                        $q = $pdo->prepare("UPDATE ac_installations SET edition='pro' WHERE id=1 AND edition='lite'");
                        $q->execute();
                        if ($q->rowCount() !== 1) throw new RuntimeException('Database edition changed during upgrade.');
                        $pdo->commit();
                    } catch (Throwable $error) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        throw $error;
                    }
                    if ($this->afterDatabaseUpdate !== null) ($this->afterDatabaseUpdate)();
                    $databaseLock->assertHeld();
                    $record = ['license_key' => $key, 'domain' => $plan['domain'], 'sku' => $plan['sku'],
                        'edition' => 'pro', 'term' => $plan['term']];
                    $this->replace(self::FILES['license.php'], $this->configCode($record));
                    $this->replace(self::FILES['license-cache.json'], LicenseState::encode(
                        $key, $plan['domain'], $appKey, 'valid', time(), time(), $plan['sku']));
                    $this->replace(self::FILES['install-state.json'], InstallationState::encode(
                        'pro', $appKey, $oldState['installed_at']));
                    $this->assertEdition($pdo, 'pro');
                    if ((new LicenseManager($this->root))->config()['sku'] !== $plan['sku']) {
                        throw new RuntimeException('Pro edition receipt did not verify.');
                    }
                    // Only remove maintenance mode after every persisted state agrees.
                    if (!unlink($this->root . self::PENDING)) throw new RuntimeException('Could not clear maintenance lock.');
                    $pending = false;
                    // Once the signed Pro state and DB agree, the website is live.
                    // A cleanup failure cannot safely be reported as a failed
                    // transition; leave private backups for operator cleanup.
                    try { $this->cleanBackup(); }
                    catch (Throwable) { error_log('Adult_Core edition upgrade succeeded; private recovery-file cleanup needs review.'); }
                } catch (Throwable $error) {
                    if ($pending) {
                        try { $this->rollbackLocked($pdo, $appKey, $databaseLock); }
                        catch (Throwable $rollbackError) {
                            // Keep maintenance lock and backups. CLI repair is required.
                            throw new RuntimeException('Upgrade interrupted and automatic rollback failed. Site remains locked; use CLI recovery.', 0, $rollbackError);
                        }
                    }
                    throw new RuntimeException('Pro upgrade did not complete. Existing Lite installation was retained or restored.', 0, $error);
                }
            } finally {
                $databaseLock->release();
            }
        });
    }

    /** Recovery from a crashed process: run locally with `php scripts/recover-edition-upgrade.php --rollback`. */
    public function rollback(): void
    {
        $guard = ConsoleRecoveryGuard::enter($this->root, InstallationGate::EDITION_UPGRADE_PENDING);
        try {
            $this->locked(function (): void {
                if (!is_file($this->root . self::PENDING) || is_link($this->root . self::PENDING)) {
                    throw new RuntimeException('No pending edition transition is recorded. Nothing was changed.');
                }
                $pdo = Connection::open($this->root);
                $databaseLock = ProvisioningLock::acquire($pdo);
                try {
                    $this->rollbackLocked($pdo, $this->appKey(), $databaseLock);
                } finally {
                    $databaseLock->release();
                }
            });
        } finally {
            fclose($guard);
        }
    }

    private function rollbackLocked(PDO $pdo, string $appKey, ProvisioningLock $databaseLock): void
    {
        $databaseLock->assertHeld();
        $originals = $this->readBackup($appKey);
        $pdo->beginTransaction();
        try {
            $q = $pdo->prepare("UPDATE ac_installations SET edition='lite' WHERE id=1 AND edition IN ('lite','pro')");
            $q->execute();
            if ($q->rowCount() > 1) throw new RuntimeException('Edition rollback affected more than one installation.');
            $this->assertEdition($pdo, 'lite');
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
        $databaseLock->assertHeld();
        foreach (self::FILES as $name => $path) $this->replace($path, $originals[$name]);
        $current = (new LicenseManager($this->root))->config();
        if ($current === null || $current['edition'] !== 'lite') throw new RuntimeException('Restored Lite state is not verifiable.');
        if (!unlink($this->root . self::PENDING)) throw new RuntimeException('Could not clear pending upgrade lock.');
        $this->cleanBackup();
    }

    private function appKey(): string
    {
        $path = $this->root . '/config/app-key.php';
        if (!is_file($path) || is_link($path)) throw new RuntimeException('Installation secret is unavailable.');
        $secret = require $path;
        if (!is_string($secret) || preg_match('/^[a-f0-9]{64}$/D', $secret) !== 1) {
            throw new RuntimeException('Installation secret is invalid.');
        }
        return $secret;
    }

    private function existingState(string $appKey): array
    {
        $path = $this->root . self::FILES['install-state.json'];
        if (!is_file($path) || is_link($path)) throw new RuntimeException('Signed installation record unavailable.');
        $raw = file_get_contents($path);
        if (!is_string($raw) || !InstallationState::valid($raw, $appKey)) {
            throw new RuntimeException('Signed installation record is invalid.');
        }
        $state = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if ($state['edition'] !== 'lite') throw new RuntimeException('Only Lite installations may be upgraded.');
        return $state;
    }

    private function takeBackup(string $appKey): void
    {
        $dir = $this->root . self::BACKUP;
        if (!mkdir($dir, 0700) || !chmod($dir, 0700)) throw new RuntimeException('Secure upgrade backup could not be created.');
        try {
            $hashes = [];
            foreach (self::FILES as $name => $path) {
                $source = $this->root . $path;
                if (!is_file($source) || is_link($source)) throw new RuntimeException('Private edition metadata is incomplete.');
                $data = file_get_contents($source);
                if (!is_string($data) || strlen($data) > 20000) throw new RuntimeException('Private edition metadata is invalid.');
                AtomicFile::write($dir . '/' . $name, $data);
                $hashes[$name] = hash('sha256', $data);
            }
            $body = json_encode(['edition' => 'lite', 'hashes' => $hashes], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            AtomicFile::write($dir . '/manifest.json', json_encode([
                'data' => $body, 'mac' => hash_hmac('sha256', $body, hex2bin($appKey))
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } catch (Throwable $failure) {
            // No pending marker exists yet, so the site is still unchanged.
            // A partial secret-bearing snapshot must not be left behind.
            try { $this->cleanBackup(); } catch (Throwable) { /* Operator review required. */ }
            throw $failure;
        }
    }

    /** @return array<string,string> */
    private function readBackup(string $appKey): array
    {
        $dir = $this->root . self::BACKUP;
        $manifest = $dir . '/manifest.json';
        if (!is_dir($dir) || is_link($dir) || !is_file($manifest) || is_link($manifest)) throw new RuntimeException('Secure rollback files missing.');
        $m = json_decode((string)file_get_contents($manifest), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($m) || !is_string($m['data'] ?? null) || !is_string($m['mac'] ?? null)
            || !hash_equals(hash_hmac('sha256', $m['data'], hex2bin($appKey)), $m['mac'])) {
            throw new RuntimeException('Upgrade recovery manifest failed authentication.');
        }
        $meta = json_decode($m['data'], true, 8, JSON_THROW_ON_ERROR);
        if (($meta['edition'] ?? null) !== 'lite' || !is_array($meta['hashes'] ?? null)) throw new RuntimeException('Recovery manifest invalid.');
        $originals=[];
        foreach (self::FILES as $name => $_) {
            $path = $dir . '/' . $name;
            if (!is_file($path) || is_link($path)) throw new RuntimeException('Edition backup file missing.');
            $data = file_get_contents($path);
            if (!is_string($data) || strlen($data) > 20000 || !hash_equals((string)($meta['hashes'][$name] ?? ''), hash('sha256', $data))) {
                throw new RuntimeException('Edition backup integrity verification failed.');
            }
            $originals[$name]=$data;
        }
        if (!InstallationState::valid($originals['install-state.json'], $appKey)
            || (json_decode($originals['install-state.json'], true, 8, JSON_THROW_ON_ERROR)['edition'] ?? null) !== 'lite') {
            throw new RuntimeException('Original signed Lite state is invalid.');
        }
        return $originals;
    }

    private function cleanBackup(): void
    {
        $dir = $this->root . self::BACKUP;
        foreach (array_merge(array_keys(self::FILES), ['manifest.json']) as $name) {
            $path = $dir . '/' . $name;
            if (is_link($path)) throw new RuntimeException('Unsafe recovery file encountered.');
            if (is_file($path) && !unlink($path)) throw new RuntimeException('Could not clean private rollback file.');
        }
        if (is_dir($dir) && !rmdir($dir)) throw new RuntimeException('Could not remove private recovery directory.');
    }

    private function replace(string $relative, string $value): void
    {
        $path = $this->root . $relative;
        $dir = dirname($path);
        if (!is_dir($dir) || is_link($dir) || is_link($path) || !is_file($path)) {
            throw new RuntimeException('Private installation state cannot be safely replaced.');
        }
        $temp = tempnam($dir, '.edition-');
        if ($temp === false) throw new RuntimeException('Could not write private installation state.');
        try {
            if (!chmod($temp, 0600) || file_put_contents($temp, $value, LOCK_EX) !== strlen($value)
                || !rename($temp, $path)) throw new RuntimeException('Could not atomically update private installation state.');
        } finally { if (is_file($temp)) unlink($temp); }
    }

    private function configCode(array $record): string
    {
        return "<?php\n// Cajun Web Pro license (private; NEVER commit, display or log this key).\nreturn "
            . var_export($record, true) . ";\n";
    }

    private function assertEdition(PDO $pdo, string $expected): void
    {
        $q = $pdo->query('SELECT edition FROM ac_installations WHERE id=1');
        if ($q->fetchColumn() !== $expected) throw new RuntimeException('Database installed edition does not match verified state.');
    }

    private function locked(Closure $callback): void
    {
        $path = $this->root . '/storage/.edition-upgrade.lock';
        if (is_link($path)) throw new RuntimeException('Unsafe edition upgrade lock file.');
        $fp = @fopen($path, 'c+');
        if (!is_resource($fp)) throw new RuntimeException('Edition upgrade lock unavailable.');
        try {
            if (!flock($fp, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another edition operation is running.');
            // Edition upgrades and same-edition license replacements modify the
            // same private config/cache files. Serialize them with the shared
            // license update lock, then exclude in-flight license cache refresh
            // writers. Lock acquisition order: edition -> license -> refresh.
            $sharedPath = $this->root . '/storage/.license-update.lock';
            if (is_link($sharedPath)) throw new RuntimeException('Unsafe license update lock file.');
            $shared = @fopen($sharedPath, 'c+');
            if (!is_resource($shared)) throw new RuntimeException('License update lock unavailable.');
            try {
                if (!flock($shared, LOCK_EX | LOCK_NB)) {
                    throw new RuntimeException('Another license update is running.');
                }
                $refreshPath = $this->root . '/storage/.license-refresh.lock';
                if (is_link($refreshPath)) throw new RuntimeException('Unsafe license refresh lock file.');
                $refresh = @fopen($refreshPath, 'c+');
                if (!is_resource($refresh)) throw new RuntimeException('License refresh lock unavailable.');
                try {
                    if (!flock($refresh, LOCK_EX | LOCK_NB)) {
                        throw new RuntimeException('Another license refresh is running.');
                    }
                    $callback();
                } finally {
                    flock($refresh, LOCK_UN);
                    fclose($refresh);
                }
            } finally {
                flock($shared, LOCK_UN);
                fclose($shared);
            }
        } finally { flock($fp, LOCK_UN); fclose($fp); }
    }
}
