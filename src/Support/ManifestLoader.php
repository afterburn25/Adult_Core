<?php

declare(strict_types=1);

namespace AdultCore\Support;

use RuntimeException;

final class ManifestLoader
{
    /** @return array<string, mixed> */
    public static function load(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Manifest missing or unreadable: ' . $path);
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Could not read manifest: ' . $path);
        }
        $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data)) {
            throw new RuntimeException('Manifest must be a JSON object: ' . $path);
        }
        return $data;
    }

    /** @return list<string> */
    public static function stringList(array $manifest, string $key): array
    {
        $value = $manifest[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException("Manifest field '{$key}' must be an array.");
        }
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new RuntimeException("Manifest field '{$key}' must contain nonempty strings.");
            }
        }
        return array_values(array_unique($value));
    }
}
