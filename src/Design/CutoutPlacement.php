<?php

declare(strict_types=1);

namespace AdultCore\Design;

use PDO;
use RuntimeException;

/** Stable, percentage-based cutout coordinates shared by the live editor and public renderer. */
final class CutoutPlacement
{
    public const VERSION = 'm12_cutout_placement_v1';
    public const DEFAULT_X = 50;
    public const DEFAULT_Y = 50;
    public const DEFAULT_WIDTH = 80;

    /** @return array{cutout_x:int,cutout_y:int,cutout_width:int} */
    public static function defaults(): array
    {
        return ['cutout_x' => self::DEFAULT_X, 'cutout_y' => self::DEFAULT_Y, 'cutout_width' => self::DEFAULT_WIDTH];
    }

    /** Strict values prevent CSS injection, invalid percentages, and accidental off-screen artwork. */
    public static function validate(mixed $x, mixed $y, mixed $width): array
    {
        $result = [];
        foreach (['cutout_x' => $x, 'cutout_y' => $y, 'cutout_width' => $width] as $name => $input) {
            if (!is_string($input) || preg_match('/^(0|[1-9][0-9]{0,2})$/D', $input) !== 1) {
                throw new RuntimeException('Invalid cutout position or size.');
            }
            $value = (int)$input;
            if ($value < ($name === 'cutout_width' ? 20 : 0) || $value > 100) {
                throw new RuntimeException('Cutout position or size is outside the allowed range.');
            }
            $result[$name] = $value;
        }
        return $result;
    }

    /**
     * Additive, repeatable MySQL migration. Never rewrite legacy side/size or publisher state.
     * MySQL DDL autocommits; column checks make interrupted migration restart-safe.
     */
    public static function migrate(PDO $pdo): void
    {
        $columns = [
            'cutout_x' => self::DEFAULT_X,
            'cutout_y' => self::DEFAULT_Y,
            'cutout_width' => self::DEFAULT_WIDTH,
        ];
        $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        foreach ($columns as $column => $default) {
            $check->execute(['ac_design_settings', $column]);
            if ((int)$check->fetchColumn() === 0) {
                // Only fixed, developer-defined SQL fragments are interpolated.
                $pdo->exec("ALTER TABLE ac_design_settings ADD COLUMN `{$column}` TINYINT UNSIGNED NOT NULL DEFAULT {$default}");
            }
        }
        if (!self::installed($pdo)) {
            // Convert existing coarse size presets only once. Fresh/empty hero slots retain 80%.
            $pdo->exec("UPDATE ac_design_settings SET cutout_width=CASE image_size
                WHEN 'small' THEN 55 WHEN 'medium' THEN 77 ELSE 100 END
                WHERE hero_asset_id IS NOT NULL");
        }
        $marker = $pdo->prepare('INSERT IGNORE INTO ac_schema_migrations (version,applied_at) VALUES (?,UTC_TIMESTAMP())');
        $marker->execute([self::VERSION]);
    }

    public static function installed(PDO $pdo): bool
    {
        $query = $pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
        $query->execute([self::VERSION]);
        return (int)$query->fetchColumn() > 0;
    }
}
