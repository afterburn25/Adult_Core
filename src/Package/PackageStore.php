<?php

declare(strict_types=1);

namespace AdultCore\Package;

use AdultCore\Module\ModuleCompatibility;
use AdultCore\Licensing\CajunWebClient;
use AdultCore\Licensing\ModuleLicenseManager;
use JsonException;
use RuntimeException;
use Throwable;

/** Private, lock-serialized package records; no module or theme code is executed. */
final class PackageStore
{
    private readonly string $directory;
    private readonly PackageVerifier $verifier;
    private readonly ModuleCompatibility $compatibility;
    private readonly ModuleLicenseManager $moduleLicenses;

    public function __construct(private readonly string $root, private readonly ?CajunWebClient $licenseClient = null)
    {
        $this->directory = $root . '/storage/packages';
        $this->verifier = new PackageVerifier($root . '/config/trusted-publishers');
        $this->compatibility = new ModuleCompatibility();
        $this->moduleLicenses = new ModuleLicenseManager($root,$licenseClient);
    }

    /** @param list<string> $capabilities */
    public function install(string $incomingZip, string $edition, array $capabilities): array
    {
        return $this->locked(function () use ($incomingZip, $edition, $capabilities): array {
            if (!is_file($incomingZip) || is_link($incomingZip)
                || (filesize($incomingZip) ?: 0) > PackageVerifier::MAX_ARCHIVE_BYTES) {
                throw new RuntimeException('The selected ZIP file is invalid or exceeds the upload limit.');
            }
            $temporaryZip = $this->directory . '/.upload-' . bin2hex(random_bytes(10)) . '.zip';
            if (!copy($incomingZip, $temporaryZip)) {
                throw new RuntimeException('Could not stage the uploaded package.');
            }
            chmod($temporaryZip, 0600);
            try {
                $package = $this->verifier->inspect($temporaryZip);
                $manifest = $package['manifest'];
                $registry = $this->readRegistry();
                $type = $manifest['type'];
                $id = $manifest['id'];
                $category = $type === 'module' ? 'modules' : 'themes';
                $this->assertCompatible($manifest, $edition, $capabilities, $this->runtimeActiveModules($registry, $edition, $capabilities));
                $destination = $this->directory . '/' . $category . '/' . $id;
                if (isset($registry[$category][$id]) || file_exists($destination) || is_link($destination)) {
                    throw new RuntimeException('A package with this identifier is already installed. Updates are not supported yet.');
                }
                $staged = $this->directory . '/.staging-' . bin2hex(random_bytes(12));
                self::mkdirPrivate($staged);
                try {
                    self::writePrivate($staged . '/package.json', $package['raw_manifest']);
                    self::writePrivate($staged . '/package.sig', $package['signature']);
                    self::mkdirPrivate($staged . '/payload');
                    foreach ($package['files'] as $path => $contents) {
                        $target = $staged . '/payload/' . $path;
                        self::mkdirPrivate(dirname($target));
                        self::writePrivate($target, $contents);
                    }
                    // Verify staged bytes against the trusted publisher before publication.
                    $verified = $this->verifier->verifyInstalled($staged);
                    if ($verified['id'] !== $id || $verified['type'] !== $type) {
                        throw new RuntimeException('Staged package identity mismatch.');
                    }
                    self::mkdirPrivate(dirname($destination));
                    if (!rename($staged, $destination)) {
                        throw new RuntimeException('Could not publish the installed package.');
                    }
                    try {
                        $registry[$category][$id] = [
                            'version' => $manifest['version'],
                            'publisher' => $manifest['publisher'],
                            'enabled' => false,
                            'installed_at' => gmdate('Y-m-d\TH:i:s\Z'),
                        ];
                        $this->writeRegistry($registry);
                    } catch (Throwable $e) {
                        self::removeDirectory($destination);
                        throw $e;
                    }
                } finally {
                    if (is_dir($staged)) {
                        self::removeDirectory($staged);
                    }
                }
                return $manifest;
            } finally {
                @unlink($temporaryZip);
            }
        });
    }

