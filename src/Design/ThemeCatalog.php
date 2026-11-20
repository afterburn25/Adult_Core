<?php

declare(strict_types=1);

namespace AdultCore\Design;

use RuntimeException;

/** Fixed trusted layout templates. Uploaded theme packages remain restricted to signed data/CSS. */
final class ThemeCatalog
{
    public const THEMES = [
        'noir-gold' => ['name' => 'Noir Atelier', 'description' => 'Editorial luxury · split hero', 'accent' => '#d9ae75', 'swatches' => ['#0e0c0d', '#2d2421', '#d9ae75']],
        'velvet-plum' => ['name' => 'Velvet Club', 'description' => 'Dramatic glow · centered editorial', 'accent' => '#f19ad0', 'swatches' => ['#140c21', '#52235a', '#f19ad0']],
        'midnight-teal' => ['name' => 'Midnight Studio', 'description' => 'Modern cinematic · reversed hero', 'accent' => '#6ce0d3', 'swatches' => ['#061827', '#174a55', '#6ce0d3']],
        'rose-ivory' => ['name' => 'Rose Editorial', 'description' => 'Soft sophistication · magazine layout', 'accent' => '#b76b80', 'swatches' => ['#f5ebdf', '#e8cbd1', '#b76b80']],
    ];

    public static function validate(string $theme, string $accent, string $side, string $size): void
    {
        if (!array_key_exists($theme, self::THEMES)
            || preg_match('/^#[0-9a-fA-F]{6}$/D', $accent) !== 1
            || !in_array($side, ['left', 'right'], true)
            || !in_array($size, ['small', 'medium', 'large'], true)) {
            throw new RuntimeException('Invalid theme, palette color, or cutout placement.');
        }
    }

    /** @return array{theme_key:string,accent_color:string,hero_asset_id:null|int,image_side:string,image_size:string} */
    public static function defaults(): array
    {
        return ['theme_key' => 'noir-gold', 'accent_color' => '#d9ae75', 'hero_asset_id' => null, 'image_side' => 'right', 'image_size' => 'large'];
    }

    public static function defaultAccent(string $theme): string
    {
        return self::THEMES[$theme]['accent'] ?? self::THEMES['noir-gold']['accent'];
    }
}
