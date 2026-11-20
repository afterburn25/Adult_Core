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
use AdultCore\Member\MemberEmailSchema;
use AdultCore\Member\EmailConfirmations;
use AdultCore\Member\PasswordRecovery;
use AdultCore\Member\PasswordRecoverySchema;
use AdultCore\Member\MemberAccounts;
use AdultCore\Membership\MembershipSchema;
use AdultCore\Membership\Entitlements;
use AdultCore\Moderation\ModerationSchema;
use AdultCore\Verification\VerificationSchema;
use AdultCore\Verification\VerificationRegistry;
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
    MemberEmailSchema::migrate($pdo);
    MemberEmailSchema::migrate($pdo);
    PasswordRecoverySchema::migrate($pdo);
    PasswordRecoverySchema::migrate($pdo);
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
    VerificationSchema::migrate($pdo);
    VerificationSchema::migrate($pdo);
    $accounts = new MemberAccounts($pdo);
    $accounts->register('newmember@example.test', 'long-example-passphrase');
    $member = $accounts->verify('newmember@example.test', 'long-example-passphrase');
    if (($member['status'] ?? '') !== 'pending'
        || $accounts->verify('newmember@example.test', 'wrong') !== null) {
        throw new RuntimeException('Member registration must remain pending and use password hashes.');
    }
    $emailConfirmations=new EmailConfirmations($pdo);
    if ($emailConfirmations->verified((int)$member['id'])) throw new RuntimeException('Member automatically email-confirmed.');
    $firstEmailToken=$emailConfirmations->issue((int)$member['id']);
    if (strlen($firstEmailToken)!==64 || preg_match('/^[a-f0-9]{64}$/D',$firstEmailToken)!==1) throw new RuntimeException('Email token format invalid.');
    if ($emailConfirmations->confirm('not-a-token') || $emailConfirmations->verified((int)$member['id'])) throw new RuntimeException('Bad email confirmation was accepted.');
    try { $emailConfirmations->issue((int)$member['id']); throw new RuntimeException('Resend cooldown was bypassed.'); }
    catch (RuntimeException $e) { if ($e->getMessage()==='Resend cooldown was bypassed.') throw $e; }
    // Explicitly expire the first token and verify it is never accepted.
    $pdo->prepare('UPDATE ac_member_email_confirmations SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND),issued_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE) WHERE user_id=?')->execute([$member['id']]);
    if ($emailConfirmations->confirm($firstEmailToken)) throw new RuntimeException('Expired email token was accepted.');
    $secondEmailToken=$emailConfirmations->issue((int)$member['id']);
    if ($emailConfirmations->confirm($firstEmailToken) || !$emailConfirmations->confirm($secondEmailToken)
        || $emailConfirmations->confirm($secondEmailToken) || !$emailConfirmations->verified((int)$member['id'])) {
        throw new RuntimeException('Email token single-use confirmation failed.');
    }
    if ($accounts->byId((int)$member['id'])['status']!=='pending') throw new RuntimeException('Email confirmation activated pending member.');
    $recovery = new PasswordRecovery($pdo);
    if ($recovery->issueForEmail('unknown@example.test') !== null) throw new RuntimeException('Unknown user recovery token generated.');
    if ($recovery->issueForEmail('owner@example.test') !== null) throw new RuntimeException('Admin recovery exposed through member route.');
    $firstReset=$recovery->issueForEmail('NEWMEMBER@EXAMPLE.TEST');
    if (!is_array($firstReset) || !preg_match('/^[a-f0-9]{64}$/D',$firstReset['token'])) throw new RuntimeException('Confirmed member could not request reset.');
    if ($recovery->issueForEmail('newmember@example.test') !== null) throw new RuntimeException('Reset cooldown bypassed.');
    $pdo->prepare('UPDATE ac_member_password_resets SET issued_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 MINUTE),expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND) WHERE user_id=?')->execute([$member['id']]);
    if ($recovery->reset($firstReset['token'],'new-valid-test-password')) throw new RuntimeException('Expired password reset token accepted.');
    $newReset=$recovery->issueForEmail('newmember@example.test');
    if (!is_array($newReset) || $recovery->reset($firstReset['token'],'new-valid-test-password')) throw new RuntimeException('Old reset token unexpectedly reusable.');
    try { $recovery->reset($newReset['token'],'short'); throw new RuntimeException('Weak recovery password accepted.'); }
    catch (RuntimeException $e) { if ($e->getMessage()==='Weak recovery password accepted.') throw $e; }
    $beforeGeneration=$recovery->generation((int)$member['id']);
    if (!$recovery->reset($newReset['token'],'new-valid-test-password') || $recovery->reset($newReset['token'],'another-valid-passphrase')) {
        throw new RuntimeException('Reset replay or valid reset failed.');
    }
    if ($recovery->generation((int)$member['id'])!==$beforeGeneration+1) throw new RuntimeException('Existing session generation not invalidated.');
    if ($accounts->verify('newmember@example.test','long-example-passphrase')!==null
        || $accounts->verify('newmember@example.test','new-valid-test-password')===null
        || $accounts->byId((int)$member['id'])['status']!=='pending') {
        throw new RuntimeException('Password reset changed account eligibility or did not rotate password.');
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM ac_age_verifications')->fetchColumn() !== 0) {
        throw new RuntimeException('Registration must never self-verify age.');
    }
    $pdo->exec("INSERT INTO ac_content (title,status,visibility,review_status,requires_consent,expected_performers,created_at) VALUES ('Fixture gallery','published','members','approved',1,1,UTC_TIMESTAMP())");
    $contentId=(int)$pdo->lastInsertId();
    $policy=new Entitlements($pdo);
    if ($policy->canView($member['id'],$contentId)) throw new RuntimeException('Pending account got private content.');
    $pdo->prepare("UPDATE ac_users SET status='active' WHERE id=?")->execute([$member['id']]);
    if ($policy->canView($member['id'],$contentId)) throw new RuntimeException('Unverified adult got private content.');
    $registry=new VerificationRegistry($pdo);
    $verifiedAge=['event_id'=>'ageverified0001','claim_type'=>'member_age','subject_id'=>$member['id'],
        'decision'=>'verified','reference'=>'approved-provider-ref001','occurred_at'=>time()-10];
    if (!$registry->apply('approved_test',$verifiedAge) || $registry->apply('approved_test',$verifiedAge)) {
        throw new RuntimeException('Age verification event was not idempotent.');
    }
    if ($policy->canView($member['id'],$contentId)) throw new RuntimeException('Missing consent unlocked private content.');
    $pdo->prepare("INSERT INTO ac_performer_records (content_id,private_record_key,verified_at,consent_at,review_status) VALUES (?,'test-fixture',UTC_TIMESTAMP(),UTC_TIMESTAMP(),'approved')")
        ->execute([$contentId]);
    if (!$policy->canView($member['id'],$contentId)) throw new RuntimeException('Approved test fixture was denied.');
    // Missing or mismatched performer coverage always fails closed.
    $pdo->prepare('UPDATE ac_content SET expected_performers=2 WHERE id=?')->execute([$contentId]);
    if ($policy->canView($member['id'],$contentId)) throw new RuntimeException('Incomplete performer coverage was allowed.');
    $pdo->prepare('UPDATE ac_content SET expected_performers=0 WHERE id=?')->execute([$contentId]);
    if ($policy->canView($member['id'],$contentId)) throw new RuntimeException('Unknown performer count was allowed.');
    $pdo->prepare('UPDATE ac_content SET expected_performers=1 WHERE id=?')->execute([$contentId]);
    if (!$policy->canView($member['id'],$contentId)) throw new RuntimeException('Expected performer coverage was denied.');

    // Asserted evidence alone does not set review_status='approved' or re-publish content.
    $performerId=(int)$pdo->query('SELECT id FROM ac_performer_records LIMIT 1')->fetchColumn();
    $revoke=['event_id'=>'evidence_revoke_001','claim_type'=>'performer_consent','subject_id'=>$performerId,'decision'=>'revoked','reference'=>'provider-evidence-record-1','occurred_at'=>time()];
    if (!$registry->apply('approved_test', $revoke) || $registry->apply('approved_test', $revoke)) throw new RuntimeException('Provider replay accepted.');
    if ($policy->canView($member['id'],$contentId)) throw new RuntimeException('Revoked consent remained authorized.');
    $q=$pdo->prepare('SELECT consent_at,review_status FROM ac_performer_records WHERE id=?');$q->execute([$performerId]);
    $record=$q->fetch(PDO::FETCH_ASSOC);
    if ($record['consent_at'] !== null || $record['review_status'] !== 'pending') throw new RuntimeException('Revocation did not invalidate approval.');
    try {
        $registry->apply('approved_test', ['event_id'=>'older_verify_001','claim_type'=>'performer_consent',
            'subject_id'=>$performerId,'decision'=>'verified','reference'=>'provider-evidence-record-1','occurred_at'=>time()-10]);
        throw new RuntimeException('Stale provider event overrode revocation.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Stale provider event overrode revocation.') throw $e;
    }


    // Safety reports must auto-quarantine even an otherwise approved fixture.
    $moderation=new ModerationRepository($pdo);
    $draft=$moderation->createDraft('Private test draft',(int)$valid['id']);
    $roster=$moderation->declarePerformers($draft,(int)$valid['id'],2);
    if (count($roster)!==2 || count(array_unique($roster))!==2) throw new RuntimeException('Performer roster was not declared.');
    try { $moderation->declarePerformers($draft,(int)$valid['id'],1); throw new RuntimeException('Duplicate roster unexpectedly created.'); }
    catch (RuntimeException $e) { if ($e->getMessage()==='Duplicate roster unexpectedly created.') throw $e; }

    $draftRow=$pdo->query('SELECT status,visibility,review_status FROM ac_content WHERE id='.(int)$draft)->fetch(PDO::FETCH_ASSOC);
    if ($draftRow['status']!=='draft' || $draftRow['visibility']!=='private' || $draftRow['review_status']!=='pending') {
        throw new RuntimeException('New content draft was published.');
    }
    $moderation->submitReport((int)$member['id'],$contentId,'Possible consent concern requiring review.');
    if ($policy->canView($member['id'],$contentId)) throw new RuntimeException('Reported content still authorized.');
    $q=$pdo->prepare("SELECT status,review_status FROM ac_content WHERE id=?");
    $q->execute([$contentId]);
    $state=$q->fetch(PDO::FETCH_ASSOC);
    if ($state['status']!=='quarantined' || $state['review_status']!=='pending') throw new RuntimeException('Report did not quarantine.');
    $count=(int)$pdo->query('SELECT COUNT(*) FROM ac_content_reports')->fetchColumn();
    $moderation->submitReport((int)$member['id'],$contentId,'Duplicate report from the same member.');
    if ((int)$pdo->query('SELECT COUNT(*) FROM ac_content_reports')->fetchColumn()!==$count) throw new RuntimeException('Duplicate report accepted.');
    $reportId=(int)$pdo->query('SELECT id FROM ac_content_reports LIMIT 1')->fetchColumn();
    $moderation->resolveReport($reportId,(int)$valid['id'],'resolved','Reviewed and retained quarantine.');
    if ($policy->canView($member['id'],$contentId)) throw new RuntimeException('Closing report unlocked quarantined content.');
    $moderation->moderateContent($contentId,(int)$valid['id'],'reject','Rejected pending evidence review.');
    if ($policy->canView($member['id'],$contentId)) throw new RuntimeException('Rejected content is visible.');
    if ((int)$pdo->query('SELECT COUNT(*) FROM ac_moderation_actions')->fetchColumn()<4) throw new RuntimeException('Audit history missing.');
    try { $moderation->moderateContent($contentId,(int)$valid['id'],'approve','Unsafe approval attempt.');
        throw new RuntimeException('Unsupported approve was accepted.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Unsupported approve was accepted.') throw $e;
    }
    $pdo->prepare("UPDATE ac_content SET review_status='pending' WHERE id=?")->execute([$contentId]);
    if ($policy->canView($member['id'],$contentId)) throw new RuntimeException('Pending moderation unlocked media.');
    $pdo->exec("INSERT INTO ac_content (title,status,visibility,review_status,requires_consent,created_at) VALUES ('Safe test text','published','members','approved',0,UTC_TIMESTAMP())");
    $safeId=(int)$pdo->lastInsertId();
    if (!$policy->canView($member['id'],$safeId)) throw new RuntimeException('Verified fixture was denied.');
    $revokeAge=['event_id'=>'agerevoked0001','claim_type'=>'member_age','subject_id'=>$member['id'],
        'decision'=>'revoked','reference'=>'approved-provider-ref001','occurred_at'=>time()];
    if (!$registry->apply('approved_test',$revokeAge)) throw new RuntimeException('Age revocation was not recorded.');
    if ($policy->canView($member['id'],$safeId)) throw new RuntimeException('Age revocation did not deny private access.');
    try {
        $registry->apply('approved_test', ['event_id'=>'ageolder0001','claim_type'=>'member_age','subject_id'=>$member['id'],
          'decision'=>'verified','reference'=>'approved-provider-ref001','occurred_at'=>time()-100]);
        throw new RuntimeException('Stale age event restored access.');
    } catch (RuntimeException $e) { if ($e->getMessage()==='Stale age event restored access.') throw $e; }

    rmdir($root . '/install');
    if ($gate->status() !== InstallationGate::READY) {
        throw new RuntimeException('Gate did not unlock after installer deletion.');
    }
    echo "Passed real MySQL installation, administrator login/role revocation, signed marker and cleanup tests.\n";
} finally {
    // Only touch the four tables this test created. The database is explicitly disposable.
    foreach (['ac_verification_receipts', 'ac_moderation_actions', 'ac_media', 'ac_content_reports', 'ac_performer_records', 'ac_memberships', 'ac_content', 'ac_membership_tiers', 'ac_age_verifications', 'ac_member_password_resets', 'ac_member_email_confirmations', 'ac_pages', 'ac_installations', 'ac_settings', 'ac_users', 'ac_schema_migrations'] as $table) {
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