    /**
     * Read-only gate for a future signed data-only update workflow.
     * No files or registry entries are rewritten, and no code is executed.
     * Plans are advisory; a later updater MUST revalidate under lock.
     *
     * @param list<string> $capabilities
     * @return array{id:string,type:string,publisher:string,current_version:string,proposed_version:string}
     */
    public function previewUpdate(string $incomingZip, string $edition, array $capabilities): array
    {
        if (!is_file($incomingZip) || is_link($incomingZip)
            || !is_readable($incomingZip) || (filesize($incomingZip) ?: 0) > PackageVerifier::MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('The proposed update ZIP is invalid.');
        }
        $candidate = $this->verifier->inspect($incomingZip)['manifest'];
        $type = $candidate['type'];
        $id = $candidate['id'];
        $category = self::category($type);
        $registry = $this->readRegistry();
        $installed = $registry[$category][$id] ?? null;
        if (!is_array($installed)) {
            throw new RuntimeException('Update target is not installed.');
        }
        if (($installed['enabled'] ?? false) === true
            || ($type === 'theme' && ($registry['selected_theme'] ?? null) === $id)) {
            throw new RuntimeException('Disable the installed package before inspecting an update.');
        }
        $current = $this->installedManifest($type, $id);
        if ($candidate['publisher'] !== $current['publisher']
            || $candidate['publisher'] !== $installed['publisher']
            || ($candidate['license_sku'] ?? null) !== ($current['license_sku'] ?? null)) {
            throw new RuntimeException('Update must retain the signed publisher and paid-product identity.');
        }
        if (!is_string($installed['version'])
            || $installed['version'] !== $current['version']
            || version_compare($candidate['version'], $current['version'], '<=')) {
            throw new RuntimeException('Update must be a strictly newer signed version.');
        }
        $this->assertCompatible($candidate, $edition, $capabilities,
            $this->runtimeActiveModules($registry, $edition, $capabilities));
        return [
            'id' => $id, 'type' => $type, 'publisher' => $candidate['publisher'],
            'current_version' => $current['version'],
            'proposed_version' => $candidate['version'],
        ];
    }

    /** @param list<string> $capabilities */
    public function changeStatus(string $type, string $id, bool $enable, string $edition, array $capabilities, ?string $moduleLicenseKey = null): void
    {
        $this->locked(function () use ($type, $id, $enable, $edition, $capabilities, $moduleLicenseKey): void {
            $category = self::category($type);
            self::validateId($id);
            $registry = $this->readRegistry();
            if (!isset($registry[$category][$id])) {
                throw new RuntimeException('Package is not installed.');
            }
            if ($enable) {
                $manifest = $this->installedManifest($type, $id);
                $this->assertCompatible($manifest, $edition, $capabilities, $this->runtimeActiveModules($registry, $edition, $capabilities));
                if ($type === 'module' && isset($manifest['license_sku'])) {
                    if (is_string($moduleLicenseKey) && trim($moduleLicenseKey) !== '') {
                        $this->moduleLicenses->activate($id, $manifest['license_sku'], $moduleLicenseKey);
                    }
                    if ($this->moduleLicenses->status($id, $manifest['license_sku']) !== 'valid') {
                        throw new RuntimeException('This paid module requires an authorized Cajun Web Pro module license.');
                    }
                }
                if ($type === 'theme') {
                    foreach ($registry['themes'] as &$theme) {
                        $theme['enabled'] = false;
                    }
                    unset($theme);
                    $registry['selected_theme'] = $id;
                }
                $registry[$category][$id]['enabled'] = true;
            } else {
                if ($type === 'module') {
                    foreach ($registry['modules'] as $otherId => $other) {
                        if ($otherId === $id || !$other['enabled']) {
                            continue;
                        }
                        $otherManifest = $this->installedManifest('module', $otherId);
                        if (in_array($id, $otherManifest['dependencies'], true)) {
                            throw new RuntimeException('Deactivate dependent module ' . $otherId . ' first.');
                        }
                    }
                }
                $registry[$category][$id]['enabled'] = false;
                if ($type === 'theme' && $registry['selected_theme'] === $id) {
                    $registry['selected_theme'] = null;
                }
            }
            $this->writeRegistry($registry);
        });
    }

