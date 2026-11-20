<?php

declare(strict_types=1);

namespace AdultCore\Membership;

use PDO;
use RuntimeException;

/** Additive migration for isolated test or upgraded installations; no destructive DDL. */
final class MembershipSchema
{
    public const VERSION = 'm5_memberships_v1';
    public static function migrate(PDO $pdo): void
    {
        $version = $pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version = ?');
        $version->execute([self::VERSION]);
        if ((int)$version->fetchColumn() > 0) return;
        $pdo->exec("ALTER TABLE ac_users ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'active'");
        $pdo->exec('CREATE TABLE ac_membership_tiers (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL, is_active TINYINT NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $pdo->exec('CREATE TABLE ac_memberships (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL, tier_id BIGINT UNSIGNED NOT NULL, status VARCHAR(20) NOT NULL, starts_at DATETIME NOT NULL, expires_at DATETIME NOT NULL, INDEX (user_id, tier_id, status, expires_at), FOREIGN KEY (user_id) REFERENCES ac_users(id), FOREIGN KEY (tier_id) REFERENCES ac_membership_tiers(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $pdo->exec("CREATE TABLE ac_content (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, title VARCHAR(190) NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'draft', visibility VARCHAR(16) NOT NULL DEFAULT 'private', tier_id BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL, INDEX (status, visibility), FOREIGN KEY (tier_id) REFERENCES ac_membership_tiers(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec('CREATE TABLE ac_media (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, content_id BIGINT UNSIGNED NOT NULL, private_storage_key VARCHAR(190) NOT NULL UNIQUE, mime_type VARCHAR(120) NOT NULL, review_status VARCHAR(24) NOT NULL DEFAULT \'pending\', FOREIGN KEY (content_id) REFERENCES ac_content(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $pdo->exec('CREATE TABLE ac_content_reports (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, content_id BIGINT UNSIGNED NOT NULL, reporter_id BIGINT UNSIGNED NOT NULL, reason TEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT \'open\', created_at DATETIME NOT NULL, FOREIGN KEY (content_id) REFERENCES ac_content(id), FOREIGN KEY (reporter_id) REFERENCES ac_users(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $pdo->exec('CREATE TABLE ac_performer_records (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, content_id BIGINT UNSIGNED NOT NULL, private_record_key VARCHAR(190) NOT NULL, verified_at DATETIME NULL, consent_at DATETIME NULL, review_status VARCHAR(24) NOT NULL DEFAULT \'pending\', FOREIGN KEY (content_id) REFERENCES ac_content(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $s=$pdo->prepare('INSERT INTO ac_schema_migrations (version, applied_at) VALUES (?, UTC_TIMESTAMP())');$s->execute([self::VERSION]);
    }
}
