<?php

declare(strict_types=1);

namespace AdultCore\Edition;

use AdultCore\Support\ManifestLoader;
use RuntimeException;

final class EditionSelector
{
    /** @var array<string, array<string, mixed>> */
    private array $editions;

    public function __construct(string $manifestDirectory)
    {
        $this->editions = [];
        foreach (['lite', 'pro'] as $edition) {
            $manifest = ManifestLoader::load(rtrim($manifestDirectory, '/') . '/' . $edition . '.json');
            if (($manifest['id'] ?? null) !== $edition) {
                throw new RuntimeException('Invalid edition manifest: ' . $edition);
            }
            ManifestLoader::stringList($manifest, 'required_capabilities');
            $this->editions[$edition] = $manifest;
        }
    }

    /** @return list<string> */
    public function missing(string $edition, array $capabilities): array
    {
        if (!isset($this->editions[$edition])) {
            throw new RuntimeException('Unknown edition: ' . $edition);
        }
        $needed = ManifestLoader::stringList($this->editions[$edition], 'required_capabilities');
        return array_values(array_diff($needed, $capabilities));
    }

    /** @return array{edition: string|null, missing_lite: list<string>, missing_pro: list<string>} */
    public function select(array $capabilities): array
    {
        $lite = $this->missing('lite', $capabilities);
        $pro = $this->missing('pro', $capabilities);
        return [
            'edition' => $pro === [] ? 'pro' : ($lite === [] ? 'lite' : null),
            'missing_lite' => $lite,
            'missing_pro' => $pro,
        ];
    }

    /** @return array<string, mixed> */
    public function manifest(string $edition): array
    {
        if (!isset($this->editions[$edition])) {
            throw new RuntimeException('Unknown edition: ' . $edition);
        }
        return $this->editions[$edition];
    }
}
