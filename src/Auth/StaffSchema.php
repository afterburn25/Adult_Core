<?php

declare(strict_types=1);

namespace AdultCore\Auth;

use PDO;

/** Additive staff action history, never stores passwords, tokens or staff notes. */
final class StaffSchema
{
    public const VERSION = 'm11_staff_audit_v1';

    public static function migrate(PDO $pdo): void
    {
        // Called only after MemberSchema, which adds ac_users.status.
        $q=$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ac_users' AND COLUMN_NAME='status'");
        if ((int)$q->fetchColumn()!==1) {
            throw new \RuntimeException('Apply the member account migration first.');
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS ac_staff_actions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            actor_id BIGINT UNSIGNED NOT NULL,
            staff_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(24) NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX (staff_id,id),
            FOREIGN KEY (actor_id) REFERENCES ac_users(id),
            FOREIGN KEY (staff_id) REFERENCES ac_users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $q=$pdo->prepare('INSERT IGNORE INTO ac_schema_migrations(version,applied_at) VALUES (?,UTC_TIMESTAMP())');
        $q->execute([self::VERSION]);
    }
}
