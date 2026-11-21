<?php

declare(strict_types=1);

namespace AdultCore\Website;

use PDO;
use RuntimeException;

/**
 * Local page snapshots; prior overwritten versions cannot be invented during
 * migration. No public route, member entitlement or adult-media authorization.
 */
final class PageHistorySchema
{
    public const VERSION='m19_private_page_history_v1';

    public static function installed(PDO $pdo): bool
    {
        $q=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
        $q->execute([self::VERSION]);
        return (int)$q->fetchColumn()===1;
    }

    public static function migrate(PDO $pdo): void
    {
        if (!PageNavigationSchema::installed($pdo)) {
            throw new RuntimeException('Apply page navigation before private page history.');
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS ac_page_history (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            page_id BIGINT UNSIGNED NOT NULL,
            revision INT UNSIGNED NOT NULL,
            slug VARCHAR(80) NOT NULL,
            title VARCHAR(190) NOT NULL,
            body TEXT NOT NULL,
            status VARCHAR(16) NOT NULL,
            show_in_menu TINYINT(1) NOT NULL,
            nav_position SMALLINT UNSIGNED NOT NULL,
            saved_at DATETIME NOT NULL,
            UNIQUE KEY uq_page_revision (page_id,revision),
            CONSTRAINT ac_page_history_page_fk FOREIGN KEY (page_id)
                REFERENCES ac_pages(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Upgrade baseline: only the CURRENT row can be proven. INSERT IGNORE
        // makes interrupted migrations repeatable and preserves any prior
        // snapshots. Older pre-upgrade edits cannot be recovered.
        $pdo->exec("INSERT IGNORE INTO ac_page_history
            (page_id,revision,slug,title,body,status,show_in_menu,nav_position,saved_at)
            SELECT id,revision,slug,title,body,status,show_in_menu,nav_position,updated_at
            FROM ac_pages");
        $q=$pdo->prepare('INSERT IGNORE INTO ac_schema_migrations(version,applied_at) VALUES (?,UTC_TIMESTAMP())');
        $q->execute([self::VERSION]);
    }
}
