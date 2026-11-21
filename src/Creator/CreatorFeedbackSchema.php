<?php

declare(strict_types=1);

namespace AdultCore\Creator;

use PDO;
use RuntimeException;

/**
 * Local-only private review feedback history. Review text belongs to the
 * customer's own installation and never unlocks publication or media.
 */
final class CreatorFeedbackSchema
{
    public const VERSION='m15_creator_feedback_history_v1';

    public static function migrate(PDO $pdo): void
    {
        $q=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
        $q->execute([CreatorReviewSchema::VERSION]);
        if ((int)$q->fetchColumn()!==1) {
            throw new RuntimeException('Apply private creator text review before feedback history.');
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS ac_creator_feedback_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            draft_id BIGINT UNSIGNED NOT NULL,
            reviewer_id BIGINT UNSIGNED NOT NULL,
            submitted_revision INT UNSIGNED NOT NULL,
            decision VARCHAR(16) NOT NULL,
            feedback VARCHAR(500) NOT NULL,
            reviewed_at DATETIME NOT NULL,
            UNIQUE KEY uq_review_revision (draft_id,submitted_revision),
            INDEX (reviewer_id,id),
            FOREIGN KEY (draft_id) REFERENCES ac_creator_drafts(id),
            FOREIGN KEY (reviewer_id) REFERENCES ac_users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $q=$pdo->prepare('INSERT IGNORE INTO ac_schema_migrations(version,applied_at) VALUES (?,UTC_TIMESTAMP())');
        $q->execute([self::VERSION]);
    }
}
