<?php

declare(strict_types=1);

/** Dedicated test-only disposable DB setup/teardown for real HTTP admin tests. */
require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Database\Schema;
use AdultCore\Installer\InstallService;
use AdultCore\Member\EmailConfirmations;
use AdultCore\Member\PasswordRecovery;

if (getenv('AC_TEST_DB_CONFIRM') !== 'YES_THIS_IS_A_DISPOSABLE_DATABASE') {
    fwrite(STDERR, "Refusing fixture: explicit disposable database confirmation is required.\n");
    exit(2);
}
$action = $argv[1] ?? '';
if (!in_array($action, ['setup', 'cleanup', 'issue-email', 'issue-recovery'], true)) {
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
if ($action === 'issue-email') {
    // Disposable CLI test helper only. This token MUST NEVER be emitted by production routes.
    $q=$pdo->prepare("SELECT id FROM ac_users WHERE email='member@test.example' AND role='member' LIMIT 1");
    $q->execute();
    $id=$q->fetchColumn();
    if ($id===false) throw new RuntimeException('Disposable test member missing.');
    echo (new EmailConfirmations($pdo))->issue((int)$id);
    exit;
}
if ($action === 'issue-recovery') {
    // Disposable test only, never expose generated tokens in an HTTP endpoint.
    $issued = (new PasswordRecovery($pdo))->issueForEmail('member@test.example');
    if (!is_array($issued)) throw new RuntimeException('Disposable member must be confirmed before recovery.');
    echo $issued['token'];
    exit;
}
if ($action === 'cleanup') {
    foreach (['ac_admin_mfa','ac_design_settings','ac_design_assets','ac_verification_receipts', 'ac_moderation_actions', 'ac_media', 'ac_content_reports', 'ac_performer_records', 'ac_memberships', 'ac_content', 'ac_membership_tiers', 'ac_age_verifications', 'ac_member_password_resets', 'ac_member_email_confirmations', 'ac_pages', 'ac_installations', 'ac_settings', 'ac_users', 'ac_schema_migrations'] as $table) {
        $pdo->exec("DROP TABLE IF EXISTS {$table}");
    }
    echo "Disposable HTTP test database cleared.\n";
    exit;
}
Schema::assertFresh($pdo);
$root = dirname(__DIR__);
(new InstallService($root, new \AdultCore\Licensing\CajunWebClient(
        static function (string $body): array {
            $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
            if (!in_array(($payload['product_id'] ?? null), array_keys(\AdultCore\Licensing\CajunWebClient::products()), true)
                || ($payload['license_key'] ?? null) !== 'ABCD-EF12-3456-7890'
                || ($payload['domain'] ?? null) !== '127.0.0.1') throw new RuntimeException('Test license validation contract mismatch.');
            return ['status' => 200, 'body' => (($payload['product_id'] ?? '') === \AdultCore\Licensing\CajunWebClient::SKU_LITE_ONCE ? '{"authorized":true}' : '{"authorized":false}')];
        }
    )))->install([
    'license_key' => 'ABCD-EF12-3456-7890', 'license_domain' => '127.0.0.1',
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
