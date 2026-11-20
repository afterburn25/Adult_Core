<?php

declare(strict_types=1);

namespace AdultCore\Member;

use PDO;

/** Safe-to-repeat additive account migration; pending accounts cannot unlock content. */
final class MemberSchema
{
    public const VERSION = 'm7_member_accounts_v1';
    public static function migrate(PDO $pdo): void
    {
        $q = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ac_users' AND COLUMN_NAME='status'");
        if ((int) $q->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE ac_users ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'active'");
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS ac_age_verifications (
          user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
          provider VARCHAR(100) NOT NULL,
          verification_ref_hash CHAR(64) NOT NULL,
          verified_at DATETIME NOT NULL,
          expires_at DATETIME NULL,
          FOREIGN KEY (user_id) REFERENCES ac_users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("INSERT IGNORE INTO ac_schema_migrations (version, applied_at) VALUES ('" . self::VERSION . "', UTC_TIMESTAMP())");
    }
}