    /** @param list<string> $capabilities
     * @return list<array{id: string, type: string, name: string, version: string, enabled: bool, publisher: string, blocked: list<string>}>
     */
    public function listPackages(string $edition, array $capabilities): array
    {
        $registry = $this->readRegistry();
        $active = $this->runtimeActiveModules($registry, $edition, $capabilities);
        $result = [];
        foreach (['module' => 'modules', 'theme' => 'themes'] as $type => $category) {
            foreach ($registry[$category] as $id => $state) {
                try {
                    $manifest = $this->installedManifest($type, $id);
                    $reasons = $this->compatibility->blockedReasons($manifest, $edition, $capabilities, $active);
                    if ($type === 'module' && isset($manifest['license_sku'])
                        && $this->moduleLicenses->status($id, $manifest['license_sku']) !== 'valid') {
                        $reasons[] = 'Paid module license is not authorized.';
                    }
                    if (($manifest['version'] ?? null) !== $state['version']) {
                        $reasons[] = 'Registered version does not match the installed package.';
                    }
                    $result[] = [
                        'id' => $id, 'type' => $type,
                        'name' => $manifest['name'], 'version' => $state['version'],
                        'enabled' => $state['enabled'], 'publisher' => $state['publisher'], 'blocked' => $reasons,
                        'license_sku' => $manifest['license_sku'] ?? null,
                    ];
                } catch (Throwable) {
                    $result[] = [
                        'id' => $id, 'type' => $type, 'name' => $id, 'version' => $state['version'],
                        'enabled' => false, 'publisher' => $state['publisher'],
                        'blocked' => ['Package signature or file integrity verification failed.'], 'license_sku' => null,
                    ];
                }
            }
        }
        return $result;
    }

