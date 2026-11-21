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
        // Bound review queue scans as creator counts grow locally.
        // Additive DDL is safe to repeat on either supported MySQL/MariaDB.
        $index=$pdo->query("SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ac_creator_drafts'
                AND INDEX_NAME='ix_creator_review_queue'");
        if ((int)$index->fetchColumn()===0) {
            $pdo->exec('ALTER TABLE ac_creator_drafts
                ADD INDEX ix_creator_review_queue(status,updated_at,id)');
        }
        // Preserve the CURRENT, provenance-backed feedback from an m14
        // installation during upgrade. The last numeric draft event must
        // match the draft's current review decision. Earlier overwritten
        // notes cannot be recovered and must never be invented.
        //
        // The reviewed submission version is exactly the current revision
        // minus one while the plan remains in 'changes' or 'rejected'.
        // INSERT IGNORE + unique (draft_id,submitted_revision) makes a
        // partially applied migration safe to retry without duplicating
        // private feedback.
        $pdo->exec("INSERT IGNORE INTO ac_creator_feedback_history
            (draft_id,reviewer_id,submitted_revision,decision,feedback,reviewed_at)
            SELECT d.id,e.actor_id,d.revision-1,d.status,d.review_note,e.created_at
            FROM ac_creator_drafts d
            JOIN ac_creator_draft_events e ON e.draft_id=d.id
            WHERE d.status IN ('changes','rejected')
                AND d.review_note <> '' AND d.revision > 1
                AND e.action=d.status
                AND e.id=(SELECT MAX(e2.id) FROM ac_creator_draft_events e2
                          WHERE e2.draft_id=d.id)");
        $q=$pdo->prepare('INSERT IGNORE INTO ac_schema_migrations(version,applied_at) VALUES (?,UTC_TIMESTAMP())');
        $q->execute([self::VERSION]);
    }
}
