<?php

declare(strict_types=1);

namespace AdultCore\Member;

use PDO;
use RuntimeException;

/**
 * Private local member display names only. No public member directory,
 * creator approval, age/consent, payment or media entitlement columns.
 */
final class MemberProfileSchema
{
    public const VERSION='m22_private_member_profiles_v1';

    public static function installed(PDO $pdo): bool
    {
        $q=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
        $q->execute([self::VERSION]);
        return (int)$q->fetchColumn()===1;
    }

    public static function migrate(PDO $pdo): void
    {
        foreach ([MemberSchema::VERSION,MemberSafetySchema::VERSION] as $required) {
            $q=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
            $q->execute([$required]);
            if ((int)$q->fetchColumn()!==1) {
                throw new RuntimeException('Apply member and safety schemas before private profiles.');
            }
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS ac_member_profiles (
            user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            display_name VARCHAR(100) NOT NULL,
            revision INT UNSIGNED NOT NULL DEFAULT 1,
            updated_at DATETIME NOT NULL,
            FOREIGN KEY (user_id) REFERENCES ac_users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $q=$pdo->prepare('INSERT IGNORE INTO ac_schema_migrations(version,applied_at)
            VALUES (?,UTC_TIMESTAMP())');
        $q->execute([self::VERSION]);
    }
}
