<?php

declare(strict_types=1);

namespace AdultCore\Auth;

use PDO;

/** Idempotent schema adoption: fresh installs also create this table. */
final class AdminMfaSchema
{
    public const VERSION = 'mfa_admin_totp_v1';

    public static function migrate(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS ac_admin_mfa (
            user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            secret_box TEXT NOT NULL,
            recovery_hashes TEXT NOT NULL,
            last_counter BIGINT NOT NULL DEFAULT -1,
            enabled_at DATETIME NOT NULL,
            CONSTRAINT fk_ac_admin_mfa_user FOREIGN KEY (user_id) REFERENCES ac_users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $pdo->prepare('INSERT IGNORE INTO ac_schema_migrations (version,applied_at) VALUES (?,UTC_TIMESTAMP())')
            ->execute([self::VERSION]);
    }
}
