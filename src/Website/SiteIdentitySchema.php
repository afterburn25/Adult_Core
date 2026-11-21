<?php

declare(strict_types=1);

namespace AdultCore\Website;

use PDO;
use RuntimeException;

/** Additive revision metadata for the canonical, self-hosted site_name setting. */
final class SiteIdentitySchema
{
    public const VERSION='m20_local_site_identity_v1';

    public static function installed(PDO $pdo): bool
    {
        $q=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
        $q->execute([self::VERSION]);
        return (int)$q->fetchColumn()===1;
    }

    public static function migrate(PDO $pdo): void
    {
        $q=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
        $q->execute([SiteSchema::VERSION]);
        if ((int)$q->fetchColumn()!==1) {
            throw new RuntimeException('Apply site pages before local site identity.');
        }
        if ((int)$pdo->query("SELECT COUNT(*) FROM ac_settings WHERE setting_key='site_name'")->fetchColumn()!==1) {
            throw new RuntimeException('Original local site name is missing; repair from a backup.');
        }
        $pdo->exec("INSERT IGNORE INTO ac_settings(setting_key,setting_value)
            VALUES ('site_name_revision','1')");
        $q=$pdo->prepare('INSERT IGNORE INTO ac_schema_migrations(version,applied_at) VALUES (?,UTC_TIMESTAMP())');
        $q->execute([self::VERSION]);
    }
}
