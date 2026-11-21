<?php

declare(strict_types=1);

namespace AdultCore\Installer;

use AdultCore\Database\Schema;
use AdultCore\Licensing\LicenseManager;
use AdultCore\Licensing\CajunWebClient;
use AdultCore\Capability\HostInspector;
use AdultCore\Edition\EditionSelector;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/** Executes a fresh install exactly once, after browser preflight, CSRF and rate limiting. */
final class InstallService
{
    public function __construct(private readonly string $root, private readonly ?CajunWebClient $licenseClient = null)
    {
    }

    /** @param array<string,mixed> $fields Validated by InstallInput. */
    public function install(array $fields, ?string $legacyHostSuggestion = null): void
    {
        $edition = 'unverified';
        $storage = $this->root . '/storage';
        $lock = fopen($storage . '/.installation.lock', 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Installation already running or locking unavailable.');
        }
        try {
            if ((new InstallationGate($this->root))->status() !== InstallationGate::SETUP_REQUIRED) {
                throw new RuntimeException('Installation is already complete or invalid.');
            }
            foreach (['/config/database.php', '/config/app-key.php', '/config/license.php', '/storage/license-cache.json', '/storage/install-state.json'] as $file) {
                if (file_exists($this->root . $file) || is_link($this->root . $file)) {
                    throw new RuntimeException('Installation files already exist. Restore a backup or contact the operator.');
                }
            }
            // Enforce hostname identity at the service boundary as well as in
            // the browser controller. CLI fixture installations have no HTTP
            // origin, so only actual web requests must supply a matching Host.
            // This check precedes any vendor requests or MySQL writes.
            if (PHP_SAPI !== 'cli') {
                InstallInput::assertRequestHost(
                    (string) ($fields['license_domain'] ?? ''),
                    (string) ($_SERVER['HTTP_HOST'] ?? '')
                );
            }
            // Denied or unreachable licensing means zero database creation and no completion marker.
            $licensing = new LicenseManager($this->root, $this->licenseClient);
            $license = $licensing->verifyForInstall((string) ($fields['license_key'] ?? ''), (string) ($fields['license_domain'] ?? ''));
            $edition = $license['edition']; // LICENSE chooses edition, NOT host auto-selection or a form field.
            $actualDocroot = PHP_SAPI === 'cli' ? $this->root . '/public'
                : (is_string($_SERVER['DOCUMENT_ROOT'] ?? null) ? $_SERVER['DOCUMENT_ROOT'] : null);
            $capabilities = (new HostInspector($this->root))->inspect($actualDocroot)['capabilities'];
            $missing = (new EditionSelector($this->root . '/config/editions'))->missing($edition, $capabilities);
            if ($missing !== []) {
                throw new RuntimeException('Your ' . strtoupper($edition) . ' license is valid, but this server lacks required capabilities: ' . implode(', ', $missing) . '. Installation was not started.');
            }
            $dsn = 'mysql:host=' . $fields['db_host'] . ';port=' . $fields['db_port'] . ';dbname=' . $fields['db_name'] . ';charset=utf8mb4';
            $pdo = new PDO($dsn, $fields['db_user'], $fields['db_password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            Schema::assertFresh($pdo);
            Schema::create($pdo);
            $passwordHash = password_hash($fields['admin_password'], PASSWORD_DEFAULT);
            if (!is_string($passwordHash)) {
                throw new RuntimeException('Could not hash administrator password.');
            }
            $installedAt = gmdate('Y-m-d H:i:s');
            $pdo->beginTransaction();
            try {
                $user = $pdo->prepare('INSERT INTO ac_users (email, password_hash, role, created_at) VALUES (:email, :hash, :role, :created)');
                $user->execute(['email' => $fields['admin_email'], 'hash' => $passwordHash, 'role' => 'admin', 'created' => $installedAt]);
                $setting = $pdo->prepare('INSERT INTO ac_settings (setting_key, setting_value) VALUES (:setting_key, :setting_value)');
                $setting->execute(['setting_key' => 'site_name', 'setting_value' => $fields['site_name']]);
                $migration = $pdo->prepare('INSERT INTO ac_schema_migrations (version, applied_at) VALUES (?, ?)');
                $migration->execute(['2026_11_20_initial', $installedAt]);
                $installation = $pdo->prepare('INSERT INTO ac_installations (id, edition, installed_at) VALUES (1, ?, ?)');
                $installation->execute([$edition, $installedAt]);
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            }
            Schema::verify($pdo);
            $appKey = bin2hex(random_bytes(32));
            $db = [
                'driver' => 'mysql', 'host' => $fields['db_host'], 'port' => $fields['db_port'],
                'database' => $fields['db_name'], 'username' => $fields['db_user'], 'password' => $fields['db_password'],
            ];
            AtomicFile::write($this->root . '/config/database.php', "<?php\n// Generated by Adult Core installation. Keep outside the public web root.\nreturn " . var_export($db, true) . ";\n");
            AtomicFile::write($this->root . '/config/app-key.php', "<?php\n// Generated by Adult Core installation. Keep private.\nreturn " . var_export($appKey, true) . ";\n");
            // Store owner-only vendor key and a signed positive receipt before finalizing.
            $licensing->persistInitial($license, $appKey);
            // The completion marker is ALWAYS the last write after verification.
            AtomicFile::write($storage . '/install-state.json', InstallationState::encode($edition, $appKey));
            $this->audit('install_completed', $edition);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function audit(string $event, string $edition): void
    {
        // Never log passwords, connection strings, tokens or emails.
        $entry = json_encode(['at' => gmdate('c'), 'event' => $event, 'edition' => $edition], JSON_THROW_ON_ERROR);
        @file_put_contents($this->root . '/storage/install-audit.jsonl', $entry . "\n", FILE_APPEND | LOCK_EX);
    }
}
