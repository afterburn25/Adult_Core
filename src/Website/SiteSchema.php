<?php

declare(strict_types=1);

namespace AdultCore\Website;

use PDO;

/** Additive, idempotent website-page schema. Run only after admin authorization. */
final class SiteSchema
{
    public const VERSION = 'm6_site_pages_v1';

    public static function migrate(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ac_pages (
          id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
          slug VARCHAR(80) NOT NULL UNIQUE,
          title VARCHAR(190) NOT NULL,
          body TEXT NOT NULL,
          status VARCHAR(16) NOT NULL DEFAULT 'draft',
          updated_at DATETIME NOT NULL,
          created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("INSERT IGNORE INTO ac_pages (slug,title,body,status,updated_at,created_at)
           VALUES ('home','Welcome','Your membership website is being prepared.','draft',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $stmt = $pdo->prepare('INSERT IGNORE INTO ac_schema_migrations (version, applied_at) VALUES (?, UTC_TIMESTAMP())');
        $stmt->execute([self::VERSION]);
    }
}
