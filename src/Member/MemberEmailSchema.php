<?php

declare(strict_types=1);

namespace AdultCore\Member;

use PDO;

/** Additive migration: email ownership is NOT identity or age verification. */
final class MemberEmailSchema
{
    public const VERSION = 'm9_member_email_v1';

    public static function migrate(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ac_member_email_confirmations (
            user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            token_sha256 CHAR(64) NULL UNIQUE,
            issued_at DATETIME NULL,
            expires_at DATETIME NULL,
            confirmed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            FOREIGN KEY (user_id) REFERENCES ac_users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $q=$pdo->prepare('INSERT IGNORE INTO ac_schema_migrations (version,applied_at) VALUES (?,UTC_TIMESTAMP())');
        $q->execute([self::VERSION]);
    }
}
