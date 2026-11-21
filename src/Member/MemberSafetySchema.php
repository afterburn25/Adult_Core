<?php

declare(strict_types=1);

namespace AdultCore\Member;

use PDO;
use RuntimeException;

/**
 * Additive site-local member status audit; no membership entitlement changes.
 * All historical security decisions are retained for operator review.
 */
final class MemberSafetySchema
{
    public const VERSION='m21_local_member_safety_v1';

    public static function installed(PDO $pdo): bool
    {
        $q=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
        $q->execute([self::VERSION]);
        return (int)$q->fetchColumn()===1;
    }

    public static function migrate(PDO $pdo): void
    {
        foreach ([MemberSchema::VERSION,MemberEmailSchema::VERSION,
            PasswordRecoverySchema::VERSION] as $required) {
            $q=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
            $q->execute([$required]);
            if ((int)$q->fetchColumn()!==1) {
                throw new RuntimeException('Apply member security prerequisites before member safety audit.');
            }
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS ac_member_status_actions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            member_id BIGINT UNSIGNED NOT NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            old_status VARCHAR(16) NOT NULL,
            new_status VARCHAR(16) NOT NULL,
            reason VARCHAR(500) NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX ix_member_status_history (member_id,id),
            FOREIGN KEY (member_id) REFERENCES ac_users(id),
            FOREIGN KEY (actor_id) REFERENCES ac_users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $q=$pdo->prepare('INSERT IGNORE INTO ac_schema_migrations(version,applied_at) VALUES (?,UTC_TIMESTAMP())');
        $q->execute([self::VERSION]);
    }
}
