<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Licensing\CajunWebClient;
use AdultCore\Licensing\LicenseManager;
use AdultCore\Licensing\LicenseState;
use AdultCore\Installer\InstallationState;

$n=0;
$check=function(string $name,bool $passed)use(&$n):void{if(!$passed)throw new RuntimeException('Licensing test failed: '.$name);$n++;};
$key='ABCD-EF12-3456-7890';$domain='example.test';$secret=str_repeat('a',64);
$products=CajunWebClient::products();
$check('Four exact product variants',count($products)===4);
$check('Monthly Lite and Pro are distinct',CajunWebClient::identity(CajunWebClient::SKU_LITE_MONTH)['term']==='month'
    && CajunWebClient::identity(CajunWebClient::SKU_PRO_MONTH)['edition']==='pro');
$check('Legacy single-product key no longer selects an edition',CajunWebClient::identity('SOFTWARE-ADULT-CORE')===null);
$made=static fn(string $sku):CajunWebClient=>new CajunWebClient(static function(string $payload)use($sku):array{
    $r=json_decode($payload,true,8,JSON_THROW_ON_ERROR);
    if (($r['license_key']??null)!=='ABCD-EF12-3456-7890'||($r['domain']??null)!=='example.test')throw new RuntimeException('Bad request');
    return ['status'=>200,'body'=>json_encode(['authorized'=>($r['product_id']??null)===$sku])];
});
foreach($products as $sku=>$identity){
    $resolved=$made($sku)->resolve($key,$domain);
    $check('Key resolves exact edition + term '.$sku,$resolved!==null && $resolved['sku']===$sku
        && $resolved['edition']===$identity['edition'] && $resolved['term']===$identity['term']);
    $other=$sku===CajunWebClient::SKU_LITE_ONCE?CajunWebClient::SKU_PRO_ONCE:CajunWebClient::SKU_LITE_ONCE;
    $check('Cross-product license denied '.$sku,$made($sku)->validate($key,$domain,$other)==='denied');
}
// Actual Cajun Web Pro handler returns authorized=true with error=null.
// Contradictory or malformed responses must not grant license authority.
$responseCases = [
    ['name' => 'Valid issuer success with null error', 'body' => '{"authorized":true,"error":null}', 'expected' => 'valid'],
    ['name' => 'Issuer denial with reason', 'body' => '{"authorized":false,"error":"Domain not registered"}', 'expected' => 'denied'],
    ['name' => 'Contradictory success plus error', 'body' => '{"authorized":true,"error":"License expired"}', 'expected' => 'transient'],
    ['name' => 'Contradictory success plus false error', 'body' => '{"authorized":true,"error":false}', 'expected' => 'transient'],
    ['name' => 'Numeric success is invalid', 'body' => '{"authorized":1,"error":null}', 'expected' => 'transient'],
    ['name' => 'String success is invalid', 'body' => '{"authorized":"true","error":null}', 'expected' => 'transient'],
    ['name' => 'Missing authorization is invalid', 'body' => '{"error":null}', 'expected' => 'transient'],
    ['name' => 'Invalid JSON response is inconclusive', 'body' => '{broken', 'expected' => 'transient'],
];
foreach ($responseCases as $case) {
    $responseClient = new CajunWebClient(static fn(string $payload): array => [
        'status' => 200, 'body' => $case['body'],
    ]);
    $check($case['name'], $responseClient->validate($key, $domain, CajunWebClient::SKU_LITE_ONCE) === $case['expected']);
}
$contradictory = new CajunWebClient(static function (string $body): array {
    $sku = json_decode($body, true, 8, JSON_THROW_ON_ERROR)['product_id'] ?? '';
    return ['status' => 200, 'body' => json_encode([
        'authorized' => $sku === CajunWebClient::SKU_LITE_ONCE,
        'error' => $sku === CajunWebClient::SKU_LITE_ONCE ? 'License expired' : null,
    ], JSON_THROW_ON_ERROR)];
});
try { $contradictory->resolve($key, $domain); $contradictoryBlocked = false; }
catch (RuntimeException $error) { $contradictoryBlocked = str_contains($error->getMessage(), 'temporarily unavailable'); }
$check('Contradictory issuer success cannot start installation', $contradictoryBlocked);
$denier=new CajunWebClient(static fn(string $body):array=>['status'=>200,'body'=>'{"authorized":false}']);
$check('Unregistered key has no product',$denier->resolve($key,$domain)===null);
foreach([400,429,500,302] as $status){
    $client=new CajunWebClient(static fn(string $body):array=>['status'=>$status,'body'=>'{"authorized":false}']);
    $check('Non200 is inconclusive '.$status,$client->validate($key,$domain,CajunWebClient::SKU_LITE_ONCE)==='transient');
    try {$client->resolve($key,$domain);$ok=false;}catch(RuntimeException){$ok=true;}
    $check('Cannot install when one or more product checks were inconclusive '.$status,$ok);
}

