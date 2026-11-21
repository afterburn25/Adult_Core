<?php

declare(strict_types=1);

namespace AdultCore\Creator;

use PDO;
use RuntimeException;

/**
 * Additive LOCAL text-planning review only. No media approval, public content,
 * verified identity, performer consent or payment entitlement is touched.
 */
final class CreatorReviewSchema
{
    public const VERSION = 'm14_creator_text_review_v1';

    public static function migrate(PDO $pdo): void
    {
        $installed=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
        $installed->execute([CreatorSchema::VERSION]);
        if ((int)$installed->fetchColumn()!==1) {
            throw new RuntimeException('Apply the local creator migration before text review.');
        }
        $column=$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ac_creator_drafts'
                AND COLUMN_NAME='review_note'");
        if ((int)$column->fetchColumn()===0) {
            $pdo->exec("ALTER TABLE ac_creator_drafts
                ADD COLUMN review_note VARCHAR(500) NOT NULL DEFAULT ''");
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS ac_creator_draft_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            draft_id BIGINT UNSIGNED NOT NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(24) NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX (draft_id,id),
            FOREIGN KEY (draft_id) REFERENCES ac_creator_drafts(id),
            FOREIGN KEY (actor_id) REFERENCES ac_users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $q=$pdo->prepare('INSERT IGNORE INTO ac_schema_migrations(version,applied_at) VALUES (?,UTC_TIMESTAMP())');
        $q->execute([self::VERSION]);
    }
}
