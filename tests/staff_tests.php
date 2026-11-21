<?php

declare(strict_types=1);

require dirname(__DIR__).'/src/Support/Autoload.php';

use AdultCore\Auth\RolePolicy;
use AdultCore\Auth\StaffRepository;
use AdultCore\Auth\StaffSchema;

$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void {
    if (!$ok) throw new RuntimeException('Staff lifecycle unit: '.$message);
    $checks++;
};
$check(StaffRepository::validateEmail(' MOD@example.TEST ')==='mod@example.test',
    'Staff email normalized and bounded');
foreach (['','invalid',str_repeat('a',191).'@test.example'] as $bad) {
    try { StaffRepository::validateEmail($bad);$check(false,'Invalid staff email accepted'); }
    catch (RuntimeException) { $check(true,'Invalid staff email refused'); }
}
$check(StaffRepository::validatePassword('a-valid-long-staff-password')==='a-valid-long-staff-password',
    'Strong staff password accepted verbatim');
foreach (['',str_repeat('x',15),str_repeat('x',73),"two\nlines-long-passphrase"] as $bad) {
    try { StaffRepository::validatePassword($bad);$check(false,'Weak/unsafe staff password accepted'); }
    catch (RuntimeException) { $check(true,'Weak/unsafe staff password refused'); }
}
$check(RolePolicy::allows('owner','members.manage')
    && RolePolicy::allows('admin','members.manage')
    && !RolePolicy::allows('moderator','members.manage')
    && !RolePolicy::allows('member','members.manage'),
    'Staff management restricted to managers');
$source=(string)file_get_contents(dirname(__DIR__).'/src/Auth/StaffRepository.php');
$check(str_contains($source,"'moderator','active'") && !str_contains($source,"VALUES (?,?,'admin'"),
    'No user-controlled role may grant owner/admin');
$controller=(string)file_get_contents(dirname(__DIR__).'/src/Http/StaffController.php');
$check(str_contains($controller,'AdminSession::csrfValid')
    && str_contains($controller,'verifyCredentials')
    && str_contains($controller,'InstallRateLimiter')
    && !str_contains($controller,"action==='promote'"),
    'Manager step-up and CSRF required; role promotion absent');
$security=(string)file_get_contents(dirname(__DIR__).'/src/Http/MfaController.php');
$check(str_contains($security,"action==='change_password'")
    && str_contains($security,'AdminSession::revoke()')
    && str_contains($source,'staff_session_epoch=staff_session_epoch+1'),
    'Self-service moderator password rotation invalidates all sessions');
$check(StaffSchema::VERSION==='m11_staff_audit_v1',
    'Staff audit schema version is explicit');
echo "Passed {$checks} staff lifecycle safety checks.\n";
