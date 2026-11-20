<?php

declare(strict_types=1);

namespace AdultCore\Member;

use PDO;

/** Additive password-recovery state and per-member session generation. */
final class PasswordRecoverySchema
{
    public const VERSION = 'm10_member_recovery_v1';

    public static function migrate(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ac_member_password_resets (
            user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            token_sha256 CHAR(64) NULL UNIQUE,
            issued_at DATETIME NULL,
            expires_at DATETIME NULL,
            session_generation BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (user_id) REFERENCES ac_users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $q = $pdo->prepare('INSERT IGNORE INTO ac_schema_migrations (version,applied_at) VALUES (?,UTC_TIMESTAMP())');
        $q->execute([self::VERSION]);
    }
}
