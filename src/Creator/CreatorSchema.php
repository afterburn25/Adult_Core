<?php

declare(strict_types=1);

namespace AdultCore\Creator;

use AdultCore\Member\MemberEmailSchema;
use AdultCore\Member\MemberSchema;
use PDO;
use RuntimeException;

/**
 * Local-only creator permissions for one independently hosted website.
 * This schema cannot create a public video, media upload, paid subscription
 * or verified identity/consent record.
 */
final class CreatorSchema
{
    public const VERSION = 'm13_local_creator_workspace_v1';

    public static function migrate(PDO $pdo): void
    {
        $q=$pdo->prepare('SELECT version FROM ac_schema_migrations WHERE version IN (?,?)');
        $q->execute([MemberSchema::VERSION, MemberEmailSchema::VERSION]);
        if (count($q->fetchAll(PDO::FETCH_COLUMN))!==2) {
            throw new RuntimeException('Apply member and member-email migrations before local creators.');
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS ac_creator_profiles (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            member_id BIGINT UNSIGNED NOT NULL UNIQUE,
            display_name VARCHAR(100) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'enabled',
            revision INT UNSIGNED NOT NULL DEFAULT 1,
            created_by BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX (status,id),
            FOREIGN KEY (member_id) REFERENCES ac_users(id),
            FOREIGN KEY (created_by) REFERENCES ac_users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ac_creator_drafts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            creator_id BIGINT UNSIGNED NOT NULL,
            title VARCHAR(190) NOT NULL,
            summary TEXT NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'private',
            revision INT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX (creator_id,id),
            FOREIGN KEY (creator_id) REFERENCES ac_creator_profiles(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ac_creator_actions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            actor_id BIGINT UNSIGNED NOT NULL,
            creator_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(30) NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX (creator_id,id),
            FOREIGN KEY (actor_id) REFERENCES ac_users(id),
            FOREIGN KEY (creator_id) REFERENCES ac_creator_profiles(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $q=$pdo->prepare('INSERT IGNORE INTO ac_schema_migrations(version,applied_at) VALUES (?,UTC_TIMESTAMP())');
        $q->execute([self::VERSION]);
    }
}
