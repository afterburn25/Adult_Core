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
use AdultCore\Design\DesignSchema;
use AdultCore\Design\DesignRepository;
use AdultCore\Design\CutoutPlacement;
use AdultCore\Member\MemberSchema;
use AdultCore\Member\MemberEmailSchema;
use AdultCore\Member\EmailConfirmations;
use AdultCore\Member\PasswordRecovery;
use AdultCore\Member\PasswordRecoverySchema;
use AdultCore\Member\MemberAccounts;
use AdultCore\Membership\MembershipSchema;
use AdultCore\Membership\Entitlements;
use AdultCore\Membership\MemberLibrary;
use AdultCore\Membership\TierCatalog;
use AdultCore\Membership\MemberSubscriptionSummary;
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
mkdir($root . '/public');
foreach (['index.php','router.php','.htaccess'] as $webFile) {
    copy(dirname(__DIR__) . '/public/' . $webFile, $root . '/public/' . $webFile);
}
mkdir($root . '/config/editions');
foreach (['lite','pro'] as $editionFile) copy(dirname(__DIR__).'/config/editions/'.$editionFile.'.json', $root.'/config/editions/'.$editionFile.'.json');
$inputs = [
    'license_key' => 'ABCD-EF12-3456-7890', 'license_domain' => '127.0.0.1',
    'db_host' => $host, 'db_port' => (int) $port, 'db_name' => $name,
    'db_user' => $user, 'db_password' => $pass,
    'site_name' => 'Disposable Integration Site',
    'admin_email' => 'owner@example.test',
    'admin_password' => 'super-secret-example-password',
];
try {
    // Explicit rejection must occur before MySQL schema creation.
    try {
        (new InstallService($root, new \AdultCore\Licensing\CajunWebClient(
            static fn(string $body): array => ['status'=>200, 'body'=>'{"authorized":false}']
        )))->install($inputs, 'lite');
        throw new RuntimeException('Rejected vendor license was accepted before installation.');
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'License not authorized')) throw $error;
    }
    Schema::assertFresh($pdo); // No tables may be created for a denied license.
    // Even two valid responses from the issuer must not choose an arbitrary
    // edition or create any tables. Customer entitlements must be unambiguous.
    try {
        (new InstallService($root, new \AdultCore\Licensing\CajunWebClient(
            static function (string $body): array {
                $sku = json_decode($body, true, 8, JSON_THROW_ON_ERROR)['product_id'] ?? '';
                return ['status' => 200, 'body' => json_encode([
                    'authorized' => in_array($sku, [
                        \AdultCore\Licensing\CajunWebClient::SKU_LITE_ONCE,
                        \AdultCore\Licensing\CajunWebClient::SKU_PRO_ONCE,
                    ], true),
                ], JSON_THROW_ON_ERROR)];
            }
        )))->install($inputs, 'lite');
        throw new RuntimeException('Duplicate vendor product grants were accepted.');
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'multiple editions')) throw $error;
    }
    Schema::assertFresh($pdo);
    if (is_file($root.'/config/license.php') || is_file($root.'/storage/install-state.json')) {
        throw new RuntimeException('Duplicate product grant wrote installation state.');
    }

    // Mixed success and temporary issuer failure must not create any schema.
    // Product uniqueness is unverified until all four SKU checks complete.
    try {
        (new InstallService($root, new \AdultCore\Licensing\CajunWebClient(
            static function (string $body): array {
                $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
                $sku = $payload['product_id'] ?? '';
                if ($sku === \AdultCore\Licensing\CajunWebClient::SKU_PRO_MONTH) {
                    return ['status' => 503, 'body' => '{}'];
                }
                return ['status' => 200, 'body' => json_encode([
                    'authorized' => $sku === \AdultCore\Licensing\CajunWebClient::SKU_LITE_ONCE,
                ], JSON_THROW_ON_ERROR)];
            }
        )))->install($inputs, 'lite');
        throw new RuntimeException('Ambiguous vendor response was accepted during installation.');
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'temporarily unavailable')) throw $error;
    }
    Schema::assertFresh($pdo); // No tables may be created for an inconclusive SKU.
    if (is_file($root.'/config/license.php') || is_file($root.'/storage/install-state.json')) {
        throw new RuntimeException('Ambiguous license wrote installation state.');
    }

    // A Pro key cannot silently install Lite just because the host lacks a Pro agent.
    try {
        (new InstallService($root, new \AdultCore\Licensing\CajunWebClient(
            static function(string $body): array {
                $payload=json_decode($body,true,8,JSON_THROW_ON_ERROR);
                return ['status'=>200,'body'=>(($payload['product_id']??'')===\AdultCore\Licensing\CajunWebClient::SKU_PRO_ONCE)
                    ? '{"authorized":true}' : '{"authorized":false}'];
            }
        )))->install($inputs, 'lite');
        throw new RuntimeException('Pro license was allowed to downgrade to Lite.');
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'lacks required capabilities:')) throw $error;
    }
    Schema::assertFresh($pdo); // A Pro license on Lite capabilities must leave no tables.

    if (is_file($root.'/config/license.php') || is_file($root.'/storage/install-state.json')) {
        throw new RuntimeException('Denied license wrote completion data.');
    }
    (new InstallService($root, new \AdultCore\Licensing\CajunWebClient(
        static function (string $body): array {
            $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
            if (!in_array(($payload['product_id'] ?? null), array_keys(\AdultCore\Licensing\CajunWebClient::products()), true)
                || ($payload['license_key'] ?? null) !== 'ABCD-EF12-3456-7890'
                || ($payload['domain'] ?? null) !== '127.0.0.1') throw new RuntimeException('Test license validation contract mismatch.');
            return ['status' => 200, 'body' => (($payload['product_id'] ?? '') === \AdultCore\Licensing\CajunWebClient::SKU_LITE_ONCE ? '{"authorized":true}' : '{"authorized":false}')];
        }
    )))->install($inputs, 'lite');
    if ((new \AdultCore\Licensing\LicenseManager($root))->status('127.0.0.1') !== 'valid') {
        throw new RuntimeException('Completed installation did not persist a valid signed license.');
    }
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
    // Authenticator MFA is installed with the base schema and remains additive.
    \AdultCore\Auth\AdminMfaSchema::migrate($pdo);
    \AdultCore\Auth\AdminMfaSchema::migrate($pdo);
    $mfa = new \AdultCore\Auth\AdminMfa($pdo,$root);
    if (!$mfa->ready() || $mfa->enabledFor((int)$valid['id'])) {
        throw new RuntimeException('Fresh administrator MFA schema was not prepared correctly.');
    }
    $secret = $mfa->newSecret();
    $code = \AdultCore\Auth\Totp::code($secret,intdiv(time(),30));
    $recovery = $mfa->enable((int)$valid['id'],$secret,$code);
    if (count($recovery)!==10 || !$mfa->enabledFor((int)$valid['id']) || $mfa->remainingRecoveryCodes((int)$valid['id'])!==10) {
        throw new RuntimeException('MFA enrollment failed on real MySQL.');
    }
    if ($mfa->consume((int)$valid['id'],$code)) throw new RuntimeException('Authenticator code replay allowed.');
    if (!$mfa->consume((int)$valid['id'],$recovery[0]) || $mfa->consume((int)$valid['id'],$recovery[0])
        || $mfa->remainingRecoveryCodes((int)$valid['id'])!==9) {
        throw new RuntimeException('Recovery codes were not consumed exactly once.');
    }
    $pdo->prepare('DELETE FROM ac_admin_mfa WHERE user_id=?')->execute([(int)$valid['id']]);
    try {$mfa->enabledFor((int)$valid['id']);throw new RuntimeException('Missing enabled MFA row silently disabled protection.');}
    catch (RuntimeException $e) {if (str_contains($e->getMessage(),'silently')) throw $e;}
    // Restore a valid factor row to exercise the supported disabling path.
    $secret=$mfa->newSecret();
    $code=\AdultCore\Auth\Totp::code($secret,intdiv(time(),30));
    $mfa->enable((int)$valid['id'],$secret,$code);
    $mfa->disable((int)$valid['id']);
    if ($mfa->enabledFor((int)$valid['id']))throw new RuntimeException('MFA disable did not clear enforcement state.');
    // M6/M7 additive migrations and site/member access against actual MySQL.
    SiteSchema::migrate($pdo);
    SiteSchema::migrate($pdo);  // Repeat-safe.
    DesignSchema::migrate($pdo);
    DesignSchema::migrate($pdo);
    CutoutPlacement::migrate($pdo);
    CutoutPlacement::migrate($pdo); // Interrupted/previous upgrades must be restart-safe.
    $positionColumns = $pdo->query("SHOW COLUMNS FROM ac_design_settings LIKE 'cutout_%'")->fetchAll(PDO::FETCH_ASSOC);
    if (count($positionColumns)!==3) throw new RuntimeException('Visual positioning columns missing.');
    $design=new DesignRepository($pdo);
    if ($design->current()['theme_key']!=='noir-gold') throw new RuntimeException('Default theme not created.');
    $design->save('velvet-plum','#f19ad0','left','medium',null);
    if ($design->current()['theme_key']!=='velvet-plum' || $design->current()['image_side']!=='left')
        throw new RuntimeException('Theme selection was not persisted.');
    $design->save('velvet-plum','#f19ad0','left','medium',null,
        ['cutout_x'=>23,'cutout_y'=>68,'cutout_width'=>62]);
    $savedPlacement=$design->current();
    if ($savedPlacement['cutout_x']!==23 || $savedPlacement['cutout_y']!==68 || $savedPlacement['cutout_width']!==62)
        throw new RuntimeException('Precise cutout coordinates were not persisted.');
    $design->save('velvet-plum','#f19ad0','left','medium',null);
    $savedPlacement=$design->current();
    if ($savedPlacement['cutout_x']!==23 || $savedPlacement['cutout_y']!==68 || $savedPlacement['cutout_width']!==62)
        throw new RuntimeException('Legacy save unexpectedly reset visual coordinates.');
    // Public image access requires BOTH an admin's explicit public-safe flag and a published homepage.
    $pdo->exec("INSERT INTO ac_design_assets (filename,mime,byte_size,public_ok,created_at)
      VALUES ('" . str_repeat('f',40) . ".png','image/png',120,0,UTC_TIMESTAMP())");
    $assetId=(int)$pdo->lastInsertId();
    $design->save('velvet-plum','#f19ad0','left','medium',$assetId);
    if ($design->publicAsset($assetId)!==null) throw new RuntimeException('Unapproved image became public.');
    $pdo->prepare('UPDATE ac_design_assets SET public_ok=1 WHERE id=?')->execute([$assetId]);
    if ($design->publicAsset($assetId)!==null) throw new RuntimeException('Draft homepage exposed public design image.');
    $pdo->exec("UPDATE ac_pages SET status='published' WHERE slug='home'");
    if ($design->publicAsset($assetId)===null) throw new RuntimeException('Approved selected image inaccessible after publication.');
    $pdo->exec("UPDATE ac_pages SET status='draft' WHERE slug='home'");
    if ($design->publicAsset($assetId)!==null) throw new RuntimeException('Draft unpublishing failed to revoke image.');
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
    $tiers=new TierCatalog($pdo);
    $newTierId=$tiers->create('Founders & <Test>',(int)$valid['id']);
    $tiers->rename($newTierId,(int)$valid['id'],'Founders & <Test>','Founders preview');
    $tierRows=$tiers->all();
    if (count($tierRows)!==1 || $tierRows[0]['id']!==$newTierId
        || $tierRows[0]['name']!=='Founders preview' || $tierRows[0]['is_active']!==0) {
        throw new RuntimeException('Private tier label catalog did not persist.');
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM ac_memberships')->fetchColumn()!==0) {
        throw new RuntimeException('Tier label creation created a paid membership.');
    }
    try {
        $tiers->rename($newTierId,(int)$valid['id'],'Founders & <Test>','Stale overwrite');
        throw new RuntimeException('Stale tier label was accepted.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Stale tier label was accepted.') throw $e;
    }
    try {
        $tiers->create('Founders preview',(int)$valid['id']);
        throw new RuntimeException('Duplicate tier label accepted.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Duplicate tier label accepted.') throw $e;
    }
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
    $library=new MemberLibrary($pdo);
    if ($policy->canView($member['id'],$contentId)) throw new RuntimeException('Pending account got private content.');
    if ($library->visible((int)$member['id']) !== []) {
        throw new RuntimeException('Pending member could see private library titles.');
    }
    $pdo->prepare("UPDATE ac_users SET status='active' WHERE id=?")->execute([$member['id']]);
    if ($policy->canView($member['id'],$contentId)) throw new RuntimeException('Unverified adult got private content.');
    $registry=new VerificationRegistry($pdo);
    $verifiedAge=['event_id'=>'ageverified0001','claim_type'=>'member_age','subject_id'=>$member['id'],
        'decision'=>'verified','reference'=>'approved-provider-ref001','occurred_at'=>time()-10];
    if (!$registry->apply('approved_test',$verifiedAge) || $registry->apply('approved_test',$verifiedAge)) {
        throw new RuntimeException('Age verification event was not idempotent.');
    }
    $conflictingAge=$verifiedAge;
    $conflictingAge['decision']='revoked';
    try {
        $registry->apply('approved_test',$conflictingAge);
        throw new RuntimeException('A conflicting provider event ID was accepted.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='A conflicting provider event ID was accepted.') throw $e;
    }
    $crossProviderStale=$verifiedAge;
    $crossProviderStale['event_id']='secondary_provider_evt001';
    $crossProviderStale['occurred_at']=$verifiedAge['occurred_at']-20;
    try {
        $registry->apply('secondary_provider',$crossProviderStale);
        throw new RuntimeException('An older other-provider age decision was accepted.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='An older other-provider age decision was accepted.') throw $e;
    }
    if ($policy->canView($member['id'],$contentId)) throw new RuntimeException('Missing consent unlocked private content.');
    if ($library->visible((int)$member['id']) !== []) {
        throw new RuntimeException('Missing performer consent exposed a library title.');
    }
    $pdo->prepare("INSERT INTO ac_performer_records (content_id,private_record_key,verified_at,consent_at,review_status) VALUES (?,'test-fixture',UTC_TIMESTAMP(),UTC_TIMESTAMP(),'approved')")
        ->execute([$contentId]);
    if (!$policy->canView($member['id'],$contentId)) throw new RuntimeException('Approved test fixture was denied.');
    $moderationQueue=(new ModerationRepository($pdo))->contentQueue();
    $moderationRow=null;
    foreach ($moderationQueue as $row) {
        if ((int)$row['id'] === $contentId) { $moderationRow=$row; break; }
    }
    if (!is_array($moderationRow) || (int)$moderationRow['recorded_performers']!==1
        || (int)$moderationRow['evidenced_performers']!==1) {
        throw new RuntimeException('Moderation queue failed to summarize documented performer evidence.');
    }
    $authorizedTitles=$library->visible((int)$member['id']);
    if (count($authorizedTitles)!==1 || $authorizedTitles[0]['id']!==$contentId
        || $authorizedTitles[0]['title']!=='Fixture gallery') {
        throw new RuntimeException('Verified member was not shown exactly their authorized gallery.');
    }
    // A large newer set of unentitled published galleries must not crowd
    // an older currently authorized gallery out of the candidate scan.
    // This is a disposable database fixture, not public adult content.
    $noise = $pdo->prepare("INSERT INTO ac_content
        (title,status,visibility,review_status,requires_consent,expected_performers,created_at)
        VALUES (?,'published','members','approved',1,1,UTC_TIMESTAMP())");
    for ($i=0;$i<260;$i++) $noise->execute(['Unentitled noise '.$i]);
    $afterNoise = $library->visible((int)$member['id']);
    if (count($afterNoise)!==1 || $afterNoise[0]['id']!==$contentId) {
        throw new RuntimeException('Newer unauthorized catalog entries hid an entitled older gallery.');
    }
    $pdo->exec("DELETE FROM ac_content WHERE title LIKE 'Unentitled noise %'");
    // Private gallery details independently recheck user entitlement and
    // include only approved, allowlisted image records (never storage keys).
    if ($library->detail((int)$member['id'], $contentId)['title'] !== 'Fixture gallery'
        || $library->detail((int)$member['id'], $contentId)['media'] !== []) {
        throw new RuntimeException('Verified member gallery detail was not safely accessible.');
    }
    $pdo->prepare("INSERT INTO ac_media (content_id,private_storage_key,mime_type,review_status)
        VALUES (?,?,'image/jpeg','approved')")->execute([$contentId,str_repeat('c',40)]);
    $approvedMedia=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO ac_media (content_id,private_storage_key,mime_type,review_status)
        VALUES (?,?,'image/jpeg','pending')")->execute([$contentId,str_repeat('d',40)]);
    $pdo->prepare("INSERT INTO ac_media (content_id,private_storage_key,mime_type,review_status)
        VALUES (?,?,'text/html','approved')")->execute([$contentId,str_repeat('e',40)]);
    $detail=$library->detail((int)$member['id'], $contentId);
    if (!is_array($detail) || count($detail['media'])!==1
        || $detail['media'][0]['id']!==$approvedMedia
        || isset($detail['media'][0]['private_storage_key'])) {
        throw new RuntimeException('Gallery exposed pending or unsupported private media metadata.');
    }
    $galleryHtml=MemberLibrary::renderDetail($detail);
    if (!str_contains($galleryHtml,'src="/media/'.$approvedMedia.'"')
        || str_contains($galleryHtml,str_repeat('c',40))
        || !str_contains($galleryHtml,'/members/report?id='.$contentId)) {
        throw new RuntimeException('Gallery view did not use guarded media URLs or report link.');
    }
    // Missing or mismatched performer coverage always fails closed.
    $pdo->prepare('UPDATE ac_content SET expected_performers=2 WHERE id=?')->execute([$contentId]);
    if ($policy->canView($member['id'],$contentId)) throw new RuntimeException('Incomplete performer coverage was allowed.');
    if ($library->visible((int)$member['id']) !== []
        || $library->detail((int)$member['id'],$contentId) !== null) {
        throw new RuntimeException('Private library or detail revealed gallery with missing performer records.');
    }
    $pdo->prepare('UPDATE ac_content SET expected_performers=0 WHERE id=?')->execute([$contentId]);
    if ($policy->canView($member['id'],$contentId)) throw new RuntimeException('Unknown performer count was allowed.');
    $pdo->prepare('UPDATE ac_content SET expected_performers=1 WHERE id=?')->execute([$contentId]);
    if (!$policy->canView($member['id'],$contentId)) throw new RuntimeException('Expected performer coverage was denied.');

    // Asserted evidence alone does not set review_status='approved' or re-publish content.
    $performerId=(int)$pdo->query('SELECT id FROM ac_performer_records LIMIT 1')->fetchColumn();
    $revoke=['event_id'=>'evidence_revoke_001','claim_type'=>'performer_consent','subject_id'=>$performerId,'decision'=>'revoked','reference'=>'provider-evidence-record-1','occurred_at'=>time()];
    if (!$registry->apply('approved_test', $revoke) || $registry->apply('approved_test', $revoke)) throw new RuntimeException('Provider replay accepted.');
    if ($policy->canView($member['id'],$contentId)) throw new RuntimeException('Revoked consent remained authorized.');
    if ($library->detail((int)$member['id'],$contentId)!==null) {
        throw new RuntimeException('Gallery detail still available after verified performer consent revocation.');
    }
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
    $moderation->renameDraft($draft,(int)$valid['id'],'Private test draft','Private test draft revised');
    $q=$pdo->prepare('SELECT title,status,visibility,review_status FROM ac_content WHERE id=?');
    $q->execute([$draft]); $titleState=$q->fetch(PDO::FETCH_ASSOC);
    if ($titleState['title']!=='Private test draft revised'
        || $titleState['status']!=='draft' || $titleState['visibility']!=='private'
        || $titleState['review_status']!=='pending') {
        throw new RuntimeException('Draft rename changed visibility/review or failed to persist.');
    }
    try {
        $moderation->renameDraft($draft,(int)$valid['id'],'Private test draft','Unsafe stale rename');
        throw new RuntimeException('Stale draft rename accepted.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Stale draft rename accepted.') throw $e;
    }
    $pendingKey=str_repeat('f',40);
    $pdo->prepare("INSERT INTO ac_media (content_id,private_storage_key,mime_type,review_status)
        VALUES (?,?,'image/png','pending')")->execute([$draft,$pendingKey]);
    $reviewMediaId=(int)$pdo->lastInsertId();
    $moderation->rejectDraftImage($reviewMediaId,(int)$valid['id'],
        'Individual pending image rejected for legal review.');
    $q=$pdo->prepare('SELECT review_status,private_storage_key FROM ac_media WHERE id=?');
    $q->execute([$reviewMediaId]); $reviewed=$q->fetch(PDO::FETCH_ASSOC);
    if ($reviewed['review_status']!=='rejected' || $reviewed['private_storage_key']!==$pendingKey) {
        throw new RuntimeException('Individual rejection did not retain private image record and evidence key.');
    }
    try { $moderation->rejectDraftImage($reviewMediaId,(int)$valid['id'],'Reject again without change.');
        throw new RuntimeException('Repeated image rejection was accepted.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Repeated image rejection was accepted.') throw $e;
    }
    $actions=$moderation->auditTrail($draft);
    if (!in_array('image_rejected',array_column($actions,'action'),true)
        || !in_array('draft_renamed',array_column($actions,'action'),true)) {
        throw new RuntimeException('Private title and image decisions lacked audit history.');
    }
    // Tier association is staging-only. Never expose the draft or create
    // members/payment evidence when selecting a future required tier.
    $moderation->assignDraftTier($draft,(int)$valid['id'],null,$newTierId);
    $q=$pdo->prepare('SELECT tier_id,status,visibility,review_status FROM ac_content WHERE id=?');
    $q->execute([$draft]);$tierDraftState=$q->fetch(PDO::FETCH_ASSOC);
    if ((int)$tierDraftState['tier_id']!==$newTierId
        || $tierDraftState['status']!=='draft'
        || $tierDraftState['visibility']!=='private'
        || $tierDraftState['review_status']!=='pending'
        || $policy->canView((int)$member['id'],$draft)) {
        throw new RuntimeException('Assigning a future tier enabled unpublished media.');
    }
    try {
        $moderation->assignDraftTier($draft,(int)$valid['id'],null,99999999);
        throw new RuntimeException('Stale draft tier assignment accepted.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Stale draft tier assignment accepted.') throw $e;
    }
    try {
        $moderation->assignDraftTier($draft,(int)$valid['id'],$newTierId,99999999);
        throw new RuntimeException('Unregistered tier assigned to private draft.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Unregistered tier assigned to private draft.') throw $e;
    }
    $moderation->assignDraftTier($draft,(int)$valid['id'],$newTierId,null);
    $q=$pdo->prepare('SELECT tier_id FROM ac_content WHERE id=?');
    $q->execute([$draft]);
    if ($q->fetchColumn()!==null) {
        throw new RuntimeException('Private draft tier could not be unassigned.');
    }
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
    // A published paid gallery remains hidden until an independently
    // authenticated billing adapter has created a valid active membership.
    // These rows are test fixtures, NOT simulated successful real charges.
    // A newly configured tier label begins disabled. Even an active
    // disposable membership row cannot bypass that entitlement gate.
    $pdo->prepare("INSERT INTO ac_content
        (title,status,visibility,tier_id,review_status,requires_consent,created_at)
        VALUES ('Disabled tier fixture','published','paid',?,'approved',0,UTC_TIMESTAMP())")
        ->execute([$newTierId]);
    $disabledContent=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO ac_memberships (user_id,tier_id,status,starts_at,expires_at)
        VALUES (?,?,'active',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY),
                DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))")->execute([$member['id'],$newTierId]);
    if ($policy->canView((int)$member['id'],$disabledContent)) {
        throw new RuntimeException('Inactive staged catalog tier unlocked paid content.');
    }
    $summary=new MemberSubscriptionSummary($pdo);
    $memberRecords=$summary->forMember((int)$member['id']);
    if (count($memberRecords)!==1 || $memberRecords[0]['name']!=='Founders preview'
        || $memberRecords[0]['is_active']!==0
        || $summary->forMember(9999999)!==[]) {
        throw new RuntimeException('Read-only membership summary leaked or omitted member-scoped records.');
    }
    $statusHtml=MemberSubscriptionSummary::render($memberRecords);
    if (!str_contains($statusHtml,'Disabled for access')
        || !str_contains($statusHtml,'not proof of a completed payment')) {
        throw new RuntimeException('Member subscription view implied a verified charge.');
    }
    $pdo->exec("INSERT INTO ac_membership_tiers (name,is_active) VALUES ('Disposable Test Tier',1)");
    $paidTier=(int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO ac_content (title,status,visibility,tier_id,review_status,requires_consent,created_at)
        VALUES ('Private paid fixture','published','paid',?,'approved',0,UTC_TIMESTAMP())")
        ->execute([$paidTier]);
    $paidContent=(int)$pdo->lastInsertId();
    if ($policy->canView((int)$member['id'],$paidContent)
        || $library->detail((int)$member['id'],$paidContent)!==null) {
        throw new RuntimeException('Private paid gallery was exposed without a paid membership.');
    }
    $pdo->prepare("INSERT INTO ac_memberships (user_id,tier_id,status,starts_at,expires_at)
        VALUES (?,?,'pending',DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY),
        DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY))")->execute([$member['id'],$paidTier]);
    $membershipId=(int)$pdo->lastInsertId();
    if ($library->detail((int)$member['id'],$paidContent)!==null) {
        throw new RuntimeException('Pending payment unlocked paid gallery detail.');
    }
    $pdo->prepare("UPDATE ac_memberships SET status='active' WHERE id=?")->execute([$membershipId]);
    $paidDetails=$library->detail((int)$member['id'],$paidContent);
    if (!is_array($paidDetails) || $paidDetails['id']!==$paidContent) {
        throw new RuntimeException('Active authorized test membership did not unlock paid gallery.');
    }
    $pdo->prepare("UPDATE ac_memberships SET status='cancelled' WHERE id=?")->execute([$membershipId]);
    if ($library->detail((int)$member['id'],$paidContent)!==null) {
        throw new RuntimeException('Cancelled test membership remained authorized for paid gallery.');
    }
    $pdo->prepare("UPDATE ac_memberships SET status='active',expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 SECOND)
        WHERE id=?")->execute([$membershipId]);
    if ($library->detail((int)$member['id'],$paidContent)!==null) {
        throw new RuntimeException('Expired test membership remained authorized for paid gallery.');
    }
    $pdo->prepare("UPDATE ac_memberships SET expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY)
        WHERE id=?")->execute([$membershipId]);
    if ($library->detail((int)$member['id'],$paidContent)===null) {
        throw new RuntimeException('Renewed test membership did not restore gallery entitlement.');
    }
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
    // Upgrade the original installation in place, preserving real MySQL content,
    // themes, members and moderation history, with fault-injected rollback first.
    $proKey='PRO-MONTH-2026-TEST';
    $proClient=new \AdultCore\Licensing\CajunWebClient(
        static function(string $request)use($proKey):array{
            $payload=json_decode($request,true,8,JSON_THROW_ON_ERROR);
            $allowed=($payload['license_key']??null)===$proKey
                &&($payload['domain']??null)==='127.0.0.1'
                &&($payload['product_id']??null)===\AdultCore\Licensing\CajunWebClient::SKU_PRO_MONTH;
            return ['status'=>200,'body'=>json_encode(['authorized'=>$allowed],JSON_THROW_ON_ERROR)];
        }
    );
    // The real CI host is intentionally Lite-only; this injected capability
    // list represents a verified Pro host strictly for this disposable test.
    $capabilities=(new \AdultCore\Edition\EditionSelector($root.'/config/editions'))
        ->manifest('pro')['required_capabilities'];
    $capabilityProbe=static fn():array=>$capabilities;
    $normalUpgrade=new \AdultCore\Licensing\EditionUpgrade($root,$proClient,$capabilityProbe);
    $beforeCount=(int)$pdo->query('SELECT COUNT(*) FROM ac_users')->fetchColumn();
    $beforeTitle=(string)$pdo->query("SELECT title FROM ac_pages WHERE slug='about'")->fetchColumn();
    $beforeTheme=(string)$pdo->query('SELECT theme_key FROM ac_design_settings WHERE id=1')->fetchColumn();
    $beforeContent=(int)$pdo->query('SELECT COUNT(*) FROM ac_content')->fetchColumn();
    $beforeInstalledAt=json_decode((string)file_get_contents($root.'/storage/install-state.json'),true)['installed_at'];
    try { $normalUpgrade->preflight('ABCD-EF12-3456-7890','127.0.0.1');
        throw new RuntimeException('Lite key was accepted for Pro upgrade.');
    } catch (RuntimeException $e) { if ($e->getMessage()==='Lite key was accepted for Pro upgrade.') throw $e; }
    try { $normalUpgrade->preflight($proKey,'other.example');
        throw new RuntimeException('Cross-domain Pro upgrade was accepted.');
    } catch (RuntimeException $e) { if ($e->getMessage()==='Cross-domain Pro upgrade was accepted.') throw $e; }
    $noAgent=new \AdultCore\Licensing\EditionUpgrade($root,$proClient,static fn():array=>['php_83']);
    try {$noAgent->upgrade($proKey,'127.0.0.1');
        throw new RuntimeException('Pro upgrade allowed on Lite-only host.');
    } catch (RuntimeException $e) { if ($e->getMessage()==='Pro upgrade allowed on Lite-only host.') throw $e; }
    $fault=new \AdultCore\Licensing\EditionUpgrade($root,$proClient,$capabilityProbe,
        static function():void{throw new RuntimeException('Synthetic interruption after committed edition change.');});
    try { $fault->upgrade($proKey,'127.0.0.1');
        throw new RuntimeException('Injected Pro upgrade failure was ignored.');
    } catch (RuntimeException $e) { if ($e->getMessage()==='Injected Pro upgrade failure was ignored.') throw $e; }
    if ($pdo->query('SELECT edition FROM ac_installations WHERE id=1')->fetchColumn()!=='lite'
        || (new \AdultCore\Licensing\LicenseManager($root))->config()['edition']!=='lite'
        || (new InstallationGate($root))->status()!==InstallationGate::READY
        || is_dir($root.'/storage/.edition-upgrade-backup')
        || is_file($root.'/storage/.edition-upgrade-pending')) {
        throw new RuntimeException('Failed Pro upgrade did not atomically recover Lite metadata and gate.');
    }
    // Simulate a hard process stop after the DB commit: no catch handler has
    // restored files. The signed maintenance sentinel must block HTTP until
    // explicit operator CLI-equivalent rollback recovers the original Lite.
    $takeBackup = new ReflectionMethod(\AdultCore\Licensing\EditionUpgrade::class, 'takeBackup');
    $takeBackup->invoke($normalUpgrade, (string)(require $root.'/config/app-key.php'));
    \AdultCore\Installer\AtomicFile::write($root.'/storage/.edition-upgrade-pending', 'synthetic crash');
    $pdo->exec("UPDATE ac_installations SET edition='pro' WHERE id=1 AND edition='lite'");
    if ((new InstallationGate($root))->status()!==InstallationGate::EDITION_UPGRADE_PENDING)
        throw new RuntimeException('Crashed upgrade failed to lock every site route.');
    $normalUpgrade->rollback();
    if ((new InstallationGate($root))->status()!==InstallationGate::READY
        || $pdo->query('SELECT edition FROM ac_installations WHERE id=1')->fetchColumn()!=='lite'
        || (new \AdultCore\Licensing\LicenseManager($root))->config()['edition']!=='lite') {
        throw new RuntimeException('CLI recovery failed to restore Lite after a simulated hard crash.');
    }
    $normalUpgrade->upgrade($proKey,'127.0.0.1');
    $newLicense=(new \AdultCore\Licensing\LicenseManager($root))->config();
    if ($pdo->query('SELECT edition FROM ac_installations WHERE id=1')->fetchColumn()!=='pro'
        || ($newLicense['edition']??null)!=='pro'
        || ($newLicense['term']??null)!=='month'
        || (new InstallationGate($root))->status()!==InstallationGate::READY
        || is_file($root.'/storage/.edition-upgrade-pending')
        || is_dir($root.'/storage/.edition-upgrade-backup')) {
        throw new RuntimeException('Pro upgrade did not persist and verify complete edition transition.');
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM ac_users')->fetchColumn()!==$beforeCount
        || (string)$pdo->query("SELECT title FROM ac_pages WHERE slug='about'")->fetchColumn()!==$beforeTitle
        || (string)$pdo->query('SELECT theme_key FROM ac_design_settings WHERE id=1')->fetchColumn()!==$beforeTheme
        || (int)$pdo->query('SELECT COUNT(*) FROM ac_content')->fetchColumn()!==$beforeContent
        || json_decode((string)file_get_contents($root.'/storage/install-state.json'),true)['installed_at']!==$beforeInstalledAt) {
        throw new RuntimeException('Pro upgrade modified existing content, members, theme or install timestamp.');
    }
    try { $normalUpgrade->upgrade($proKey,'127.0.0.1');
        throw new RuntimeException('Second upgrade wrongly accepted on installed Pro edition.');
    } catch (RuntimeException $e) { if ($e->getMessage()==='Second upgrade wrongly accepted on installed Pro edition.') throw $e; }
    echo "Passed real MySQL installation, administrator login/role revocation, signed marker, cleanup and Lite-to-Pro upgrade/rollback tests.\n";
} finally {
    // Only touch the four tables this test created. The database is explicitly disposable.
    foreach (['ac_admin_mfa','ac_design_settings','ac_design_assets','ac_verification_receipts', 'ac_moderation_actions', 'ac_media', 'ac_content_reports', 'ac_performer_records', 'ac_memberships', 'ac_content', 'ac_membership_tiers', 'ac_age_verifications', 'ac_member_password_resets', 'ac_member_email_confirmations', 'ac_pages', 'ac_installations', 'ac_settings', 'ac_users', 'ac_schema_migrations'] as $table) {
        $pdo->exec("DROP TABLE IF EXISTS {$table}");
    }
    foreach (['lite','pro'] as $ed) @unlink($root.'/config/editions/'.$ed.'.json');
    @rmdir($root.'/config/editions');
    foreach (['index.php','router.php','.htaccess'] as $webFile) {
        @unlink($root . '/public/' . $webFile);
    }
    @rmdir($root.'/public');
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
