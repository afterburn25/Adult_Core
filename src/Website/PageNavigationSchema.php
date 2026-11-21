<?php

declare(strict_types=1);

namespace AdultCore\Website;

use PDO;
use RuntimeException;

/**
 * Local website navigation metadata; publication still depends solely on
 * PageRepository::published(). Hidden menu links are NOT an access control.
 */
final class PageNavigationSchema
{
    public const VERSION='m17_page_navigation_v1';

    public static function migrate(PDO $pdo): void
    {
        if (!PageRevisionSchema::installed($pdo)) {
            throw new RuntimeException('Apply page revisions before navigation settings.');
        }
        $check=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ac_pages'
                AND COLUMN_NAME=?");
        foreach ([
            'show_in_menu'=>'TINYINT(1) NOT NULL DEFAULT 1',
            'nav_position'=>'SMALLINT UNSIGNED NOT NULL DEFAULT 100',
        ] as $name=>$definition) {
            $check->execute([$name]);
            if ((int)$check->fetchColumn()===0) {
                $pdo->exec('ALTER TABLE ac_pages ADD COLUMN '.$name.' '.$definition);
            }
        }
        $index=$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ac_pages'
                AND INDEX_NAME='ix_public_page_navigation'");
        if ((int)$index->fetchColumn()===0) {
            $pdo->exec('ALTER TABLE ac_pages
                ADD INDEX ix_public_page_navigation(status,show_in_menu,nav_position,id)');
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
