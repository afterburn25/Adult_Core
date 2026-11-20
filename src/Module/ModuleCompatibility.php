<?php

declare(strict_types=1);

namespace AdultCore\Module;

use AdultCore\Support\ManifestLoader;
use RuntimeException;

final class ModuleCompatibility
{
    public const CORE_VERSION = '0.1.0';


    /**
     * Hard guard to be called before any module package is written, activated, or run.
     * Actual package signature and filesystem installation are a later milestone.
     *
     * @param array<string, mixed> $module
     * @param list<string> $capabilities
     * @param list<string> $installedModules
     */
    public function assertInstallable(array $module, ?string $edition, array $capabilities, array $installedModules = []): void
    {
        $reasons = $this->blockedReasons($module, $edition, $capabilities, $installedModules);
        if ($reasons !== []) {
            throw new RuntimeException('Module installation refused: ' . implode(' ', $reasons));
        }
    }

    /**
     * @param array<string, mixed> $module
     * @param list<string> $capabilities
     * @param list<string> $installedModules
     * @return list<string> Human-readable reasons the module cannot be activated.
     */
    public function blockedReasons(array $module, ?string $edition, array $capabilities, array $installedModules = []): array
    {
        $id = $module['id'] ?? null;
        if (!is_string($id) || preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $id) !== 1) {
            throw new RuntimeException('Invalid module ID.');
        }
        foreach (['editions', 'required_capabilities', 'dependencies'] as $key) {
            ManifestLoader::stringList($module, $key);
        }
        $minimum = $module['minimum_core_version'] ?? null;
        if (!is_string($minimum) || !preg_match('/^\d+\.\d+\.\d+$/D', $minimum)) {
            throw new RuntimeException('Invalid minimum_core_version in module: ' . $id);
        }

        $reasons = [];
        if ($edition === null) {
            $reasons[] = 'Core server requirements are not met.';
        } elseif (!in_array($edition, $module['editions'], true)) {
            $reasons[] = 'Requires edition: ' . implode(' or ', $module['editions']) . '.';
        }
        if (version_compare(self::CORE_VERSION, $minimum, '<')) {
            $reasons[] = 'Core version ' . $minimum . ' or newer is required.';
        }
        foreach (array_diff($module['required_capabilities'], $capabilities) as $missing) {
            $reasons[] = 'Missing server capability: ' . $missing . '.';
        }
        foreach (array_diff($module['dependencies'], $installedModules) as $missing) {
            $reasons[] = 'Missing dependency module: ' . $missing . '.';
        }
        return $reasons;
    }
}
