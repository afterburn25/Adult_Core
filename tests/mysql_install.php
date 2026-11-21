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
use AdultCore\Auth\StaffSchema;
use AdultCore\Auth\StaffRepository;
use AdultCore\Installer\InstallService;
use AdultCore\Installer\InstallInput;
use AdultCore\Installer\InstallationGate;
use AdultCore\Website\SiteSchema;
use AdultCore\Website\PageRepository;
use AdultCore\Website\PageRevisionSchema;
use AdultCore\Website\PageNavigationSchema;
use AdultCore\Website\PageHistorySchema;
use AdultCore\Website\SiteIdentity;
use AdultCore\Website\SiteIdentitySchema;
use AdultCore\Design\DesignSchema;
use AdultCore\Design\HighlightSchema;
use AdultCore\Design\DesignRepository;
use AdultCore\Design\CutoutPlacement;
use AdultCore\Member\MemberSchema;
use AdultCore\Creator\CreatorSchema;
use AdultCore\Creator\LocalCreators;
use AdultCore\Creator\CreatorReviewSchema;
use AdultCore\Creator\CreatorFeedbackSchema;
use AdultCore\Creator\CreatorFeedbackHistory;
use AdultCore\Creator\CreatorDraftReview;
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
    // The same single-line UTF-8 name policy applies to fresh installation,
    // BEFORE transmitting any paid key or opening the installation database.
    foreach (["Bad\x00Name","Line\nBreak",'Trailing name'."\0"] as $invalidName) {
        $candidate=$inputs;
        $candidate['site_name']=$invalidName;
        try {
            InstallInput::validateForService($candidate);
            throw new RuntimeException('Invalid installer brand was accepted.');
        } catch (\InvalidArgumentException $e) {
            if ($e->getMessage()==='Invalid installer brand was accepted.') throw $e;
        }
    }
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
    SiteIdentitySchema::migrate($pdo);
    SiteIdentitySchema::migrate($pdo);
    $identity=new SiteIdentity($pdo);
    if ($identity->current()!==['site_name'=>'Disposable Integration Site','revision'=>1]) {
        throw new RuntimeException('Local brand migration changed original installer name.');
    }
    $identity->rename('Local <Club> & More',1);
    if ($identity->current()!==['site_name'=>'Local <Club> & More','revision'=>2]) {
        throw new RuntimeException('Local brand revision was not persisted.');
    }
    foreach ([
        static fn()=> $identity->rename('Stale overwrite',1),
        static fn()=> $identity->rename("Bad\nBrand",2),
    ] as $badChange) {
        try {
            $badChange();
            throw new RuntimeException('Unsafe or stale branding change was accepted.');
        } catch (RuntimeException $e) {
            if ($e->getMessage()==='Unsafe or stale branding change was accepted.') throw $e;
        }
    }
    $identity->rename('Disposable Integration Site',2);
    if ($identity->current()['revision']!==3) {
        throw new RuntimeException('Local brand repeat-edit revision failed.');
    }
    PageRevisionSchema::migrate($pdo);
    PageRevisionSchema::migrate($pdo); // Legacy and interrupted updates are repeat-safe.
    $revisionColumns=$pdo->query("SHOW COLUMNS FROM ac_pages LIKE 'revision'")->fetchAll(PDO::FETCH_ASSOC);
    if (count($revisionColumns)!==1) throw new RuntimeException('Page revision migration is not repeat-safe.');
    PageNavigationSchema::migrate($pdo);
    PageNavigationSchema::migrate($pdo);
    PageHistorySchema::migrate($pdo);
    PageHistorySchema::migrate($pdo);
    $initialHistory=(int)$pdo->query("SELECT COUNT(*) FROM ac_page_history WHERE slug='home'")->fetchColumn();
    if ($initialHistory!==1) throw new RuntimeException('Legacy homepage history backfill duplicated or vanished.');
    foreach (['show_in_menu','nav_position'] as $column) {
        $columnCheck=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ac_pages' AND COLUMN_NAME=?");
        $columnCheck->execute([$column]);
        if ((int)$columnCheck->fetchColumn()!==1) throw new RuntimeException('Navigation upgrade missing column.');
    }
    DesignSchema::migrate($pdo);
    DesignSchema::migrate($pdo);
    CutoutPlacement::migrate($pdo);
    CutoutPlacement::migrate($pdo); // Interrupted/previous upgrades must be restart-safe.
    HighlightSchema::migrate($pdo);
    HighlightSchema::migrate($pdo);
    $testCards=HighlightSchema::defaults();
    $testCards[0]=['title'=>'Owner-made highlight','body'=>'Site-local title and text'];
    $cardsRepo=new DesignRepository($pdo);
    $cardsRepo->save('noir-gold','#d9ae75','right','large',null,null,$testCards);
    if ($cardsRepo->current()['highlights']!==$testCards) {
        throw new RuntimeException('Owner-edited homepage info cards did not persist.');
    }
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
    StaffSchema::migrate($pdo);
    StaffSchema::migrate($pdo);
    $staff=new StaffRepository($pdo);
    $createdStaff=$staff->createModerator((int)$valid['id'],'  STAFF.MANAGED@example.test  ','first-unique-moderator-password');
    if ($createdStaff<1 || count($staff->recentActions((int)$valid['id']))!==1) {
        throw new RuntimeException('Manager could not create audited restricted moderator.');
    }
    $mfaStaffList=$staff->moderators((int)$valid['id']);
    if ($mfaStaffList[0]['mfa_enabled']!==0) {
        throw new RuntimeException('Staff MFA state was incorrectly claimed as enrolled.');
    }
    $managed=$auth->verifyCredentials('staff.managed@example.test','first-unique-moderator-password');
    $firstEpoch=$auth->staffSessionEpoch($createdStaff);
    if (($managed['role']??null)!=='moderator'
        || (int)($managed['id']??0)!==$createdStaff || $firstEpoch!==0) {
        throw new RuntimeException('Managed staff was not a restricted authenticated moderator.');
    }
    foreach (['STAFF.MANAGED@example.test','owner@example.test'] as $duplicate) {
        try {
            $staff->createModerator((int)$valid['id'],$duplicate,'another-strong-moderator-password');
            throw new RuntimeException('Duplicate staff email was accepted.');
        } catch (RuntimeException $e) {
            if ($e->getMessage()==='Duplicate staff email was accepted.') throw $e;
        }
    }
    try {
        $staff->suspendModerator((int)$valid['id'],(int)$valid['id']);
        throw new RuntimeException('Manager suspended admin through staff endpoint.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Manager suspended admin through staff endpoint.') throw $e;
    }
    $staff->suspendModerator((int)$valid['id'],$createdStaff);
    if ($auth->authorizedById($createdStaff)!==null
        || $auth->staffSessionEpoch($createdStaff)!==$firstEpoch+1
        || $auth->verifyCredentials('staff.managed@example.test','first-unique-moderator-password')!==null) {
        throw new RuntimeException('Suspending moderator did not revoke credentials/epoch.');
    }
    try {
        $staff->suspendModerator((int)$valid['id'],$createdStaff);
        throw new RuntimeException('Repeated moderator suspension succeeded.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Repeated moderator suspension succeeded.') throw $e;
    }
    try {
        $staff->restoreModerator((int)$valid['id'],$createdStaff,'first-unique-moderator-password');
        throw new RuntimeException('Restoration permitted reuse of suspended moderator password.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Restoration permitted reuse of suspended moderator password.') throw $e;
    }
    $staff->restoreModerator((int)$valid['id'],$createdStaff,'fresh-restored-staff-password');
    if ($auth->staffSessionEpoch($createdStaff)!==$firstEpoch+2
        || $auth->verifyCredentials('staff.managed@example.test','first-unique-moderator-password')!==null
        || $auth->verifyCredentials('staff.managed@example.test','fresh-restored-staff-password')===null
        || array_column($staff->recentActions((int)$valid['id']),'action')!==['restored','suspended','created']) {
        throw new RuntimeException('Restoring moderator failed to rotate epoch/password/audit.');
    }
    try {
        $staff->changeOwnPassword($createdStaff,'incorrect-old-password','alternate-strong-password');
        throw new RuntimeException('Moderator accepted a wrong existing password.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Moderator accepted a wrong existing password.') throw $e;
    }
    $staff->changeOwnPassword($createdStaff,'fresh-restored-staff-password','new-personal-staff-password');
    if ($auth->staffSessionEpoch($createdStaff)!==$firstEpoch+3
        || $auth->verifyCredentials('staff.managed@example.test','fresh-restored-staff-password')!==null
        || $auth->verifyCredentials('staff.managed@example.test','new-personal-staff-password')===null
        || $staff->recentActions((int)$valid['id'])[0]['action']!=='password_changed') {
        throw new RuntimeException('Moderator self-service rotation failed to revoke credentials or audit.');
    }
    try {
        $staff->changeOwnPassword((int)$valid['id'],'super-secret-example-password','admin-role-must-not-change');
        throw new RuntimeException('Moderator password change method touched an administrator.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Moderator password change method touched an administrator.') throw $e;
    }
    try {
        $staff->createModerator($createdStaff,'unauthorized.staff@example.test','unauthorized-test-password');
        throw new RuntimeException('Restricted moderator created another staff account.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Restricted moderator created another staff account.') throw $e;
    }
    MemberEmailSchema::migrate($pdo);
    MemberEmailSchema::migrate($pdo);
    CreatorSchema::migrate($pdo);
    CreatorSchema::migrate($pdo);
    CreatorReviewSchema::migrate($pdo);
    CreatorReviewSchema::migrate($pdo);
    CreatorFeedbackSchema::migrate($pdo);
    CreatorFeedbackSchema::migrate($pdo);
    PasswordRecoverySchema::migrate($pdo);
    PasswordRecoverySchema::migrate($pdo);
    $repo = new PageRepository($pdo);
    $homepage=(array)$pdo->query("SELECT id,revision FROM ac_pages WHERE slug='home'")->fetch(PDO::FETCH_ASSOC);
    $homeId=(int)($homepage['id']??0);
    $homeRevision=(int)($homepage['revision']??0);
    if ($homeId<1 || $homeRevision<1) throw new RuntimeException('No versioned homepage to protect.');
    foreach ([
        static fn()=> $repo->save($homeId,'renamed-home','Unintended','Bad','published',$homeRevision),
        static fn()=> $repo->save(0,'home','Another homepage','Bad','published'),
        static fn()=> $repo->duplicateAsDraft($homeId,$homeRevision,'home'),
    ] as $operation) {
        try {
            $operation();
            throw new RuntimeException('Reserved homepage slug mutation was accepted.');
        } catch (RuntimeException $e) {
            if ($e->getMessage()==='Reserved homepage slug mutation was accepted.') throw $e;
        }
    }
    $afterHome=$repo->byId($homeId);
    if ($afterHome===null || $afterHome['slug']!=='home'
        || (int)$afterHome['revision']!==$homeRevision) {
        throw new RuntimeException('Denied homepage rename modified protected route.');
    }
    if ($repo->published('home') !== null) {
        throw new RuntimeException('Default homepage unexpectedly public before review.');
    }
    $repo->save(0, 'about', 'About the club', "A safe welcome\nNo HTML", 'published');
    if (($repo->published('about')['title'] ?? '') !== 'About the club') {
        throw new RuntimeException('Public-page publishing failed.');
    }
    $aboutId=(int)$pdo->query("SELECT id FROM ac_pages WHERE slug='about'")->fetchColumn();
    try {
        $repo->save($aboutId,'home','Another page impersonating home','Bad','draft',1);
        throw new RuntimeException('Secondary page took the homepage slug.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Secondary page took the homepage slug.') throw $e;
    }
    $aboutRevision=(int)($repo->byId($aboutId)['revision']??0);
    if ($aboutRevision!==1) throw new RuntimeException('New pages must start at revision one.');
    $repo->save($aboutId,'about','About the club','Updated welcome','draft',$aboutRevision);
    if (($repo->byId($aboutId)['revision']??null)!=2 || $repo->published('about')!==null) {
        throw new RuntimeException('Revised page did not persist a safe draft transition.');
    }
    foreach ([
        ['stale revision',1],
        ['missing revision',null],
    ] as [$label,$revision]) {
        try {
            $repo->save($aboutId,'about','Overwritten private text','Stale attempt','published',$revision);
            throw new RuntimeException($label.' silently overwrote newer page state.');
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(),'silently overwrote')) throw $e;
        }
    }
    if (($repo->byId($aboutId)['title']??'')!=='About the club'
        || $repo->published('about')!==null) {
        throw new RuntimeException('Stale or missing page revision changed the website.');
    }
    $repo->save($aboutId,'about','About the club',"A safe welcome\nNo HTML",'published',2);
    if (($repo->byId($aboutId)['revision']??null)!=3 || $repo->published('about')===null) {
        throw new RuntimeException('Fresh revision did not restore public plain-text page.');
    }
    // Hiding a menu link never changes published/direct URL availability;
    // Design Studio edits must preserve independent navigation flags.
    $repo->save($aboutId,'about','About the club','Public but unlisted',
        'published',3,false,9);
    $nav=$repo->publishedMenu();
    if ($repo->published('about')===null
        || in_array('about',array_column($nav,'slug'),true)) {
        throw new RuntimeException('Unlisted page became private or leaked into the menu.');
    }
    $page=$repo->byId($aboutId);
    if ((int)$page['nav_position']!==9 || (int)$page['show_in_menu']!==0) {
        throw new RuntimeException('Hidden menu selection or order was not saved.');
    }
    $repo->save($aboutId,'about','About the club',"A safe welcome\nNo HTML",
        'published',4,true,2);
    if (!in_array('about',array_column($repo->publishedMenu(),'slug'),true)) {
        throw new RuntimeException('Reenabled published page missing from menu.');
    }
    // A local owner with more than 50 pages must be able to reach every
    // saved draft from the authenticated list; forged cursors fail closed.
    for ($n=0;$n<51;$n++) {
        $repo->save(0,sprintf('page-%02d',$n),'Saved test page '.$n,
            'Only safe private draft text','draft');
    }
    $searchOne=$repo->pageList(null,'Saved test page 0');
    if (count($searchOne['pages'])!==1 || $searchOne['next']!==null
        || $searchOne['pages'][0]['title']!=='Saved test page 0') {
        throw new RuntimeException('Literal owner search did not match exactly one page.');
    }
    $draftOnly=$repo->pageList(null,null,'draft');
    $publishedOnly=$repo->pageList(null,null,'published');
    if (count($draftOnly['pages'])!==50 || $draftOnly['next']===null
        || count($publishedOnly['pages'])!==1
        || $publishedOnly['pages'][0]['slug']!=='about') {
        throw new RuntimeException('Page status filter omitted drafts or included wrong public pages.');
    }
    try {
        $repo->pageList(null,null,'approved');
        throw new RuntimeException('Unsupported page approval filter accepted.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Unsupported page approval filter accepted.') throw $e;
    }
    $searchMany=$repo->pageList(null,'Saved test page');
    $searchTail=$repo->pageList($searchMany['next'],'Saved test page');
    if (count($searchMany['pages'])!==50 || $searchMany['next']===null
        || count($searchTail['pages'])!==1 || $searchTail['next']!==null) {
        throw new RuntimeException('Owner search paging skipped matching pages.');
    }
    if ($repo->pageList(null,'%_')['pages']!==[]) {
        throw new RuntimeException('Search wildcard metacharacters were expanded.');
    }
    foreach ([str_repeat('x',81),"\0",'word'."\0"] as $unsafeSearch) {
        try {
            $repo->pageList(null,$unsafeSearch);
            throw new RuntimeException('Invalid or control-bearing page search accepted.');
        } catch (RuntimeException $e) {
            if ($e->getMessage()==='Invalid or control-bearing page search accepted.') throw $e;
        }
    }
    $first=$repo->pageList();
    if (count($first['pages'])!==50 || $first['next']===null) {
        throw new RuntimeException('Page list omitted next-page navigation.');
    }
    $second=$repo->pageList($first['next']);
    $ids=array_merge(array_column($first['pages'],'id'),array_column($second['pages'],'id'));
    if (count($second['pages'])!==3 || $second['next']!==null
        || count(array_unique($ids))!==53
        || !in_array($aboutId,array_map('intval',$ids),true)) {
        throw new RuntimeException('Private page listing skipped or duplicated draft records.');
    }
    try {
        $repo->pageList(0);
        throw new RuntimeException('Invalid page cursor accepted.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Invalid page cursor accepted.') throw $e;
    }
    // Unpublished page duplication copies only safe text and is guarded by
    // source revision; no accidental public visibility or nav menu grant.
    $copyId=$repo->duplicateAsDraft($aboutId,5,'about-private-copy');
    $copy=$repo->byId($copyId);
    if (!is_array($copy) || $copy['status']!=='draft'
        || (int)$copy['revision']!==1 || (int)$copy['show_in_menu']!==0
        || $copy['title']!=='About the club'
        || $repo->published('about-private-copy')!==null) {
        throw new RuntimeException('Page duplicate unexpectedly published or altered content.');
    }
    $detail=$repo->historyDetail($aboutId,2);
    if ($detail===null || $detail['body']!=='Updated welcome'
        || (int)$detail['revision']!==2
        || $repo->historyDetail($copyId,2)!==null
        || $repo->historyDetail($aboutId,999)!==null) {
        throw new RuntimeException('Private page history details leaked or omitted prior text.');
    }
    $history=$repo->history($aboutId);
    if (count($history)!==5 || array_column($history,'revision')!==[5,4,3,2,1]
        || count($repo->history($copyId))!==1) {
        throw new RuntimeException('Versioned page saves or new private copies lost history.');
    }
    try {
        $repo->restoreTextAsDraft($aboutId,4,1);
        throw new RuntimeException('Stale page restore was accepted.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Stale page restore was accepted.') throw $e;
    }
    try {
        $repo->restoreTextAsDraft($aboutId,5,999);
        throw new RuntimeException('Missing historic revision restored.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Missing historic revision restored.') throw $e;
    }
    $restoredRevision=$repo->restoreTextAsDraft($aboutId,5,1);
    $restoredPage=$repo->byId($aboutId);
    if ($restoredRevision!==6 || !is_array($restoredPage)
        || (int)$restoredPage['revision']!==6
        || $restoredPage['title']!=='About the club'
        || $restoredPage['status']!=='draft'
        || (int)$restoredPage['show_in_menu']!==1
        || (int)$restoredPage['nav_position']!==2
        || $repo->published('about')!==null
        || count($repo->history($aboutId))!==6) {
        throw new RuntimeException('Restore reused published state, changed local menu or lost revision history.');
    }
    foreach ([[4,'stale-source-copy'],[5,'bad/slug']] as [$rev,$slug]) {
        try {
            $repo->duplicateAsDraft($aboutId,$rev,$slug);
            throw new RuntimeException('Unsafe page duplication was accepted.');
        } catch (RuntimeException $e) {
            if ($e->getMessage()==='Unsafe page duplication was accepted.') throw $e;
        }
    }
    MembershipSchema::migrate($pdo);
    MembershipSchema::migrate($pdo);
    $pdo->prepare("UPDATE ac_users SET status='suspended' WHERE id=?")->execute([$valid['id']]);
    if ($auth->verifyCredentials('owner@example.test','super-secret-example-password')!==null
        || $auth->authorizedById((int)$valid['id'])!==null) {
        throw new RuntimeException('Suspended administrator retained login/session authorization.');
    }
    $pdo->prepare("UPDATE ac_users SET status='active' WHERE id=?")->execute([$valid['id']]);
    if ($auth->authorizedById((int)$valid['id'])===null) {
        throw new RuntimeException('Reactivated test administrator could not reauthenticate.');
    }
    $pdo->prepare("INSERT INTO ac_users (email,password_hash,role,status,created_at)
        VALUES ('moderator@example.test',?,'moderator','active',UTC_TIMESTAMP())")
        ->execute([password_hash('strong-example-moderator-password',PASSWORD_DEFAULT)]);
    $moderatorId=(int)$pdo->lastInsertId();
    if ($auth->verifyCredentials('moderator@example.test','strong-example-moderator-password')['id']!==$moderatorId
        || $auth->authorizedById($moderatorId)['role']!=='moderator') {
        throw new RuntimeException('Restricted moderator credentials were denied.');
    }
    $tiers=new TierCatalog($pdo);
    try {
        $tiers->create('Moderator cannot change paid labels',$moderatorId);
        throw new RuntimeException('Moderator changed tier labels.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Moderator changed tier labels.') throw $e;
    }
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
    foreach (['Founders preview','FOUNDERS PREVIEW',"Trailing\x00"] as $name) {
        try {
            $tiers->create($name,(int)$valid['id']);
            throw new RuntimeException('Duplicate, case-variant or control-bearing tier was accepted.');
        } catch (RuntimeException $e) {
            if ($e->getMessage()==='Duplicate, case-variant or control-bearing tier was accepted.') throw $e;
        }
    }
    if (count($tiers->all())!==1) {
        throw new RuntimeException('Rejected membership labels changed the catalog.');
    }
    ModerationSchema::migrate($pdo);
    ModerationSchema::migrate($pdo);
    $moderatorRepo=new ModerationRepository($pdo);
    $moderatorDraft=$moderatorRepo->createDraft('Moderator review-only draft',$moderatorId);
    $q=$pdo->prepare('SELECT status,visibility,review_status FROM ac_content WHERE id=?');
    $q->execute([$moderatorDraft]);$modDraftState=$q->fetch(PDO::FETCH_ASSOC);
    if ($modDraftState['status']!=='draft' || $modDraftState['visibility']!=='private'
        || $modDraftState['review_status']!=='pending') {
        throw new RuntimeException('Moderator creation published private content.');
    }
    $reviewSummary=$moderatorRepo->privateDraftDetail($moderatorDraft);
    if (!is_array($reviewSummary) || $reviewSummary['content']['title']!=='Moderator review-only draft'
        || $reviewSummary['images']!==[] || $reviewSummary['performers']!==[]
        || $moderatorRepo->privateDraftDetail(99999999)!==null) {
        throw new RuntimeException('Read-only private draft review exposed unrelated content.');
    }
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
    // Owner-managed local creator tools: an existing confirmed member, not
    // platform signup, may only produce private text-planning drafts.
    $creators=new LocalCreators($pdo);
    try {
        $creators->provision($moderatorId,'newmember@example.test','Moderator cannot grant creators');
        throw new RuntimeException('Moderator granted local creator permissions.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Moderator granted local creator permissions.') throw $e;
    }
    try {
        $creators->provision((int)$valid['id'],'missing@example.test','Unknown member');
        throw new RuntimeException('Unknown member received creator permissions.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Unknown member received creator permissions.') throw $e;
    }
    $creatorId=$creators->provision((int)$valid['id'],'NEWMEMBER@example.test','Cajun <Creator>');
    $activeCreator=$creators->activeForMember((int)$member['id']);
    if ($creatorId<1 || !is_array($activeCreator) ||
        $activeCreator['display_name']!=='Cajun <Creator>' ||
        $accounts->byId((int)$member['id'])['status']!=='pending') {
        throw new RuntimeException('Local owner creator grant changed member status or failed to persist.');
    }
    try {
        $creators->provision((int)$valid['id'],'newmember@example.test','Duplicate creator');
        throw new RuntimeException('Duplicate creator grant accepted.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Duplicate creator grant accepted.') throw $e;
    }
    $originalContentCount=(int)$pdo->query('SELECT COUNT(*) FROM ac_content')->fetchColumn();
    $draftId=$creators->saveDraft((int)$member['id'],null,null,
        'My private <draft>','A private text-only creator draft, never a public gallery.');
    $drafts=$creators->ownDrafts((int)$member['id']);
    if ($draftId<1 || count($drafts)!==1 || $drafts[0]['status']!=='private'
        || $drafts[0]['title']!=='My private <draft>'
        || (int)$pdo->query('SELECT COUNT(*) FROM ac_content')->fetchColumn()!==$originalContentCount) {
        throw new RuntimeException('Creator planning leaked into public moderation/media content.');
    }
    try {
        $creators->saveDraft((int)$member['id'],$draftId,999,'Stale draft',
            'A concurrent edit must not be accepted into the workspace.');
        throw new RuntimeException('Stale creator draft edit accepted.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Stale creator draft edit accepted.') throw $e;
    }
    $creators->saveDraft((int)$member['id'],$draftId,(int)$drafts[0]['revision'],
        'Updated private draft','Updated private text planning, still unpublished.');
    $accounts->register('secondcreator@example.test','long-example-second-password');
    $secondMember=$accounts->verify('secondcreator@example.test','long-example-second-password');
    if (!is_array($secondMember)) throw new RuntimeException('Second member fixture failed.');
    try {
        $creators->provision((int)$valid['id'],'secondcreator@example.test','Unconfirmed creator');
        throw new RuntimeException('Unconfirmed email was accepted for creator access.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Unconfirmed email was accepted for creator access.') throw $e;
    }
    $secondToken=$emailConfirmations->issue((int)$secondMember['id']);
    if (!$emailConfirmations->confirm($secondToken)) throw new RuntimeException('Second member confirmation failed.');
    $secondCreatorId=$creators->provision((int)$valid['id'],'secondcreator@example.test','Second creator');
    if ($creators->ownDrafts((int)$secondMember['id'])!==[]) {
        throw new RuntimeException('One creator could read another creator\'s private drafts.');
    }
    try {
        $creators->saveDraft((int)$secondMember['id'],$draftId,1,'Cross-account edit',
            'This is an unauthorized creator draft edit attempt.');
        throw new RuntimeException('Cross-creator draft edit accepted.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Cross-creator draft edit accepted.') throw $e;
    }
    $ownerPlans=$creators->privatePlansForOwner((int)$valid['id'],$creatorId);
    if (!is_array($ownerPlans) || count($ownerPlans['drafts'])!==1
        || $ownerPlans['drafts'][0]['title']!=='Updated private draft'
        || $creators->privatePlansForOwner((int)$valid['id'],99999999)!==null) {
        throw new RuntimeException('Local owner private draft review missing or exposed wrong content.');
    }
    try {
        $creators->privatePlansForOwner($moderatorId,$creatorId);
        throw new RuntimeException('Moderator read privileged owner draft plans.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Moderator read privileged owner draft plans.') throw $e;
    }
    $olderManaged=$creators->managed((int)$valid['id'],$creatorId+1);
    $tooOldManaged=$creators->managed((int)$valid['id'],$creatorId);
    if (count($olderManaged)!==1 || (int)$olderManaged[0]['id']!==$creatorId
        || $tooOldManaged!==[]) {
        throw new RuntimeException('Local creator manager pagination leaked older/other records.');
    }
    $managed=$creators->managed((int)$valid['id']);
    if (count($managed)!==2 || (int)$managed[1]['id']!==$creatorId ||
        (int)$managed[1]['draft_count']!==1) {
        throw new RuntimeException('Owner local creator list and private draft counters wrong.');
    }
    try {
        $creators->managed($moderatorId);
        throw new RuntimeException('Moderator read privileged creator management table.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Moderator read privileged creator management table.') throw $e;
    }
    $creators->setStatus((int)$valid['id'],$creatorId,1,'suspend');
    if ($creators->activeForMember((int)$member['id'])!==null ||
        $creators->ownDrafts((int)$member['id'])!==[]) {
        throw new RuntimeException('Suspension did not revoke private creator workspace.');
    }
    try {
        $creators->saveDraft((int)$member['id'],null,null,'Blocked while suspended',
            'Suspended creators must have no access to private draft writes.');
        throw new RuntimeException('Suspended creator wrote a private draft.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Suspended creator wrote a private draft.') throw $e;
    }
    try {
        $creators->setStatus((int)$valid['id'],$creatorId,1,'restore');
        throw new RuntimeException('Stale creator restoration accepted.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Stale creator restoration accepted.') throw $e;
    }
    $creators->setStatus((int)$valid['id'],$creatorId,2,'restore');
    if ($creators->activeForMember((int)$member['id'])===null ||
        count($creators->ownDrafts((int)$member['id']))!==1 ||
        array_column($creators->audit((int)$valid['id']),'action')[0]!=='restored') {
        throw new RuntimeException('Local creator restoration, draft isolation or audit failed.');
    }
    try {
        $creators->rename((int)$valid['id'],$creatorId,2,'An outdated creator name');
        throw new RuntimeException('Stale creator display name overwrite accepted.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Stale creator display name overwrite accepted.') throw $e;
    }
    try {
        $creators->rename($moderatorId,$creatorId,3,'Unauthorized rename');
        throw new RuntimeException('Moderator changed a creator display name.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Moderator changed a creator display name.') throw $e;
    }
    $creators->rename((int)$valid['id'],$creatorId,3,'Renamed <Creator>');
    $renamedCreator=$creators->activeForMember((int)$member['id']);
    if ($renamedCreator['display_name']!=='Renamed <Creator>' ||
        count($creators->ownDrafts((int)$member['id']))!==1 ||
        $creators->audit((int)$valid['id'])[0]['action']!=='renamed') {
        throw new RuntimeException('Creator rename changed privileges/drafts or lacked local audit.');
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM ac_age_verifications')->fetchColumn()!==0) {
        throw new RuntimeException('Local creator grant pretended to verify age.');
    }
    // Strictly private text review. Moderators may request changes or
    // reject; there is NO approve/publish transition or media insertion.
    $textReview=new CreatorDraftReview($pdo);
    $beforeContent=(int)$pdo->query('SELECT COUNT(*) FROM ac_content')->fetchColumn();
    if ($textReview->queue($moderatorId)!==[]) {
        throw new RuntimeException('Unsubmitted private creator plan leaked to review queue.');
    }
    try {
        $textReview->queue((int)$member['id']);
        throw new RuntimeException('Member could read moderator text-review queue.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Member could read moderator text-review queue.') throw $e;
    }
    $ready=$creators->ownDrafts((int)$member['id'])[0];
    try {
        $textReview->submit((int)$secondMember['id'],$draftId,(int)$ready['revision']);
        throw new RuntimeException('Other creator submitted another account\'s private plan.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Other creator submitted another account\'s private plan.') throw $e;
    }
    $textReview->submit((int)$member['id'],$draftId,(int)$ready['revision']);
    $submitted=$creators->ownDrafts((int)$member['id'])[0];
    $queue=$textReview->queue($moderatorId);
    if ($submitted['status']!=='submitted' || count($queue)!==1
        || (int)$queue[0]['id']!==$draftId || (int)$queue[0]['revision']!==(int)$submitted['revision']) {
        throw new RuntimeException('Submitted private text plan not locked for local review.');
    }
    try {
        $creators->saveDraft((int)$member['id'],$draftId,(int)$submitted['revision'],
            'Forbidden edit while submitted','This edit must be denied while the private plan is under review.');
        throw new RuntimeException('Creator edited submitted private text.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Creator edited submitted private text.') throw $e;
    }
    foreach (['approved','published','accepted'] as $unsafe) {
        try {
            $textReview->decide($moderatorId,$draftId,(int)$submitted['revision'],
                $unsafe,'Moderator cannot approve or publish this text-only plan.');
            throw new RuntimeException('Moderator transitioned private creator text to public/approved.');
        } catch (RuntimeException $e) {
            if ($e->getMessage()==='Moderator transitioned private creator text to public/approved.') throw $e;
        }
    }
    try {
        $textReview->decide((int)$member['id'],$draftId,(int)$submitted['revision'],
            'changes','Member may not review their own submitted text plan.');
        throw new RuntimeException('Member made unauthorized text-review decision.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Member made unauthorized text-review decision.') throw $e;
    }
    $textReview->decide($moderatorId,$draftId,(int)$submitted['revision'],
        'changes','Please clarify the private story and exclude all identity evidence.');
    $rework=$creators->ownDrafts((int)$member['id'])[0];
    if ($rework['status']!=='changes'
        || !str_contains((string)$rework['review_note'],'clarify')
        || $textReview->queue($moderatorId)!==[]) {
        throw new RuntimeException('Moderator private revision feedback unavailable to creator.');
    }
    try {
        $textReview->submit((int)$member['id'],$draftId,(int)$rework['revision']);
        throw new RuntimeException('Creator resubmitted requested changes without editing first.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Creator resubmitted requested changes without editing first.') throw $e;
    }
    $creators->saveDraft((int)$member['id'],$draftId,(int)$rework['revision'],
        'Revised private story','A revised private summary with no evidence, media or publication.');
    $feedbackRepo=new CreatorFeedbackHistory($pdo);
    $retained=$feedbackRepo->forMember((int)$member['id']);
    if (count($retained)!==1 || $retained[0]['decision']!=='changes'
        || !str_contains((string)$retained[0]['feedback'],'clarify')
        || $feedbackRepo->forMember((int)$secondMember['id'])!==[]) {
        throw new RuntimeException('Review history was lost on edit or leaked to unrelated member.');
    }
    try {
        $feedbackRepo->forOwner($moderatorId,$creatorId);
        throw new RuntimeException('Moderator accessed protected owner feedback history.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Moderator accessed protected owner feedback history.') throw $e;
    }
    $revised=$creators->ownDrafts((int)$member['id'])[0];
    if ($revised['status']!=='private' || (string)$revised['review_note']!=='') {
        throw new RuntimeException('Revision failed to clear obsolete moderator feedback.');
    }
    $textReview->submit((int)$member['id'],$draftId,(int)$revised['revision']);
    $resubmitted=$creators->ownDrafts((int)$member['id'])[0];
    try {
        $textReview->decide($moderatorId,$draftId,(int)$submitted['revision'],
            'rejected','A stale moderation decision must not overwrite a newer submission.');
        throw new RuntimeException('Stale moderator review revision was accepted.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Stale moderator review revision was accepted.') throw $e;
    }
    $textReview->decide($moderatorId,$draftId,(int)$resubmitted['revision'],
        'rejected','Rejected because required rights and documentation are not ready.');
    $rejected=$creators->ownDrafts((int)$member['id'])[0];
    $allFeedback=$feedbackRepo->forMember((int)$member['id']);
    $ownerFeedback=$feedbackRepo->forOwner((int)$valid['id'],$creatorId);
    if (count($allFeedback)!==2 || count($ownerFeedback)!==2
        || $allFeedback[0]['decision']!=='rejected'
        || $allFeedback[1]['decision']!=='changes'
        || !str_contains((string)$allFeedback[1]['feedback'],'clarify')
        || (int)$allFeedback[0]['submitted_revision']!==(int)$resubmitted['revision']
        || (int)$ownerFeedback[0]['reviewer_id']!==$moderatorId) {
        throw new RuntimeException('Revision-bound private feedback timeline was incomplete.');
    }
    if ($rejected['status']!=='rejected' || !str_contains((string)$rejected['review_note'],'Rejected')
        || $textReview->queue($moderatorId)!==[]) {
        throw new RuntimeException('Rejected private text plan stayed in queue or lost feedback.');
    }
    try {
        $creators->saveDraft((int)$member['id'],$draftId,(int)$rejected['revision'],
            'Attempted rejected draft edit','Rejected plans may not be resubmitted by the creator.');
        throw new RuntimeException('Rejected private plan was edited without owner intervention.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Rejected private plan was edited without owner intervention.') throw $e;
    }
    $q=$pdo->prepare('SELECT action FROM ac_creator_draft_events WHERE draft_id=? ORDER BY id');
    $q->execute([$draftId]);
    if ($q->fetchAll(PDO::FETCH_COLUMN)!==['submitted','changes','submitted','rejected']
        || (int)$pdo->query('SELECT COUNT(*) FROM ac_content')->fetchColumn()!==$beforeContent
        || (int)$pdo->query('SELECT COUNT(*) FROM ac_age_verifications')->fetchColumn()!==0) {
        throw new RuntimeException('Text review mutated public content, verification or audit history.');
    }
    $ownerAfterReview=$creators->privatePlansForOwner((int)$valid['id'],$creatorId);
    if ($ownerAfterReview['drafts'][0]['status']!=='rejected'
        || !str_contains((string)$ownerAfterReview['drafts'][0]['review_note'],'Rejected')
        || count($ownerAfterReview['events'])!==4
        || $ownerAfterReview['events'][0]['action']!=='rejected') {
        throw new RuntimeException('Local owner cannot inspect retained rejected text plans.');
    }
    // A creator can withdraw only an undecided submission, preserving a
    // numeric audit event and restoring text editing without publication.
    $withdrawId=$creators->saveDraft((int)$member['id'],null,null,
        'Withdraw test plan','This private text plan should remain editable after withdrawal.');
    $textReview->submit((int)$member['id'],$withdrawId,1);
    if (count($textReview->queue($moderatorId))!==1) {
        throw new RuntimeException('Submitted withdrawal fixture was not privately queued.');
    }
    try {
        $textReview->withdraw((int)$secondMember['id'],$withdrawId,2);
        throw new RuntimeException('Another creator withdrew an unrelated text plan.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Another creator withdrew an unrelated text plan.') throw $e;
    }
    $textReview->withdraw((int)$member['id'],$withdrawId,2);
    $withdrawn=$creators->ownDrafts((int)$member['id'])[0];
    $eventQuery=$pdo->prepare('SELECT action FROM ac_creator_draft_events WHERE draft_id=? ORDER BY id');
    $eventQuery->execute([$withdrawId]);
    if ($withdrawn['status']!=='private' || (int)$withdrawn['revision']!==3
        || $eventQuery->fetchAll(PDO::FETCH_COLUMN)!==['submitted','withdrawn']
        || $textReview->queue($moderatorId)!==[]) {
        throw new RuntimeException('Withdrawing pending text failed to restore privacy or preserve audit.');
    }
    try {
        $textReview->withdraw((int)$member['id'],$withdrawId,3);
        throw new RuntimeException('Repeated withdrawal succeeded.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Repeated withdrawal succeeded.') throw $e;
    }
    try {
        $textReview->decide($moderatorId,$withdrawId,2,
            'changes','Moderator cannot review a withdrawn private plan.');
        throw new RuntimeException('Moderator reviewed a withdrawn plan.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Moderator reviewed a withdrawn plan.') throw $e;
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM ac_content')->fetchColumn()!==$beforeContent) {
        throw new RuntimeException('Withdrawn text submission mutated public content.');
    }
    // Archiving a private, unsent plan is reversible but keeps the
    // audit and consumes a retained draft slot (never silent deletion).
    try {
        $creators->changeDraftArchive((int)$secondMember['id'],$withdrawId,3,'archive');
        throw new RuntimeException('Other creator archived a draft belonging to this account.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Other creator archived a draft belonging to this account.') throw $e;
    }
    $creators->changeDraftArchive((int)$member['id'],$withdrawId,3,'archive');
    $archived=$creators->ownDrafts((int)$member['id'])[0];
    if ($archived['status']!=='archived' || (int)$archived['revision']!==4
        || $creators->privatePlansForOwner((int)$valid['id'],$creatorId)['drafts'][0]['status']!=='archived') {
        throw new RuntimeException('Archiving an unsent private plan removed it or lost revision state.');
    }
    try {
        $textReview->submit((int)$member['id'],$withdrawId,4);
        throw new RuntimeException('Archived text plan was submitted for moderation.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Archived text plan was submitted for moderation.') throw $e;
    }
    try {
        $creators->saveDraft((int)$member['id'],$withdrawId,4,
            'Tampered with archived plan','Archived plan content must stay immutable until restoration.');
        throw new RuntimeException('Archived text plan could be edited.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Archived text plan could be edited.') throw $e;
    }
    try {
        $creators->changeDraftArchive((int)$member['id'],$withdrawId,3,'restore');
        throw new RuntimeException('Stale archive restore succeeded.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Stale archive restore succeeded.') throw $e;
    }
    $creators->changeDraftArchive((int)$member['id'],$withdrawId,4,'restore');
    $restoredPlan=$creators->ownDrafts((int)$member['id'])[0];
    $eventQuery->execute([$withdrawId]);
    if ($restoredPlan['status']!=='private' || (int)$restoredPlan['revision']!==5
        || $eventQuery->fetchAll(PDO::FETCH_COLUMN)!==['submitted','withdrawn','archived','restored']
        || (int)$pdo->query('SELECT COUNT(*) FROM ac_content')->fetchColumn()!==$beforeContent) {
        throw new RuntimeException('Private plan restoration lost text, event history or public isolation.');
    }
    try {
        $creators->changeDraftArchive((int)$member['id'],$draftId,7,'archive');
        throw new RuntimeException('Rejected plan could be archived to evade review.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Rejected plan could be archived to evade review.') throw $e;
    }
    // Verify m14 -> m15 upgrade preserves ONLY feedback with an actual
    // matching last review event, never inventing a reviewer for corrupt data.
    // This disposable test deliberately writes an old-style m14 decision
    // directly because the current live decision path already requires m15.
    $legacyDraftId=$creators->saveDraft((int)$secondMember['id'],null,null,
        'Pre-m15 text review','An older local plan whose current feedback must survive upgrade.');
    $textReview->submit((int)$secondMember['id'],$legacyDraftId,1);
    $pdo->prepare("UPDATE ac_creator_drafts
        SET status='changes',review_note=?,revision=revision+1,updated_at=UTC_TIMESTAMP()
        WHERE id=?")->execute(['Original m14 review feedback with verified provenance.',$legacyDraftId]);
    $pdo->prepare("INSERT INTO ac_creator_draft_events(draft_id,actor_id,action,created_at)
        VALUES (?,?,'changes',UTC_TIMESTAMP())")->execute([$legacyDraftId,$moderatorId]);
    CreatorFeedbackSchema::migrate($pdo);
    CreatorFeedbackSchema::migrate($pdo);
    $legacyFeedback=$feedbackRepo->forMember((int)$secondMember['id']);
    if (count($legacyFeedback)!==1 || (int)$legacyFeedback[0]['draft_id']!==$legacyDraftId
        || (int)$legacyFeedback[0]['submitted_revision']!==2
        || $legacyFeedback[0]['decision']!=='changes'
        || !str_contains((string)$legacyFeedback[0]['feedback'],'Original m14')
        || (int)$feedbackRepo->forOwner((int)$valid['id'],$secondCreatorId)[0]['reviewer_id']!==$moderatorId) {
        throw new RuntimeException('Repeatable m15 upgrade lost event-backed m14 current feedback.');
    }
    $unprovenDraftId=$creators->saveDraft((int)$secondMember['id'],null,null,
        'No verified reviewer','An old draft with no matching review event cannot be attributed.');
    $textReview->submit((int)$secondMember['id'],$unprovenDraftId,1);
    $pdo->prepare("UPDATE ac_creator_drafts
        SET status='changes',review_note=?,revision=revision+1,updated_at=UTC_TIMESTAMP()
        WHERE id=?")->execute(['A note without a matching reviewer event is not backfilled.',$unprovenDraftId]);
    CreatorFeedbackSchema::migrate($pdo);
    if (count($feedbackRepo->forMember((int)$secondMember['id']))!==1) {
        throw new RuntimeException('Creator migration guessed unverified historic reviewer attribution.');
    }
    // More than 50 submitted local text plans must remain reachable in
    // ordered, role-protected pages. A second creator avoids the 50-private
    // drafts-per-creator cap while testing site-wide moderation throughput.
    $queueBeforeCount=(int)$pdo->query("SELECT COUNT(*) FROM ac_creator_drafts
        WHERE status='submitted'")->fetchColumn();
    if ($queueBeforeCount!==0) throw new RuntimeException('Creator queue fixture not empty before pagination test.');
    $createdQueueIds=[];
    for ($i=1;$i<=51;$i++) {
        $creatorMember=$i%2===0?(int)$member['id']:(int)$secondMember['id'];
        $qId=$creators->saveDraft($creatorMember,null,null,
            'Review queue item '.str_pad((string)$i,3,'0',STR_PAD_LEFT),
            'Private pending text-plan pagination regression fixture '.$i.'.');
        $textReview->submit($creatorMember,$qId,1);
        $createdQueueIds[]=$qId;
    }
    $firstPage=$textReview->queuePage($moderatorId);
    if (count($firstPage['drafts'])!==50 || !is_string($firstPage['next'])
        || $firstPage['next']==='') {
        throw new RuntimeException('First local creator text-review page lacks bounded next cursor.');
    }
    $secondPage=$textReview->queuePage($moderatorId,$firstPage['next']);
    $seenIds=array_merge(
        array_map('intval',array_column($firstPage['drafts'],'id')),
        array_map('intval',array_column($secondPage['drafts'],'id'))
    );
    sort($createdQueueIds);sort($seenIds);
    if (count($secondPage['drafts'])!==1 || $secondPage['next']!==null
        || $createdQueueIds!==$seenIds) {
        throw new RuntimeException('Review queue pagination skipped, leaked or duplicated creator plans.');
    }
    foreach (['bad*cursor',rtrim(strtr(base64_encode('2026-99-99 99:99:99|1'),
             '+/','-_'),'='),str_repeat('a',101)] as $badCursor) {
        try {
            $textReview->queuePage($moderatorId,$badCursor);
            throw new RuntimeException('Malformed private review cursor accepted.');
        } catch (RuntimeException $e) {
            if ($e->getMessage()==='Malformed private review cursor accepted.') throw $e;
        }
    }
    try {
        $textReview->queuePage((int)$member['id'],$firstPage['next']);
        throw new RuntimeException('Member bypassed moderation permission by supplying a cursor.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Member bypassed moderation permission by supplying a cursor.') throw $e;
    }
    if ((int)$pdo->query('SELECT COUNT(*) FROM ac_content')->fetchColumn()!==$beforeContent) {
        throw new RuntimeException('Private moderation queue paging affected public content.');
    }
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
    $performerPage=(new ModerationRepository($pdo))->performerQueuePage();
    if (!is_array($performerPage['items']) || $performerPage['next']!==null) {
        throw new RuntimeException('Performer summary paging returned invalid shape.');
    }
    try {
        (new ModerationRepository($pdo))->performerQueuePage(0);
        throw new RuntimeException('Invalid performer cursor accepted.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Invalid performer cursor accepted.') throw $e;
    }
    $moderationPage=(new ModerationRepository($pdo))->contentQueuePage();
    if (count($moderationPage['items'])<1
        || (int)$moderationPage['items'][0]['id']<1) {
        throw new RuntimeException('Private moderation queue page failed to return staged record.');
    }
    try {
        (new ModerationRepository($pdo))->contentQueuePage(0);
        throw new RuntimeException('Invalid moderation cursor was accepted.');
    } catch (RuntimeException $e) {
        if ($e->getMessage()==='Invalid moderation cursor was accepted.') throw $e;
    }
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
    $reportPage=$moderation->reportQueuePage();
    if ($reportPage['items']===[] || $reportPage['next']!==null
        || (int)$reportPage['items'][0]['id']!==$reportId) {
        throw new RuntimeException('Bounded report queue omitted known local report.');
    }
    foreach (['0:0','3:999','1:not-a-number','1:99999999999999999999999999'] as $badReportCursor) {
        try {
            $moderation->reportQueuePage($badReportCursor);
            throw new RuntimeException('Invalid report cursor was accepted.');
        } catch (RuntimeException $e) {
            if ($e->getMessage()==='Invalid report cursor was accepted.') throw $e;
        }
    }
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
    foreach (['ac_page_history','ac_creator_feedback_history','ac_creator_draft_events','ac_creator_actions','ac_creator_drafts','ac_creator_profiles','ac_staff_actions','ac_admin_mfa','ac_design_settings','ac_design_assets','ac_verification_receipts', 'ac_moderation_actions', 'ac_media', 'ac_content_reports', 'ac_performer_records', 'ac_memberships', 'ac_content', 'ac_membership_tiers', 'ac_age_verifications', 'ac_member_password_resets', 'ac_member_email_confirmations', 'ac_pages', 'ac_installations', 'ac_settings', 'ac_users', 'ac_schema_migrations'] as $table) {
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
