<?php

declare(strict_types=1);

namespace AdultCore\Capability;

final class HostInspector
{
    public function __construct(private readonly string $root, private readonly ProAgentClient $proAgent = new ProAgentClient())
    {
    }

    /**
     * @return array{capabilities: list<string>, checks: list<array{name: string, pass: bool, detail: string}>}
     */
    public function inspect(?string $documentRoot): array
    {
        $capabilities = [];
        $checks = [];
        $check = static function (string $id, string $name, bool $passes, string $detail) use (&$capabilities, &$checks): void {
            $checks[] = ['name' => $name, 'pass' => $passes, 'detail' => $detail];
            if ($passes) {
                $capabilities[] = $id;
            }
        };

        $check('php_83', 'PHP 8.3 or newer', PHP_VERSION_ID >= 80300, 'Detected PHP ' . PHP_VERSION);
        $check('pdo_mysql', 'PDO MySQL driver', extension_loaded('pdo_mysql'), 'Required to connect to MySQL/MariaDB.');
        $check('json', 'JSON extension', extension_loaded('json'), 'Required for manifests and configuration.');
        $check('openssl', 'OpenSSL extension', extension_loaded('openssl'), 'Required for secure cryptographic operations.');
        $check('fileinfo', 'Fileinfo extension', extension_loaded('fileinfo'), 'Required for media-type checks.');
        $check('sessions', 'PHP session extension', extension_loaded('session'), 'Required for the browser installer and user sessions.');
        $check('phar_zip', 'PHP Phar ZIP archive support', class_exists(\PharData::class) && extension_loaded('phar'), 'Optional for installing signed add-on/theme ZIPs; does not block the core installation.');

        $storage = realpath($this->root . '/storage');
        $safeStorage = $storage !== false && is_dir($storage) && is_writable($storage);
        $check('storage_writable', 'Application storage writable', $safeStorage, 'The storage directory must exist and be writable.');

        $config = realpath($this->root . '/config');
        $safeConfig = $config !== false && is_dir($config) && is_writable($config);
        $check('config_writable', 'Private configuration directory writable', $safeConfig, 'The config/ directory must be writable by PHP to store database credentials and the private application key.');

        $expectedDocroot = realpath($this->root . '/public');
        $actualDocroot = $documentRoot === null ? false : realpath($documentRoot);
        $public = $this->root . '/public';
        $apache = $public . '/.htaccess';
        $rules = is_file($apache) && !is_link($apache) ? @file_get_contents($apache) : false;
        $safeDocroot = $expectedDocroot !== false && $actualDocroot !== false && $expectedDocroot === $actualDocroot
            && PublicRootSafety::unsafePaths($public) === []
            && is_file($public . '/index.php') && !is_link($public . '/index.php')
            && is_file($public . '/router.php') && !is_link($public . '/router.php')
            && is_string($rules) && str_contains($rules, 'RewriteEngine On')
            && str_contains($rules, 'Options -Indexes')
            && str_contains($rules, '[F,L,NC]')
            && str_contains($rules, '<FilesMatch "^(?!index\\.php$)');
        $check('public_docroot', 'Secure public document root', $safeDocroot,
            'The domain document root must be the bundled public/ directory with safe routing files and no private trees, unexpected scripts or symlinks.');

        $agentCapabilities = $this->proAgent->detectCapabilities($this->root);
        foreach ($agentCapabilities as $cap) {
            $capabilities[] = $cap;
        }
        $checks[] = [
            'name' => 'Verified Pro background service',
            'pass' => in_array('pro_agent', $agentCapabilities, true),
            'detail' => in_array('pro_agent', $agentCapabilities, true)
                ? 'Authenticated local Pro agent is responding.'
                : 'Optional: requires an authenticated service on this VPS.',
        ];

        return ['capabilities' => array_values(array_unique($capabilities)), 'checks' => $checks];
    }
}
