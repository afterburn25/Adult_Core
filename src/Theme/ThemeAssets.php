<?php

declare(strict_types=1);

namespace AdultCore\Theme;

use AdultCore\Package\PackageStore;
use RuntimeException;

/** Serves only signed CSS from the selected trusted theme, never executable template code. */
final class ThemeAssets
{
    public function __construct(private readonly string $root) {}

    /** @param list<string> $capabilities */
    public function css(string $edition, array $capabilities): ?string
    {
        $registryPath = $this->root . '/storage/packages/registry.json';
        if (!is_file($registryPath) || is_link($registryPath)) {
            return null;
        }
        $registry = json_decode((string) file_get_contents($registryPath), true, 12);
        if (!is_array($registry)) {
            return null;
        }
        $id = $registry['selected_theme'] ?? null;
        if (!is_string($id) || preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $id) !== 1
            || !($registry['themes'][$id]['enabled'] ?? false)) {
            return null;
        }
        if (!(new PackageStore($this->root))->isRunnable('theme', $id, $edition, $capabilities)) {
            return null;
        }
        $cssPath = $this->root . '/storage/packages/themes/' . $id . '/payload/assets/theme.css';
        if (!is_file($cssPath) || is_link($cssPath) || filesize($cssPath) > 262144) {
            return null;
        }
        // isRunnable performs a fresh signature and all-file integrity check.
        return file_get_contents($cssPath) ?: null;
    }
}
