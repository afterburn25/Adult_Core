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
if (!in_array($action, ['setup', 'cleanup', 'issue-email', 'issue-recovery', 'stage-gallery', 'revoke-gallery', 'draft-media-id', 'suspend-owner', 'reactivate-owner', 'seed-moderator'], true)) {
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
if ($action === 'suspend-owner' || $action === 'reactivate-owner') {
    // Disposable-only admin security regression; never parameterize user IDs.
    $state=$action==='suspend-owner'?'suspended':'active';
    $q=$pdo->prepare("UPDATE ac_users SET status=? WHERE email='owner@example.test' AND role='admin'");
    $q->execute([$state]);
    if ($q->rowCount()!==1) throw new RuntimeException('Disposable administrator fixture not found.');
    echo 'Disposable administrator status updated.';
    exit;
}
if ($action === 'seed-moderator') {
    // Test-only role account, AFTER the additive member/status migration.
    $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ac_users' AND COLUMN_NAME='status'");
    $q->execute();
    if ((int)$q->fetchColumn()!==1) throw new RuntimeException('Moderator test requires account migration.');
    $q=$pdo->prepare("INSERT INTO ac_users (email,password_hash,role,status,created_at)
        VALUES ('moderator@example.test',?,'moderator','active',UTC_TIMESTAMP())");
    $q->execute([password_hash('strong-example-moderator-password',PASSWORD_DEFAULT)]);
    echo 'Disposable moderator provisioned.';
    exit;
}
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
if ($action === 'draft-media-id') {
    // Read-only test fixture probe. Returns a numeric media ID, never a key.
    $draft=$argv[2]??null;
    if (!is_string($draft) || preg_match('/^[1-9][0-9]{0,17}$/D',$draft)!==1) {
        throw new RuntimeException('Invalid disposable draft identifier.');
    }
    $q=$pdo->prepare("SELECT m.id FROM ac_media m JOIN ac_content c ON c.id=m.content_id
        WHERE c.id=? AND c.title='Hidden safety demo' AND c.status='draft'
          AND c.visibility='private' AND m.review_status='pending'
        ORDER BY m.id DESC LIMIT 1");
    $q->execute([(int)$draft]);
    $mediaId=$q->fetchColumn();
    if ($mediaId===false) throw new RuntimeException('Disposable pending draft image missing.');
    echo (int)$mediaId;
    exit;
}
if ($action === 'stage-gallery') {
    // Test fixture only: deliberately bypasses real provider authorization in
    // this expressly confirmed, disposable MySQL database. This MUST NOT be
    // reused as an administrator action or a production verification adapter.
    $q=$pdo->query("SELECT id FROM ac_users WHERE email='member@test.example' AND role='member' LIMIT 1");
    $member=(int)$q->fetchColumn();
    if ($member<1 || !(new EmailConfirmations($pdo))->verified($member)) {
        throw new RuntimeException('Disposable member must confirm email before the gallery test.');
    }
    $pdo->prepare("UPDATE ac_users SET status='active' WHERE id=?")->execute([$member]);
    $pdo->prepare("INSERT INTO ac_age_verifications
        (user_id,provider,verification_ref_hash,verified_at,expires_at)
        VALUES (?,'disposable_fixture',?,UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))")
        ->execute([$member,hash('sha256','temporary-http-verification')]);
    $pdo->exec("INSERT INTO ac_content
        (title,status,visibility,review_status,requires_consent,expected_performers,created_at)
        VALUES ('Approved local fixture album','published','members','approved',1,1,UTC_TIMESTAMP())");
    $contentId=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO ac_performer_records
        (content_id,private_record_key,verified_at,consent_at,review_status)
        VALUES (?,'disposable-performer-record',UTC_TIMESTAMP(),UTC_TIMESTAMP(),'approved')")
        ->execute([$contentId]);
    $key=bin2hex(random_bytes(20));
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGO4p2gOAAMXATf6OqxSAAAAAElFTkSuQmCC',true);
    if (!is_string($png) || strlen($png)<10) throw new RuntimeException('Invalid disposable PNG.');
    $mediaDirectory=dirname(__DIR__).'/storage/private-media';
    if (!is_dir($mediaDirectory) && !mkdir($mediaDirectory,0700,true)) {
        throw new RuntimeException('Disposable media directory unavailable.');
    }
    $mediaFile=$mediaDirectory.'/'.$key.'.bin';
    if (file_put_contents($mediaFile,$png,LOCK_EX)!==strlen($png)) {
        throw new RuntimeException('Disposable PNG could not be staged.');
    }
    chmod($mediaFile,0600);
    $pdo->prepare("INSERT INTO ac_media
        (content_id,private_storage_key,mime_type,review_status)
        VALUES (?,?,'image/png','approved')")->execute([$contentId,$key]);
    $mediaId=(int)$pdo->lastInsertId();
    // Separate synthetic, inactive tier and active record: dashboard can
    // display the locally stored status, but entitlement remains denied.
    $pdo->exec("INSERT INTO ac_membership_tiers (name,is_active) VALUES ('Staged fixture membership',0)");
    $fixtureTier=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO ac_memberships (user_id,tier_id,status,starts_at,expires_at)
        VALUES (?,?,'active',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY),
                    DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))")->execute([$member,$fixtureTier]);
    echo $contentId.':'.$mediaId;
    exit;
}
if ($action === 'revoke-gallery') {
    $contentId=$argv[2]??null;
    if (!is_string($contentId) || preg_match('/^[1-9][0-9]{0,17}$/D',$contentId)!==1) {
        throw new RuntimeException('Disposable fixture gallery ID invalid.');
    }
    $q=$pdo->prepare("SELECT id FROM ac_content WHERE id=? AND title='Approved local fixture album'");
    $q->execute([(int)$contentId]);
    if (!$q->fetchColumn()) throw new RuntimeException('Disposable fixture gallery missing.');
    $pdo->prepare("UPDATE ac_performer_records SET consent_at=NULL,review_status='pending' WHERE content_id=?")
        ->execute([(int)$contentId]);
    $pdo->prepare("UPDATE ac_content SET status='quarantined',review_status='pending' WHERE id=?")
        ->execute([(int)$contentId]);
    $pdo->prepare("UPDATE ac_media SET review_status='pending' WHERE content_id=?")
        ->execute([(int)$contentId]);
    echo "Disposable performer consent revoked and gallery quarantined.";
    exit;
}
if ($action === 'cleanup') {
    foreach (['ac_creator_draft_events','ac_creator_actions','ac_creator_drafts','ac_creator_profiles','ac_staff_actions','ac_admin_mfa','ac_design_settings','ac_design_assets','ac_verification_receipts', 'ac_moderation_actions', 'ac_media', 'ac_content_reports', 'ac_performer_records', 'ac_memberships', 'ac_content', 'ac_membership_tiers', 'ac_age_verifications', 'ac_member_password_resets', 'ac_member_email_confirmations', 'ac_pages', 'ac_installations', 'ac_settings', 'ac_users', 'ac_schema_migrations'] as $table) {
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
