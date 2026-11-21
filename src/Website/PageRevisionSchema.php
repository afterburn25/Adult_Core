<?php

declare(strict_types=1);

namespace AdultCore\Website;

use PDO;
use RuntimeException;

/**
 * Repeatable, additive page revision migration. A monotonic integer is used
 * instead of DATETIME seconds so two saves in the same second cannot collide.
 * The application must never treat a missing revision as permission to write.
 */
final class PageRevisionSchema
{
    public const VERSION='m16_page_revisions_v1';

    public static function migrate(PDO $pdo): void
    {
        $q=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
        $q->execute([SiteSchema::VERSION]);
        if ((int)$q->fetchColumn()!==1) {
            throw new RuntimeException('Apply the site page schema before page revisions.');
        }
        $check=$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ac_pages'
                AND COLUMN_NAME='revision'");
        if ((int)$check->fetchColumn()===0) {
            $pdo->exec('ALTER TABLE ac_pages ADD COLUMN revision INT UNSIGNED NOT NULL DEFAULT 1');
        }
        $q=$pdo->prepare('INSERT IGNORE INTO ac_schema_migrations(version,applied_at) VALUES (?,UTC_TIMESTAMP())');
        $q->execute([self::VERSION]);
    }

    public static function installed(PDO $pdo): bool
    {
        $q=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
        $q->execute([self::VERSION]);
        return (int)$q->fetchColumn()===1;
    }
}
