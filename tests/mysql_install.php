<?php

declare(strict_types=1);

/**
 * Real MySQL/MariaDB installation integration test.
 * IMPORTANT: ONLY run against a disposable, initially empty database.
 * Requires AC_TEST_DB_CONFIRM=YES_THIS_IS_A_DISPOSABLE_DATABASE.
 * Example environment variables are documented in docs/TESTING.md.
 */

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Database\Schema;
use AdultCore\Database\Connection;
use AdultCore\Auth\AdminAccounts;
use AdultCore\Installer\InstallService;
use AdultCore\Installer\InstallationGate;
use AdultCore\Website\SiteSchema;
use AdultCore\Website\PageRepository;
use AdultCore\Member\MemberSchema;
use AdultCore\Member\MemberAccounts;
use AdultCore\Membership\MembershipSchema;
use AdultCore\Membership\Entitlements;
use AdultCore\Moderation\ModerationSchema;
use AdultCore\Moderation\ModerationRepository;

if (getenv('AC_TEST_DB_CONFIRM') !== 'YES_THIS_IS_A_DISPOSABLE_DATABASE') {
    fwrite(STDERR, "Refusing to run: disposable test database confirmation is missing.\n");
    exit(2);
}
if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "MySQL PDO extension required for integration test.\n");
    exit(2);
}
$host = getenv('AC_TEST_DB_HOST') ?: '127.0.0.1';
$port = getenv('AC_TEST_DB_PORT') ?: '3306';
$name = getenv('AC_TEST_DB_NAME');
$user = getenv('AC_TEST_DB_USER');
$pass = getenv('AC_TEST_DB_PASSWORD');
if (!is_string($name) || !is_string($user) || !is_string($pass) || $name === '' || $user === '') {
    throw new RuntimeException('Test DB environment variables are missing.');
}
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
Schema::assertFresh($pdo); // Prevent any chance of modifying an existing Adult Core installation.
$root = sys_get_temp_dir() . '/adult-core-db-test-' . bin2hex(random_bytes(8));
mkdir($root);
mkdir($root . '/config');
mkdir($root . '/install');
mkdir($root . '/storage');
$inputs = [
    'db_host' => $host, 'db_port' => (int) $port, 'db_name' => $name,
    'db_user' => $user, 'db_password' => $pass,
    'site_name' => 'Disposable Integration Site',
    'admin_email' => 'owner@example.test',
    'admin_password' => 'super-secret-example-password',
];
try {
    (new InstallService($root))->install($inputs, 'lite');
    $gate = new InstallationGate($root);
    if ($gate->status() !== InstallationGate::CLEANUP_REQUIRED) {
        throw new RuntimeException('Gate did not enforce installer cleanup.');
    }
    $account = $pdo->query("SELECT email, password_hash, role FROM ac_users LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!is_array($account) || $account['role'] !== 'admin' || $account['email'] !== 'owner@example.test'
        || !password_verify('super-secret-example-password', $account['password_hash'])) {
        throw new RuntimeException('Administrator bootstrap did not verify.');
    }
    $edition = $pdo->query('SELECT edition FROM ac_installations WHERE id = 1')->fetchColumn();
    if ($edition !== 'lite') {
        throw new RuntimeException('Persisted edition mismatch.');
    }
    // Verify runtime authentication against actual installed schema/config.
    $auth = new AdminAccounts(Connection::open($root));
    $valid = $auth->verifyCredentials('OWNER@EXAMPLE.TEST', 'super-secret-example-password');
    if (!is_array($valid) || $valid['role'] !== 'admin' || $valid['email'] !== 'owner@example.test') {
        throw new RuntimeException('Real MySQL administrator login failed.');
    }
    if ($auth->verifyCredentials('owner@example.test', 'incorrect-password') !== null
        || $auth->verifyCredentials('missing@example.test', 'super-secret-example-password') !== null) {
        throw new RuntimeException('Incorrect/unknown credentials were accepted.');
    }
    $pdo->prepare('UPDATE ac_users SET role = ? WHERE id = ?')->execute(['member', $valid['id']]);
    if ($auth->verifyCredentials('owner@example.test', 'super-secret-example-password') !== null
        || $auth->authorizedById($valid['id']) !== null) {
        throw new RuntimeException('Revoked administrator role still grants access.');
    }
    $pdo->prepare('UPDATE ac_users SET role = ? WHERE id = ?')->execute(['admin', $valid['id']]);
    if ($auth->authorizedById($valid['id']) === null) {
        throw new RuntimeException('Restored administrator role not recognized.');
    }
    // M6/M7 additive migrations and site/member access against actual MySQL.
    SiteSchema::migrate($pdo);
    SiteSchema::migrate($pdo);  // Repeat-safe.
    MemberSchema::migrate($pdo);
    MemberSchema::migrate($pdo);
    $repo = new PageRepository($pdo);
    if ($repo->published('home') !== null) {
        throw new RuntimeException('Default homepage unexpectedly public before review.');
    }
    $repo->save(0, 'about', 'About the club', "A safe welcome\nNo HTML", 'published');
    if (($repo->published('about')['title'] ?? '') !== 'About the club') {
        throw new RuntimeException('Public-page publishing failed.');
    }
    MembershipSchema::migrate($pdo);
    MembershipSchema::migrate($pdo);
    ModerationSchema::migrate($pdo);
    ModerationSchema::migrate($pdo);
    $accounts = new MemberAccounts($pdo);
    $accounts->register('newmember@example.test', 'long-example-passphrase');
    $member = $accounts->verify('newmember@example.test', 'long-example-passphrase');
    if (($member['status'] ?? '') !== 'pending'
        || $accounts->verify('newmember@example.test', 'wrong') !== null) {
        throw new RuntimeException('Member registration must remain pending and use password hashes.');
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM ac_age_verifications')->fetchColumn() !== 0) {
        throw new RuntimeException('Registration must never self-verify age.');
    }
    $pdo->exec("INSERT INTO ac_content (title,status,visibility,review_status,requires_consent,created_at) VALUES ('Fixture gallery','published','members','approved',1,UTC_TIMESTAMP())");
    $contentId=(int)$pdo->lastInsertId();
    $policy=new Entitlements($pdo);
    if ($policy->canView($member['id'],$contentId)) throw new RuntimeException('Pending account got private content.');
    $pdo->prepare("UPDATE ac_users SET status='active' WHERE id=?")->execute([$member['id']]);
    if ($policy->canView($member['id'],$contentId)) throw new RuntimeException('Unverified adult got private content.');
    $pdo->prepare("INSERT INTO ac_age_verifications (user_id,provider,verification_ref_hash,verified_at) VALUES (?,'test-provider',?,UTC_TIMESTAMP())")
        ->execute([$member['id'],str_repeat('a',64)]);
    if ($policy->canView($member['id'],$contentId)) throw new RuntimeException('Missing consent unlocked private content.');
    $pdo->prepare("INSERT INTO ac_performer_records (content_id,private_record_key,verified_at,consent_at,review_status) VALUES (?,'test-fixture',UTC_TIMESTAMP(),UTC_TIMESTAMP(),'approved')")
        ->execute([$contentId]);
    if (!$policy->canView($member['id'],$contentId)) throw new RuntimeException('Approved test fixture was denied.');
    // A report must quarantine even an otherwise approved fixture.
    $moderation=new ModerationRepository($pdo);
    $draft=$moderation->createDraft('Private test draft',(int)$valid['id']);
    $draftRow=$pdo->query('SELECT status,visibility,review_status FROM ac_content WHERE id='.(int)$draft)->fetch(PDO::FETCH_ASSOC);
    if ($draftRow['status']!=='draft' || $draftRow['visibility']!=='private' || $draftRow['review_status']!=='pending') throw new RuntimeException('New draft was published.');
    $moderation->submitReport((int)$member['id'],$contentId,'Possible consent concern requiring review.');
    if ($policy->canView($member['id'],$contentId)) throw new RuntimeException('Reported content still authorized.');
    $q=$pdo->prepare("SELECT status,review_status FROM ac_content WHERE id=?");
    $q->execute([$contentId]);$state=$q->fetch(PDO::FETCH_ASSOC);
    if ($state['status']!=='quarantined' || $state['review_status']!=='pending') throw new RuntimeException('Report did not quarantine.');
    $count=(int)$pdo->query('SELECT COUNT(*) FROM ac_content_reports')->fetchColumn();
    $moderation->submitReport((int)$member['id'],$contentId,'Duplicate report from member.');
    if ((int)$pdo->query('SELECT COUNT(*) FROM ac_content_reports')->fetchColumn()!==$count) throw new RuntimeException('Duplicate report accepted.');
    $reportId=(int)$pdo->query('SELECT id FROM ac_content_reports LIMIT 1')->fetchColumn();
    $moderation->resolveReport($reportId,(int)$valid['id'],'resolved','Reviewed and retained quarantine.');
    if ($policy->canView($member['id'],$contentId)) throw new RuntimeException('Closing report unlocked quarantined content.');
    $moderation->moderateContent($contentId,(int)$valid['id'],'reject','Rejected pending evidence review.');
    if ($policy->canView($member['id'],$contentId)) throw new RuntimeException('Rejected content is visible.');
    if ((int)$pdo->query('SELECT COUNT(*) FROM ac_moderation_actions')->fetchColumn()<4) throw new RuntimeException('Audit history missing.');
    try {
        $moderation->moderateContent($contentId,(int)$valid['id'],'approve','Unsafe approval attempt.');
        throw new RuntimeException('Unsupported approve was accepted.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Unsupported approve was accepted.') throw $e;
    }
    $pdo->prepare("UPDATE ac_content SET review_status='pending' WHERE id=?")->execute([$contentId]);
    if ($policy->canView($member['id'],$contentId)) throw new RuntimeException('Pending moderation unlocked media.');
    rmdir($root . '/install');
    if ($gate->status() !== InstallationGate::READY) {
        throw new RuntimeException('Gate did not unlock after installer deletion.');
    }
    echo "Passed real MySQL installation, administrator login/role revocation, signed marker and cleanup tests.\n";
} finally {
    // Only touch the four tables this test created. The database is explicitly disposable.
    foreach (['ac_moderation_actions', 'ac_media', 'ac_content_reports', 'ac_performer_records', 'ac_memberships', 'ac_content', 'ac_membership_tiers', 'ac_age_verifications', 'ac_pages', 'ac_installations', 'ac_settings', 'ac_users', 'ac_schema_migrations'] as $table) {
        $pdo->exec("DROP TABLE IF EXISTS {$table}");
    }
    foreach (['storage', 'config'] as $directory) {
        foreach (glob($root . '/' . $directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($root . '/' . $directory . '/.*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($root . '/' . $directory);
    }
    if (is_dir($root . '/install')) {
        rmdir($root . '/install');
    }
    rmdir($root);
}
