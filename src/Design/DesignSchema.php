<?php

declare(strict_types=1);

namespace AdultCore\Design;

use PDO;

/** Additive and repeatable setup; existing page and package data are untouched. */
final class DesignSchema
{
    public const VERSION = 'm11_visual_design_v1';

    public static function migrate(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ac_design_assets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(90) NOT NULL UNIQUE,
            mime VARCHAR(24) NOT NULL,
            byte_size INT UNSIGNED NOT NULL,
            public_ok TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ac_design_settings (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            theme_key VARCHAR(32) NOT NULL DEFAULT 'noir-gold',
            accent_color VARCHAR(7) NOT NULL DEFAULT '#d9ae75',
            hero_asset_id BIGINT UNSIGNED NULL,
            image_side VARCHAR(12) NOT NULL DEFAULT 'right',
            image_size VARCHAR(12) NOT NULL DEFAULT 'large',
            updated_at DATETIME NOT NULL,
            CONSTRAINT ac_design_asset_fk FOREIGN KEY (hero_asset_id) REFERENCES ac_design_assets(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("INSERT IGNORE INTO ac_design_settings (id,theme_key,accent_color,hero_asset_id,image_side,image_size,updated_at)
            VALUES (1,'noir-gold','#d9ae75',NULL,'right','large',UTC_TIMESTAMP())");
        $q = $pdo->prepare('INSERT IGNORE INTO ac_schema_migrations (version, applied_at) VALUES (?, UTC_TIMESTAMP())');
        $q->execute([self::VERSION]);
    }
}