    /** Runtime must independently recheck signature, edition and capabilities before using any future extension. */
    public function isRunnable(string $type, string $id, string $edition, array $capabilities): bool
    {
        try {
            $registry = $this->readRegistry();
            $category = self::category($type);
            self::validateId($id);
            if (!($registry[$category][$id]['enabled'] ?? false)) {
                return false;
            }
            if ($type === 'theme' && ($registry['selected_theme'] ?? null) !== $id) {
                return false;
            }
            if ($type === 'module') {
                return $this->runtimeModuleReady($id, $registry, $edition, $capabilities);
            }
            $this->assertCompatible(
                $this->installedManifest($type, $id), $edition, $capabilities,
                $this->runtimeActiveModules($registry, $edition, $capabilities),
            );
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $manifest
     * @param list<string> $capabilities
     * @param list<string> $activeModules
     */
    private function assertCompatible(array $manifest, string $edition, array $capabilities, array $activeModules): void
    {
        $this->compatibility->assertInstallable($manifest, $edition, $capabilities, $activeModules);
    }

    /** @return array<string, mixed> */
    private function installedManifest(string $type, string $id): array
    {
        $category = self::category($type);
        self::validateId($id);
        $manifest = $this->verifier->verifyInstalled($this->directory . '/' . $category . '/' . $id);
        if ($manifest['type'] !== $type || $manifest['id'] !== $id) {
            throw new RuntimeException('Installed package metadata is inconsistent.');
        }
        return $manifest;
    }

    /** @return array{modules:array<string,array<string,mixed>>,themes:array<string,array<string,mixed>>,selected_theme:?string} */
    private function readRegistry(): array
    {
        $file = $this->directory . '/registry.json';
        if (!is_file($file)) {
            return ['modules' => [], 'themes' => [], 'selected_theme' => null];
        }
        if (is_link($file) || filesize($file) > 262144) {
            throw new RuntimeException('Invalid package registry.');
        }
        try {
            $value = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Package registry could not be read.');
        }
        if (!is_array($value) || !is_array($value['modules'] ?? null) || !is_array($value['themes'] ?? null)
            || !(is_string($value['selected_theme'] ?? null) || ($value['selected_theme'] ?? null) === null)) {
            throw new RuntimeException('Package registry is malformed.');
        }
        foreach (['modules', 'themes'] as $category) {
            foreach ($value[$category] as $id => $state) {
                self::validateId($id);
                if (!is_array($state) || !is_bool($state['enabled'] ?? null)
                    || !is_string($state['version'] ?? null) || !is_string($state['publisher'] ?? null)
                    || !is_string($state['installed_at'] ?? null)) {
                    throw new RuntimeException('Package registry contains invalid entries.');
                }
            }
        }
        return $value;
    }

    /** @param array<string,mixed> $registry */
    private function writeRegistry(array $registry): void
    {
        $data = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = $this->directory . '/.registry-' . bin2hex(random_bytes(8));
        self::writePrivate($temporary, $data . "\n");
        if (!rename($temporary, $this->directory . '/registry.json')) {
            @unlink($temporary);
            throw new RuntimeException('Could not publish package registry.');
        }
    }

    /** Return only enabled modules that are still signed, compatible, and whose dependency tree is valid. */
    private function runtimeActiveModules(array $registry, string $edition, array $capabilities): array
    {
        $active = [];
        foreach ($registry['modules'] as $id => $state) {
            if ($state['enabled'] && $this->runtimeModuleReady($id, $registry, $edition, $capabilities)) {
                $active[] = $id;
            }
        }
        return $active;
    }

    private function runtimeModuleReady(
        string $id, array $registry, string $edition, array $capabilities, array $trail = [],
    ): bool {
        if (!($registry['modules'][$id]['enabled'] ?? false) || in_array($id, $trail, true)) {
            return false;
        }
        try {
            $manifest = $this->installedManifest('module', $id);
            if ($manifest['version'] !== $registry['modules'][$id]['version']) return false;
            if (isset($manifest['license_sku']) && $this->moduleLicenses->status($id,$manifest['license_sku']) !== 'valid') return false;
            $trail[] = $id;
            foreach ($manifest['dependencies'] as $dependency) {
                if (!$this->runtimeModuleReady($dependency, $registry, $edition, $capabilities, $trail)) {
                    return false;
                }
            }
            return $this->compatibility->blockedReasons(
                $manifest, $edition, $capabilities, $manifest['dependencies'],
            ) === [];
        } catch (Throwable) {
            return false;
        }
    }

    private function locked(callable $callback): mixed
    {
        if (!is_dir($this->root . '/storage') || is_link($this->root . '/storage')) {
            throw new RuntimeException('Private storage unavailable.');
        }
        self::mkdirPrivate($this->directory);
        $lock = $this->directory . '/.lock';
        if (is_link($lock)) {
            throw new RuntimeException('Package lock is unsafe.');
        }
        $handle = fopen($lock, 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Could not lock package storage.');
        }
        try {
            chmod($lock, 0600);
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Package lock unavailable.');
            }
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function category(string $type): string
    {
        return match ($type) {
            'module' => 'modules',
            'theme' => 'themes',
            default => throw new RuntimeException('Invalid package type.'),
        };
    }

    private static function validateId(string $id): void
    {
        if (preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $id) !== 1) {
            throw new RuntimeException('Invalid package identifier.');
        }
    }

    private static function mkdirPrivate(string $path): void
    {
        if (is_link($path)) {
            throw new RuntimeException('Unsafe private directory.');
        }
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Could not create private package directory.');
        }
    }

    private static function writePrivate(string $path, string $data): void
    {
        if (is_link($path) || file_exists($path)) {
            throw new RuntimeException('Package file already exists.');
        }
        if (file_put_contents($path, $data, LOCK_EX) !== strlen($data)) {
            throw new RuntimeException('Could not write package file.');
        }
        chmod($path, 0600);
    }

    private static function removeDirectory(string $directory): void
    {
        if (is_link($directory)) {
            throw new RuntimeException('Refusing to remove symlink directory.');
        }
        if (!is_dir($directory)) {
            return;
        }
        $files = new \FilesystemIterator($directory);
        foreach ($files as $file) {
            if ($file->isLink()) {
                unlink($file->getPathname());
            } elseif ($file->isDir()) {
                self::removeDirectory($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($directory);
    }
}
