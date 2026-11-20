<?php

declare(strict_types=1);

namespace AdultCore\Verification;

use PDO;

/** Additive, repeat-safe migration. No approval or publication is granted. */
final class VerificationSchema
{
    public const VERSION = 'm8_verification_receipts_v1';

    public static function migrate(PDO $pdo): void
    {
        $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ac_content' AND COLUMN_NAME='expected_performers'");
        $q->execute();
        if ((int) $q->fetchColumn() === 0) {
            // Unknown performer counts MUST remain 0: the authorization guard denies these items.
            $pdo->exec('ALTER TABLE ac_content ADD COLUMN expected_performers SMALLINT UNSIGNED NOT NULL DEFAULT 0');
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS ac_verification_receipts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            provider VARCHAR(40) NOT NULL,
            event_id VARCHAR(120) NOT NULL,
            claim_type VARCHAR(32) NOT NULL,
            subject_id BIGINT UNSIGNED NOT NULL,
            decision VARCHAR(16) NOT NULL,
            reference_sha256 CHAR(64) NOT NULL,
            occurred_at DATETIME NOT NULL,
            received_at DATETIME NOT NULL,
            UNIQUE KEY provider_event (provider,event_id),
            INDEX (claim_type,subject_id,received_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $s = $pdo->prepare('INSERT IGNORE INTO ac_schema_migrations (version,applied_at) VALUES (?,UTC_TIMESTAMP())');
        $s->execute([self::VERSION]);
    }
}
