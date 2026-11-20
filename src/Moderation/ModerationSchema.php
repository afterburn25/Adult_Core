<?php

declare(strict_types=1);

namespace AdultCore\Moderation;

use PDO;

/** Additive schema: a review decision does not confer publication rights. */
final class ModerationSchema
{
    public const VERSION = 'm8_moderation_review_v1';

    public static function migrate(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ac_moderation_actions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            content_id BIGINT UNSIGNED NOT NULL,
            report_id BIGINT UNSIGNED NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(32) NOT NULL,
            note VARCHAR(500) NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX (content_id,created_at),
            INDEX (report_id),
            FOREIGN KEY (content_id) REFERENCES ac_content(id),
            FOREIGN KEY (report_id) REFERENCES ac_content_reports(id),
            FOREIGN KEY (actor_id) REFERENCES ac_users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $stmt=$pdo->prepare('INSERT IGNORE INTO ac_schema_migrations (version,applied_at) VALUES (?,UTC_TIMESTAMP())');
        $stmt->execute([self::VERSION]);
    }
}
