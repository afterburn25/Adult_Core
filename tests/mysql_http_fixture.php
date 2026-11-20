<?php

declare(strict_types=1);

/** Dedicated test-only disposable DB setup/teardown for real HTTP admin tests. */
require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Database\Schema;
use AdultCore\Installer\InstallService;

if (getenv('AC_TEST_DB_CONFIRM') !== 'YES_THIS_IS_A_DISPOSABLE_DATABASE') {
    fwrite(STDERR, "Refusing fixture: explicit disposable database confirmation is required.\n");
    exit(2);
}
$action = $argv[1] ?? '';
if (!in_array($action, ['setup', 'cleanup'], true)) {
    throw new RuntimeException('Expected setup or cleanup action.');
}
$host = getenv('AC_TEST_DB_HOST') ?: '127.0.0.1';
$port = (int) (getenv('AC_TEST_DB_PORT') ?: 3306);
$name = getenv('AC_TEST_DB_NAME');
$user = getenv('AC_TEST_DB_USER');
$pass = getenv('AC_TEST_DB_PASSWORD');
if (!is_string($name) || !is_string($user) || !is_string($pass)
    || !preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $name)) {
    throw new RuntimeException('Missing or unsafe disposable test database configuration.');
}
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($action === 'cleanup') {
    foreach (['ac_verification_receipts', 'ac_moderation_actions', 'ac_media', 'ac_content_reports', 'ac_performer_records', 'ac_memberships', 'ac_content', 'ac_membership_tiers', 'ac_age_verifications', 'ac_pages', 'ac_installations', 'ac_settings', 'ac_users', 'ac_schema_migrations'] as $table) {
        $pdo->exec("DROP TABLE IF EXISTS {$table}");
    }
    echo "Disposable HTTP test database cleared.\n";
    exit;
}
Schema::assertFresh($pdo);
$root = dirname(__DIR__);
(new InstallService($root))->install([
    'db_host' => $host, 'db_port' => $port, 'db_name' => $name,
    'db_user' => $user, 'db_password' => $pass, 'site_name' => 'HTTP Fixture <script>alert(1)</script>',
    'admin_email' => 'owner@example.test', 'admin_password' => 'strong-example-admin-password',
], 'lite');
if (!is_dir($root . '/install') || !is_file($root . '/install/index.php')) {
    throw new RuntimeException('Installer fixture missing.');
}
unlink($root . '/install/index.php');
rmdir($root . '/install');
echo "Disposable HTTP admin fixture created.\n";