// One authorized SKU is not sufficient when another SKU has an inconclusive response:
// it might be authorized too, so the installer must fail closed.
foreach (array_keys($products) as $authorizedSku) {
    foreach (array_keys($products) as $uncertainSku) {
        if ($authorizedSku === $uncertainSku) continue;
        $mixed = new CajunWebClient(static function (string $body) use ($authorizedSku, $uncertainSku): array {
            $sku = json_decode($body, true, 8, JSON_THROW_ON_ERROR)['product_id'] ?? '';
            if ($sku === $uncertainSku) return ['status' => 503, 'body' => '{}'];
            return ['status' => 200, 'body' => json_encode(['authorized' => $sku === $authorizedSku], JSON_THROW_ON_ERROR)];
        });
        try { $mixed->resolve($key, $domain); $blocked = false; }
        catch (RuntimeException) { $blocked = true; }
        $check('Authorized SKU cannot install with uncertain SKU '.$authorizedSku.' / '.$uncertainSku, $blocked);
    }
}
// Every SKU must be checked even after one positive response. A key that
// resolves to multiple products is ambiguous and must never select an edition.
$probes = [];
$duplicate = new CajunWebClient(static function (string $body) use (&$probes): array {
    $sku = json_decode($body, true, 8, JSON_THROW_ON_ERROR)['product_id'] ?? '';
    $probes[] = $sku;
    return ['status' => 200, 'body' => json_encode([
        'authorized' => in_array($sku, [CajunWebClient::SKU_LITE_ONCE, CajunWebClient::SKU_PRO_ONCE], true),
    ], JSON_THROW_ON_ERROR)];
});
try { $duplicate->resolve($key, $domain); $ambiguous = false; }
catch (RuntimeException $error) { $ambiguous = str_contains($error->getMessage(), 'multiple editions'); }
$check('Ambiguous multiple authorized SKUs fail closed', $ambiguous);
$check('Ambiguous key stops discovery as soon as second authorization is found', $probes === array_slice(array_keys($products), 0, 3));
$probeCount = 0;
$single = new CajunWebClient(static function (string $body) use (&$probeCount): array {
    $probeCount++;
    $sku = json_decode($body, true, 8, JSON_THROW_ON_ERROR)['product_id'] ?? '';
    return ['status' => 200, 'body' => json_encode([
        'authorized' => $sku === CajunWebClient::SKU_LITE_ONCE,
    ], JSON_THROW_ON_ERROR)];
});
$check('Unambiguous Lite resolution succeeds', $single->resolve($key, $domain)['edition'] === 'lite');
$check('Successful discovery still checks four SKUs', $probeCount === 4);
$network = new CajunWebClient(static function (string $body): array {
    $sku = json_decode($body, true, 8, JSON_THROW_ON_ERROR)['product_id'] ?? '';
    if ($sku === CajunWebClient::SKU_PRO_MONTH) throw new RuntimeException('Simulated transport outage');
    return ['status' => 200, 'body' => json_encode([
        'authorized' => $sku === CajunWebClient::SKU_LITE_ONCE,
    ], JSON_THROW_ON_ERROR)];
});
try { $network->resolve($key, $domain); $outageBlocked = false; }
catch (RuntimeException $error) { $outageBlocked = str_contains($error->getMessage(), 'temporarily unavailable'); }
$check('Positive license plus thrown transport exception fails closed', $outageBlocked);
$check('www hostname normalized',CajunWebClient::domain('WWW.Example.Test')==='example.test');
foreach(['http://example.test','example.test:443','localhost','bad/../example.test','bad@domain.test'] as $value){
    try{CajunWebClient::domain($value);$ok=false;}catch(InvalidArgumentException){$ok=true;}
    $check('Bad hostname rejected '.$value,$ok);
}
foreach($products as $sku=>$identity){
    $raw=LicenseState::encode($key,$domain,$secret,'valid',100000,100000,$sku);
    $check('Signed receipt roundtrip '.$sku,LicenseState::decode($raw,$key,$domain,$secret,$sku,100100)!==null);
    $check('Wrong SKU cannot replay receipt '.$sku,LicenseState::decode($raw,$key,$domain,$secret,$sku===CajunWebClient::SKU_LITE_ONCE?CajunWebClient::SKU_PRO_ONCE:CajunWebClient::SKU_LITE_ONCE,100100)===null);
    $check('Wrong domain cannot replay receipt '.$sku,LicenseState::decode($raw,$key,'evil.test',$secret,$sku,100100)===null);
    $check('Bad MAC rejected '.$sku,LicenseState::decode(str_replace('"valid"','"denied"',$raw),$key,$domain,$secret,$sku,100100)===null);
}
$root=sys_get_temp_dir().'/ac-edition-license-'.bin2hex(random_bytes(5));
mkdir($root);mkdir($root.'/config');mkdir($root.'/storage');
file_put_contents($root.'/config/app-key.php','<?php return '.var_export($secret,true).';');
try{
    $manager=new LicenseManager($root,$made(CajunWebClient::SKU_LITE_MONTH));
    $record=$manager->verifyForInstall($key,$domain);
    $check('Monthly plan resolved from vendor SKU',$record['edition']==='lite'&&$record['term']==='month');
    $manager->persistInitial($record,$secret);
    file_put_contents($root.'/storage/install-state.json',InstallationState::encode('lite',$secret));
    $check('Licensed Lite monthly can operate',$manager->status('example.test')==='valid');
    $calls = 0;
    $issuer = new CajunWebClient(static function (string $body) use (&$calls): array {
        $calls++;
        return ['status'=>200, 'body'=>'{"authorized":false}'];
    });
    $forced = new LicenseManager($root,$issuer);
    $check('Recent signed valid receipt is used without vendor call', $forced->status('example.test') === 'valid' && $calls === 0);
    $check('Manual check bypasses fresh cache and records revocation', $forced->status('example.test', true) === 'denied' && $calls === 1
        && $forced->lastAuthorityResponse() === 'denied');
    $check('Subsequent requests honor signed revocation', $manager->status('example.test') === 'denied');
    $check('Manual refresh can restore reactivated license', $manager->status('example.test',true)==='valid');
    $temporarilyUnavailable = new LicenseManager($root,new CajunWebClient(static fn(string $body):array=>[
        'status'=>503,'body'=>'{"authorized":false}'
    ]));
    $check('Manual check distinguishes temporary issuer outage', $temporarilyUnavailable->status('example.test',true)==='valid'
        && $temporarilyUnavailable->lastAuthorityResponse()==='transient');
    $check('Temporary failure does not replace positive receipt', $manager->status('example.test')==='valid');

    $check('Site remains host locked to domain',$manager->status('other.example')==='domain_mismatch');
    foreach (['example.test:80', 'WWW.EXAMPLE.TEST:443', 'example.test:65535'] as $allowedHost) {
        $check('Runtime license accepts normalized hostname with valid port '.$allowedHost,
            $manager->status($allowedHost)==='valid');
    }
    foreach (['example.test:0','example.test:65536','example.test:99999',
        'example.test:','example.test:-1','example.test:1:2',
        'https://example.test','example.test/path','example.test:443@evil.test',
        'example.test\\r\\nHost: evil.test'] as $unsafeHost) {
        $check('Runtime license rejects malformed Host '.$unsafeHost,
            $manager->status($unsafeHost)==='domain_mismatch');
    }

    $check('Private config uses resolved edition',$manager->config()['sku']===CajunWebClient::SKU_LITE_MONTH);
    try{(new LicenseManager($root,$made(CajunWebClient::SKU_PRO_MONTH)))->replaceLicense($key,$domain);$ok=false;}catch(RuntimeException){$ok=true;}
    $check('Admin replacement cannot change Lite to Pro without migration',$ok);
    $check('Wrong-edition replacement preserves config',$manager->config()['sku']===CajunWebClient::SKU_LITE_MONTH);
    $manager->replaceLicense($key,$domain);
    $check('Same-edition key replacement remains authorized',$manager->status($domain)==='valid');
    // A cache path changed to a symlink must block the entire replacement,
    // not allow a new config to be written before the cache update fails.
    $cachePath = $root.'/storage/license-cache.json';
    $cacheBackup = $root.'/storage/cache-before-replace.json';
    $oldConfig = file_get_contents($root.'/config/license.php');
    if (rename($cachePath, $cacheBackup)) {
        try {
            if (@symlink($cacheBackup, $cachePath)) {
                $attempted = new LicenseManager($root,$made(CajunWebClient::SKU_LITE_ONCE));
                try { $attempted->replaceLicense($key,$domain); $blocked = false; }
                catch (RuntimeException) { $blocked = true; }
                $check('Symlinked license cache blocks replacement', $blocked);
                $check('Failed cache preflight preserves original license config',
                    file_get_contents($root.'/config/license.php') === $oldConfig
                    && $manager->config()['sku'] === CajunWebClient::SKU_LITE_MONTH);
                unlink($cachePath);
            }
        } finally {
            if (is_link($cachePath)) unlink($cachePath);
            rename($cacheBackup, $cachePath);
        }
    } else {
        throw new RuntimeException('Could not prepare private cache replacement fixture.');
    }
    // The configuration destination itself must also be a regular private
    // file; a symlink there cannot redirect a purchased key to another file.
    $licensePath = $root.'/config/license.php';
    $licenseBackup = $root.'/config/license-before-replace.php';
    $cachedBefore = file_get_contents($cachePath);
    if (!rename($licensePath, $licenseBackup)) {
        throw new RuntimeException('Could not prepare private license replacement fixture.');
    }
    try {
        if (!@symlink($licenseBackup, $licensePath)) {
            throw new RuntimeException('Could not create local symlink security fixture.');
        }
        $attempted = new LicenseManager($root,$made(CajunWebClient::SKU_LITE_ONCE));
        try { $attempted->replaceLicense($key,$domain); $blocked = false; }
        catch (RuntimeException) { $blocked = true; }
        $check('Symlinked license configuration blocks replacement', $blocked);
        $check('Failed configuration preflight preserves signed license cache',
            file_get_contents($cachePath) === $cachedBefore);
    } finally {
        if (is_link($licensePath)) unlink($licensePath);
        rename($licenseBackup, $licensePath);
    }
    $old=LicenseState::encode($key,$domain,$secret,'valid',time()-LicenseManager::REFRESH_SECONDS-2,time()-LicenseManager::REFRESH_SECONDS-2,CajunWebClient::SKU_LITE_MONTH);
    file_put_contents($root.'/storage/license-cache.json',$old);
    $transient=new LicenseManager($root,new CajunWebClient(static fn(string $body):array=>['status'=>503,'body'=>'{}']));
    $check('Short issuer outage has bounded grace',$transient->status($domain)==='valid');
    file_put_contents($root.'/storage/license-cache.json',LicenseState::encode($key,$domain,$secret,'valid',time()-LicenseManager::TRANSIENT_GRACE_SECONDS-10,time()-LicenseManager::TRANSIENT_GRACE_SECONDS-10,CajunWebClient::SKU_LITE_MONTH));
    $check('Expired grace blocks',$transient->status($domain)==='unavailable');
    file_put_contents($root.'/storage/license-cache.json',LicenseState::encode($key,$domain,$secret,'denied',time(),time()-50,CajunWebClient::SKU_LITE_MONTH));
    $check('Definitive denial always blocks',$manager->status($domain)==='denied');
    // Signed state cannot be rewritten from a forged edition token.
    file_put_contents($root.'/storage/install-state.json',InstallationState::encode('pro',$secret));
    $check('Changing signed edition without matching SKU causes fail closed',$manager->status($domain)==='license_required');
}finally{
    foreach(glob($root.'/config/*')?:[] as $p)unlink($p);
    foreach(glob($root.'/storage/*')?:[] as $p)unlink($p);
    foreach(glob($root.'/storage/.*')?:[] as $p)if(is_file($p))unlink($p);
    rmdir($root.'/storage');rmdir($root.'/config');rmdir($root);
}
echo "Passed {$n} edition and subscription licensing checks.\n";
