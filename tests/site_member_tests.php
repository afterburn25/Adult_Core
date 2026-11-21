<?php

declare(strict_types=1);
require dirname(__DIR__) . '/src/Support/Autoload.php';
use AdultCore\Website\PageRepository;
use AdultCore\Website\PageRevisionSchema;
use AdultCore\Website\PageNavigationSchema;
use AdultCore\Website\SiteIdentity;
use AdultCore\Website\SiteIdentitySchema;
use AdultCore\UI\Layout;
use AdultCore\Member\MemberAccounts;
use AdultCore\Member\MemberDirectory;
use AdultCore\Membership\MemberLibrary;
use AdultCore\Member\MemberSession;
use AdultCore\Member\EmailDelivery;
use AdultCore\Member\MemberEmailSchema;
use AdultCore\Member\PasswordRecovery;
use AdultCore\Member\MemberPasswordChange;
use AdultCore\Member\PasswordRecoverySchema;
$checks = 0;
function claim(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException($name); $checks++; }
PageRepository::validate('home','Hello','Safe plain text','published');claim(true,'Valid home page');
claim(SiteIdentitySchema::VERSION==='m20_local_site_identity_v1',
    'Local brand schema version is separate from vendor licensing');
claim(SiteIdentity::validateName(' Local <Brand> & More ')==='Local <Brand> & More',
    'Local UTF-8 branding accepts punctuation that public renderer escapes');
foreach (['x','  ',str_repeat('X',121),"Invalid\nName","Bad\0Name",'Trailing'."\0"] as $badName) {
    try {
        SiteIdentity::validateName($badName);
        throw new RuntimeException('Invalid local branding accepted.');
    } catch (RuntimeException $e) {
        claim($e->getMessage()!=='Invalid local branding accepted.','Unsafe brand denied');
    }
}
claim(PageRevisionSchema::VERSION==='m16_page_revisions_v1','Page revision migration has distinct version');
claim(PageNavigationSchema::VERSION==='m17_page_navigation_v1','Navigation migration has distinct version');
PageRepository::validateNavigation(false,0);
PageRepository::validateNavigation(true,999);
foreach ([-1,1000] as $position) {
    try {
        PageRepository::validateNavigation(true,$position);
        throw new RuntimeException('Out-of-range menu order accepted.');
    } catch (RuntimeException $e) {
        claim($e->getMessage()!=='Out-of-range menu order accepted.','Bad menu order rejected');
    }
}
PageRepository::validate('about-us','About us','A','draft');claim(true,'Valid slug');
// Two published pages may share a title. Active navigation must follow the
// route slug, never the title, even on a protected owner preview.
$duplicateTitleMenu=[
    ['slug'=>'one','title'=>'Shared name'],
    ['slug'=>'two','title'=>'Shared name'],
];
$activeNav=Layout::site('Shared name','Safe body',$duplicateTitleMenu,'Local site',false,[],false,'two');
claim(substr_count($activeNav,'aria-current="page"')===1
    && str_contains($activeNav,'href="/p/two" aria-current="page"')
    && !str_contains($activeNav,'href="/p/one" aria-current="page"'),
    'Duplicate titles cannot create two active navigation links');
$unboundNav=Layout::site('Shared name','Safe body',$duplicateTitleMenu,'Local site');
claim(!str_contains($unboundNav,'aria-current="page"'),
    'Title matches alone never mark a public route active');
foreach ([['UPPER','Title','Body','draft'],['bad/slash','Title','Body','draft'],['a','Title','Body','hidden'],['a','','Body','draft'],['a',str_repeat('x',191),'Body','draft'],['a','Title',str_repeat('x',30001),'draft']] as $case) {
    try { PageRepository::validate(...$case); throw new RuntimeException('Invalid page accepted'); }
    catch (RuntimeException $e) { claim($e->getMessage() !== 'Invalid page accepted', 'Page input rejected'); }
}
MemberAccounts::validate('user@example.com','a-long-strong-password');claim(true,'Valid member details');
$memberDirectorySource=(string)file_get_contents(dirname(__DIR__).'/src/Member/MemberDirectory.php');
claim(str_contains($memberDirectorySource,"WHERE role='member'")
    && str_contains($memberDirectorySource,'ORDER BY id DESC LIMIT 51')
    && !str_contains($memberDirectorySource,'password_hash')
    && !str_contains($memberDirectorySource,'ac_age_verifications')
    && !str_contains($memberDirectorySource,'ac_memberships'),
    'Read-only member directory returns no credentials or entitlement records');
$emptyLibrary=MemberLibrary::render([]);
claim(str_contains($emptyLibrary,'No content is currently available')
    && !str_contains($emptyLibrary,'<img'), 'Unverified member library is neutral and text-only');
$escapedLibrary=MemberLibrary::render([['id'=>12,'title'=>'<script>secret</script>','visibility'=>'paid']]);
claim(!str_contains($escapedLibrary,'<script>') && str_contains($escapedLibrary,'&lt;script&gt;')
    && str_contains($escapedLibrary,'Paid membership')
    && str_contains($escapedLibrary,'href="/members/library/12"')
    && !str_contains($escapedLibrary,'/media/'),
    'Private library escapes titles and links only to guarded gallery details');
