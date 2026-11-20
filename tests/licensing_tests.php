<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Licensing\CajunWebClient;
use AdultCore\Licensing\LicenseManager;
use AdultCore\Licensing\LicenseState;

$n = 0;
$check = static function (string $name, bool $ok) use (&$n): void {
    if (!$ok) throw new RuntimeException('Licensing test failed: ' . $name);
    $n++;
};
$fake = static fn(int $status, string $body): CajunWebClient => new CajunWebClient(
    static fn(string $payload): array => ['status' => $status, 'body' => $body]
);
$key='ABCD-1234-EF56-7890';
$domain='example.test';
$secret=str_repeat('f',64);
$client = new CajunWebClient(static function (string $body) use ($key, $check): array {
    $payload=json_decode($body,true,8,JSON_THROW_ON_ERROR);
    $check('vendor endpoint receives product-specific software SKU',($payload['product_id']??null)===CajunWebClient::SKU);
    $check('vendor endpoint receives exact key and domain',($payload['license_key']??null)==='ABCD-1234-EF56-7890'
        && ($payload['domain']??null)==='example.test');
    $check('unregistered developer build version is not sent', !isset($payload['version']));
    return ['status'=>200,'body'=>'{"authorized":true}'];
});
$check('positive vendor authorization', $client->validate($key, 'www.example.test')==='valid');
$check('explicit HTTP 200 license denial', $fake(200,'{"authorized":false,"error":"revoked"}')->validate($key,$domain)==='denied');
foreach ([400,429,503,302] as $status) {
    $check('transient non-200 '.$status,$fake($status,'{"authorized":false}')->validate($key,$domain)==='transient');
}
foreach (['{"authorized":"false"}','{"error":"missing"}','invalid json','{"authorized":null}'] as $reply) {
    $check('ambiguous vendor payload fails closed', $fake(200,$reply)->validate($key,$domain)==='transient');
}
$check('www and apex canonicalize identically', CajunWebClient::domain('WWW.Example.Test')==='example.test');
foreach (['http://example.test','example.test:443','localhost','bad/../example.test','evil.example.test/path','bad@domain.test'] as $host) {
    try { CajunWebClient::domain($host); $ok=false; } catch (InvalidArgumentException) { $ok=true; }
    $check('unsafe domain rejected', $ok);
}
foreach (['', 'abc', 'a key with spaces', 'foo/bar'] as $value) {
    try { CajunWebClient::key($value); $ok=false; } catch (InvalidArgumentException) { $ok=true; }
    $check('unsafe license key rejected', $ok);
}
$raw=LicenseState::encode($key,$domain,$secret,'valid',100000,100000);
$check('signed receipt parses', LicenseState::decode($raw,$key,$domain,$secret,100100)!==null);
$check('receipts are domain specific', LicenseState::decode($raw,$key,'other.example',$secret,100100)===null);
$check('receipts bind license key', LicenseState::decode($raw,'DIFFERENT-KEY-1234',$domain,$secret,100100)===null);
$check('receipts bind signing secret', LicenseState::decode($raw,$key,$domain,str_repeat('a',64),100100)===null);
$check('modified verdict rejected', LicenseState::decode(str_replace('"valid"','"denied"',$raw),$key,$domain,$secret,100100)===null);
$check('future timestamp rejected', LicenseState::decode($raw,$key,$domain,$secret,1000)===null);
$root=sys_get_temp_dir().'/adult-core-license-'.bin2hex(random_bytes(8));
mkdir($root);mkdir($root.'/config');mkdir($root.'/storage');
$good=new LicenseManager($root,$client);
try {
    $check('new site requires license', $good->status('example.test')==='license_required');
    $record=$good->verifyForInstall($key,$domain);
    file_put_contents($root.'/config/app-key.php', '<?php return '.var_export($secret,true).';');
    $good->persistInitial($record,$secret);
    $check('initial state authenticates', $good->status('example.test:8443')==='valid');
    $check('www alias allowed for licensed domain',$good->status('www.example.test')==='valid');
    $check('other domain refused',$good->status('malicious.example')==='domain_mismatch');
    $check('license never shown in cache',!str_contains((string)file_get_contents($root.'/storage/license-cache.json'),$key));
    $check('private key file permissions', (fileperms($root.'/config/license.php')&0077)===0);
    $check('cached denial always blocks', (function () use($root,$key,$domain,$secret,$good){
        file_put_contents($root.'/storage/license-cache.json', LicenseState::encode($key,$domain,$secret,'denied',time(),time()-50));
        return $good->status($domain)==='denied';
    })());
    $good->replaceLicense($key,$domain);
    $check('authorized admin reactivation clears denial',$good->status($domain)==='valid');
    $outage=new LicenseManager($root,$fake(503,'{"authorized":false}'));
    file_put_contents($root.'/storage/license-cache.json', LicenseState::encode($key,$domain,$secret,'valid',time()-LicenseManager::REFRESH_SECONDS-10,time()-LicenseManager::REFRESH_SECONDS-10));
    $check('short vendor outage grants bounded last-good grace',$outage->status($domain)==='valid');
    file_put_contents($root.'/storage/license-cache.json', LicenseState::encode($key,$domain,$secret,'valid',time()-LicenseManager::TRANSIENT_GRACE_SECONDS-10,time()-LicenseManager::TRANSIENT_GRACE_SECONDS-10));
    $check('grace expiration blocks site',$outage->status($domain)==='unavailable');
    $check('tampered local receipt blocks site when authority unavailable',(function() use($root,$outage,$domain){
        file_put_contents($root.'/storage/license-cache.json','{"verdict":"valid"}');
        return $outage->status($domain)==='unavailable';
    })());
    $revoked=new LicenseManager($root,$fake(200,'{"authorized":false}'));
    $check('authority explicit refusal blocks site',$revoked->status($domain)==='denied');
    $check('authoritative denial is persisted',$revoked->status($domain)==='denied');
    $check('license key is never included in saved response',(function() use ($root,$key) {
        return !str_contains((string)file_get_contents($root.'/storage/license-cache.json'),$key);
    })());
    try { $revoked->verifyForInstall($key,$domain); $ok=false; } catch (RuntimeException) {$ok=true;}
    $check('first install refuses rejected key',$ok);
    try { $outage->verifyForInstall($key,$domain); $ok=false; } catch (RuntimeException) {$ok=true;}
    $check('first install refuses vendor outage',$ok);
} finally {
    foreach (glob($root.'/config/*')?:[] as $f)unlink($f);
    foreach (glob($root.'/storage/*')?:[] as $f)unlink($f);
    foreach (glob($root.'/storage/.*')?:[] as $f)if(is_file($f))unlink($f);
    rmdir($root.'/storage');rmdir($root.'/config');rmdir($root);
}
echo "Passed {$n} licensing contract and runtime checks.\n";
