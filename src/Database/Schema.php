<?php

declare(strict_types=1);

namespace AdultCore\Database;

use PDO;
use RuntimeException;

/** Minimal install schema; membership/content migrations will be separate. */
final class Schema
{
    private const TABLES = ['ac_schema_migrations', 'ac_users', 'ac_settings', 'ac_installations'];

    public static function assertFresh(PDO $pdo): void
    {
        $existing = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
        if (array_filter($existing, static fn($table): bool => is_string($table) && str_starts_with($table, 'ac_')) !== []) {
            throw new RuntimeException('Database already contains Adult Core tables. Use a fresh empty database; do not overwrite an existing installation.');
        }
    }

    public static function create(PDO $pdo): void
    {
        // MySQL DDL auto-commits: see recovery notes in docs/INSTALLATION_LIFECYCLE.md.
        $pdo->exec('CREATE TABLE ac_schema_migrations (version VARCHAR(80) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $pdo->exec('CREATE TABLE ac_users (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, email VARCHAR(190) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, role VARCHAR(32) NOT NULL, created_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $pdo->exec('CREATE TABLE ac_settings (setting_key VARCHAR(120) NOT NULL PRIMARY KEY, setting_value TEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $pdo->exec('CREATE TABLE ac_installations (id TINYINT UNSIGNED NOT NULL PRIMARY KEY, edition VARCHAR(8) NOT NULL, installed_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public static function verify(PDO $pdo): void
    {
        $tables = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
        foreach (self::TABLES as $expected) {
            if (!in_array($expected, $tables, true)) {
                throw new RuntimeException('Schema verification failed.');
            }
        }
        $admins = (int) $pdo->query("SELECT COUNT(*) FROM ac_users WHERE role = 'admin'")->fetchColumn();
        $installations = (int) $pdo->query('SELECT COUNT(*) FROM ac_installations WHERE id = 1')->fetchColumn();
        if ($admins !== 1 || $installations !== 1) {
            throw new RuntimeException('Administrator/installation verification failed.');
        }
    }
}