$detailPage=MemberLibrary::renderDetail([
    'id'=>12,'title'=>'<img src=x onerror=alert(1)>','visibility'=>'paid',
    'media'=>[
        ['id'=>19,'mime_type'=>'image/jpeg'],
        ['id'=>20,'mime_type'=>'text/html'],
        ['id'=>21,'mime_type'=>'image/svg+xml'],
    ],
]);
claim(str_contains($detailPage,'&lt;img src=x onerror=alert(1)&gt;')
    && !str_contains($detailPage,'<img src=x')
    && str_contains($detailPage,'src="/media/19"')
    && !str_contains($detailPage,'/media/20')
    && !str_contains($detailPage,'/media/21')
    && str_contains($detailPage,'/members/report?id=12'),
    'Private gallery escapes metadata and renders only guarded safe image URLs');
claim(str_contains(MemberLibrary::renderDetail([
    'id'=>99,'title'=>'Empty gallery','media'=>[],
]),'no approved images'), 'Approved text-only empty galleries render safely');
try {
    MemberLibrary::renderDetail(['id'=>0,'title'=>'Invalid','media'=>[]]);
    throw new RuntimeException('Invalid gallery accepted');
} catch (RuntimeException $e) {
    claim($e->getMessage()!=='Invalid gallery accepted',
        'Invalid gallery data rejected by renderer');
}
foreach ([['no-email','strong-passphrase'],['user@example.com','short'],['user@example.com',str_repeat('p',73)]] as $case) {
    try { MemberAccounts::validate(...$case); throw new RuntimeException('Bad registration accepted'); }
    catch (RuntimeException $e) { claim($e->getMessage() !== 'Bad registration accepted', 'Invalid registration rejected'); }
}
claim(MemberEmailSchema::VERSION==='m9_member_email_v1','Email schema has a distinct migration version');
claim(PasswordRecoverySchema::VERSION==='m10_member_recovery_v1','Recovery schema migration version');
PasswordRecovery::validatePassword('new-strong-password');claim(true,'Recovery password valid');
MemberPasswordChange::validateInput('current-long-password','new-long-password','new-long-password');
claim(true,'Member self-service password fields validated before database writes');
foreach ([
    ['','a-valid-new-password','a-valid-new-password'],
    ['current-long-password','short','short'],
    ['current-long-password','new-long-password','other-long-password'],
    ['current-long-password',str_repeat('x',73),str_repeat('x',73)],
] as $invalidPasswordChange) {
    try {
        MemberPasswordChange::validateInput(...$invalidPasswordChange);
        throw new RuntimeException('Invalid member password change accepted.');
    } catch (RuntimeException $e) {
        claim($e->getMessage()!=='Invalid member password change accepted.',
            'Invalid own-password change is refused');
    }
}
foreach (['short',str_repeat('x',73)] as $bad) {
    try { PasswordRecovery::validatePassword($bad); throw new RuntimeException('Invalid recovery password accepted'); }
    catch (RuntimeException $e) { claim($e->getMessage() !== 'Invalid recovery password accepted','Weak recovery passwords refused'); }
}
EmailDelivery::validateConfig(['enabled'=>true,'base_url'=>'https://example.com','from'=>'accounts@example.com']);
claim(true,'Valid HTTPS email origin');
foreach (['http://example.com','https://example.com/path','https://example.com?param=1','https://user@example.com','https://example.com:8080','https://example.com/'] as $invalid) {
    try { EmailDelivery::validateConfig(['enabled'=>true,'base_url'=>$invalid,'from'=>'accounts@example.com']); throw new RuntimeException('Unsafe email origin accepted'); }
    catch (RuntimeException $e) { claim($e->getMessage() !== 'Unsafe email origin accepted','Unsafe email origin rejected'); }
}
// Session tests use an isolated test directory and can run without MySQL.
$root = sys_get_temp_dir().'/ac-member-session-'.bin2hex(random_bytes(5));
mkdir($root);mkdir($root.'/storage');
MemberSession::start($root,false);claim(MemberSession::id()===null,'Anonymous by default');
$csrf=MemberSession::csrf();claim(MemberSession::csrfValid($csrf),'CSRF accepted');
claim(!MemberSession::csrfValid(str_repeat('f',64)),'CSRF rejected');
MemberSession::login(27,3);claim(MemberSession::id()===27,'Member login established');
claim(MemberSession::generation()===3,'Member session generation recorded');
MemberSession::revoke();claim(MemberSession::id()===null,'Member session manually revoked');
MemberSession::login(27,4);claim(MemberSession::generation()===4,'Member re-login advances generation');
MemberSession::logout();claim(session_status()===PHP_SESSION_NONE,'Session destroyed');
foreach (glob($root.'/storage/member-sessions/*') ?: [] as $f) unlink($f);
rmdir($root.'/storage/member-sessions');rmdir($root.'/storage');rmdir($root);
echo "Passed $checks site/member foundation checks.\n";
