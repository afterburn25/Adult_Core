<?php

declare(strict_types=1);

namespace AdultCore\Membership;

use PDO;

/** Idempotent additive schema for private content; MySQL DDL is not transactional. */
final class MembershipSchema
{
    public const VERSION = 'm8_restricted_content_v1';

    public static function migrate(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ac_membership_tiers (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(120) NOT NULL,
          is_active TINYINT NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ac_memberships (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
          tier_id BIGINT UNSIGNED NOT NULL, status VARCHAR(20) NOT NULL,
          starts_at DATETIME NOT NULL, expires_at DATETIME NOT NULL,
          INDEX (user_id,tier_id,status,expires_at),
          FOREIGN KEY (user_id) REFERENCES ac_users(id),
          FOREIGN KEY (tier_id) REFERENCES ac_membership_tiers(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ac_content (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          title VARCHAR(190) NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'draft',
          visibility VARCHAR(16) NOT NULL DEFAULT 'private',
          tier_id BIGINT UNSIGNED NULL,
          review_status VARCHAR(20) NOT NULL DEFAULT 'pending',
          requires_consent TINYINT NOT NULL DEFAULT 1,
          expected_performers SMALLINT UNSIGNED NOT NULL DEFAULT 0,
          created_at DATETIME NOT NULL,
          INDEX(status, visibility, review_status),
          FOREIGN KEY (tier_id) REFERENCES ac_membership_tiers(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ac_media (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          content_id BIGINT UNSIGNED NOT NULL,
          private_storage_key VARCHAR(190) NOT NULL UNIQUE,
          mime_type VARCHAR(120) NOT NULL,
          review_status VARCHAR(24) NOT NULL DEFAULT 'pending',
          FOREIGN KEY (content_id) REFERENCES ac_content(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ac_content_reports (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          content_id BIGINT UNSIGNED NOT NULL, reporter_id BIGINT UNSIGNED NOT NULL,
          reason TEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'open',
          created_at DATETIME NOT NULL,
          FOREIGN KEY (content_id) REFERENCES ac_content(id),
          FOREIGN KEY (reporter_id) REFERENCES ac_users(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ac_performer_records (
          id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          content_id BIGINT UNSIGNED NOT NULL,
          private_record_key VARCHAR(190) NOT NULL,
          verified_at DATETIME NULL, consent_at DATETIME NULL,
          review_status VARCHAR(24) NOT NULL DEFAULT 'pending',
          FOREIGN KEY (content_id) REFERENCES ac_content(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Repair a previous partially-run M5 draft without dropping any data.
        self::ensureColumn($pdo, 'ac_content', 'review_status', "VARCHAR(20) NOT NULL DEFAULT 'pending'");
        self::ensureColumn($pdo, 'ac_content', 'requires_consent', 'TINYINT NOT NULL DEFAULT 1');
        self::ensureColumn($pdo, 'ac_content', 'expected_performers', 'SMALLINT UNSIGNED NOT NULL DEFAULT 0');
        $s=$pdo->prepare('INSERT IGNORE INTO ac_schema_migrations (version, applied_at) VALUES (?,UTC_TIMESTAMP())');
        $s->execute([self::VERSION]);
    }

    private static function ensureColumn(PDO $pdo, string $table, string $column, string $type): void
    {
        $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $q->execute([$table,$column]);
        if ((int)$q->fetchColumn() === 0) $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$type}");
    }
}
