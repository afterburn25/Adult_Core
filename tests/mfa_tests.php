<?php

declare(strict_types=1);

require dirname(__DIR__).'/src/Support/Autoload.php';

use AdultCore\Auth\Totp;
use AdultCore\Auth\AdminSession;

$n = 0;
$test = static function(string $label,bool $ok)use(&$n):void{
    if (!$ok) throw new RuntimeException('MFA failed: '.$label);
    $n++;
};
$secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
$test('RFC 6238 SHA1 six digit vector', Totp::code($secret,1)==='287082');
$test('Six-digit code verifies on exact step',Totp::verify($secret,'287082',-1,59)===1);
$test('TOTP is single use after counter spent',Totp::verify($secret,'287082',1,59)===null);
$test('Clock skew one step accepted',Totp::verify($secret,'287082',-1,89)===1);
$test('Two steps outside skew refused',Totp::verify($secret,'287082',-1,119)===null);
$test('Malformed factor refused',Totp::verify($secret,'28708X',-1,59)===null);
$test('Short factor refused',Totp::verify($secret,'28708',-1,59)===null);
$test('Secret base32 encoded without padding',Totp::base32('12345678901234567890')===$secret);
$test('Secret decoded exactly',Totp::decode($secret)==='12345678901234567890');
$generated=Totp::generateSecret();
$test('Generated key has 160 bits',strlen($generated)===32&&strlen(Totp::decode($generated))===20);
$test('Current generated code verifies',Totp::verify($generated,Totp::code($generated,intdiv(time(),30)))!==null);
foreach (['','ABC','A'.str_repeat('A',30).'=' ,'ABC0'.str_repeat('A',28)] as $value) {
    try{Totp::decode($value);$ok=false;}catch(InvalidArgumentException){$ok=true;}
    $test('Invalid secret rejected', $ok);
}
$test('Session cannot be considered MFA verified by default', !AdminSession::mfaVerified());
$start=sys_get_temp_dir().'/adultcore-mfa-test-'.bin2hex(random_bytes(4));mkdir($start);mkdir($start.'/storage');
try {
    AdminSession::start($start,false);
    $token=AdminSession::csrf();
    $test('Pre-auth session token created',strlen($token)===64);
    AdminSession::beginMfa(7,3);
    $test('Moderator pending MFA challenge binds password-step revocation epoch',
        AdminSession::pendingStaffEpoch()===3);
    $test('Password stage does not authenticate',AdminSession::authenticatedId()===null);
    $test('Short-lived MFA challenge identifies account',AdminSession::pendingMfaId()===7);
    $test('MFA challenge has rotated CSRF token',!hash_equals($token,AdminSession::csrf()));
    AdminSession::authenticate(7,null,true);
    $test('MFA-completed session is fully authenticated',AdminSession::authenticatedId()===7&&AdminSession::mfaVerified());
    $test('MFA pending state cleared by full authentication',
        AdminSession::pendingMfaId()===null && AdminSession::pendingStaffEpoch()===null);
    AdminSession::setEnrollmentSecret(7,$generated);
    $test('Enrollment available to right session user',AdminSession::enrollmentSecret(7)===$generated);
    $test('Enrollment not leaked across users',AdminSession::enrollmentSecret(8)===null);
    AdminSession::setEnrollmentSecret(7,$generated);
    $test('Enrollment expires after 10 minutes',AdminSession::enrollmentSecret(7,time()+601)===null);
    AdminSession::authenticate(7);
    $test('Password-only authentication lacks MFA completion',!AdminSession::mfaVerified());
    AdminSession::logout();
} finally {
    foreach(glob($start.'/storage/sessions/*')?:[] as $file)if(is_file($file))unlink($file);
    if(is_dir($start.'/storage/sessions'))rmdir($start.'/storage/sessions');
    foreach(glob($start.'/storage/*')?:[] as $file)if(is_file($file))unlink($file);
    foreach(glob($start.'/storage/.*')?:[] as $file)if(is_file($file))unlink($file);
    rmdir($start.'/storage');rmdir($start);
}
echo "Passed {$n} administrator MFA and authenticator checks.\n";
