<?php

declare(strict_types=1);

namespace AdultCore\Creator;

use PDO;
use RuntimeException;

/** Private creator-interest intake; never marks a member verified or publishes media. */
final class CreatorSchema
{
    public const VERSION = 'm12_creator_intake_v1';

    public static function migrate(PDO $pdo): void
    {
        $q=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
        $q->execute([\AdultCore\Member\MemberEmailSchema::VERSION]);
        if ((int)$q->fetchColumn()!==1) throw new RuntimeException('Apply member email setup before creator intake.');
        $pdo->exec("CREATE TABLE IF NOT EXISTS ac_creator_applications (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            member_id BIGINT UNSIGNED NOT NULL UNIQUE,
            display_name VARCHAR(80) NOT NULL,
            introduction VARCHAR(500) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT 'pending',
            review_note VARCHAR(500) NOT NULL DEFAULT '',
            revision INT UNSIGNED NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX (status, updated_at),
            FOREIGN KEY (member_id) REFERENCES ac_users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ac_creator_application_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            application_id BIGINT UNSIGNED NOT NULL,
            actor_id BIGINT UNSIGNED NOT NULL,
            event VARCHAR(24) NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX (application_id,id),
            FOREIGN KEY (application_id) REFERENCES ac_creator_applications(id),
            FOREIGN KEY (actor_id) REFERENCES ac_users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $q=$pdo->prepare('INSERT IGNORE INTO ac_schema_migrations(version,applied_at) VALUES (?,UTC_TIMESTAMP())');
        $q->execute([self::VERSION]);
    }
}
