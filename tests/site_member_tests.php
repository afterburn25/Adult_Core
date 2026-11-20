<?php

declare(strict_types=1);
require dirname(__DIR__) . '/src/Support/Autoload.php';
use AdultCore\Website\PageRepository;
use AdultCore\Member\MemberAccounts;
use AdultCore\Member\MemberSession;
$checks = 0;
function claim(bool $ok, string $name): void { global $checks; if (!$ok) throw new RuntimeException($name); $checks++; }
PageRepository::validate('home','Hello','Safe plain text','published');claim(true,'Valid home page');
PageRepository::validate('about-us','About us','A','draft');claim(true,'Valid slug');
foreach ([['UPPER','Title','Body','draft'],['bad/slash','Title','Body','draft'],['a','Title','Body','hidden'],['a','','Body','draft'],['a',str_repeat('x',191),'Body','draft'],['a','Title',str_repeat('x',30001),'draft']] as $case) {
    try { PageRepository::validate(...$case); throw new RuntimeException('Invalid page accepted'); }
    catch (RuntimeException $e) { claim($e->getMessage() !== 'Invalid page accepted', 'Page input rejected'); }
}
MemberAccounts::validate('user@example.com','a-long-strong-password');claim(true,'Valid member details');
foreach ([['no-email','strong-passphrase'],['user@example.com','short'],['user@example.com',str_repeat('p',73)]] as $case) {
    try { MemberAccounts::validate(...$case); throw new RuntimeException('Bad registration accepted'); }
    catch (RuntimeException $e) { claim($e->getMessage() !== 'Bad registration accepted', 'Invalid registration rejected'); }
}
// Session tests use an isolated test directory and can run without MySQL.
$root = sys_get_temp_dir().'/ac-member-session-'.bin2hex(random_bytes(5));
mkdir($root);mkdir($root.'/storage');
MemberSession::start($root,false);claim(MemberSession::id()===null,'Anonymous by default');
$csrf=MemberSession::csrf();claim(MemberSession::csrfValid($csrf),'CSRF accepted');
claim(!MemberSession::csrfValid(str_repeat('f',64)),'CSRF rejected');
MemberSession::login(27);claim(MemberSession::id()===27,'Member login established');
MemberSession::logout();claim(session_status()===PHP_SESSION_NONE,'Session destroyed');
foreach (glob($root.'/storage/member-sessions/*') ?: [] as $f) unlink($f);
rmdir($root.'/storage/member-sessions');rmdir($root.'/storage');rmdir($root);
echo "Passed $checks site/member foundation checks.\n";
