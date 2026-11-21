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
    $check('Private config uses resolved edition',$manager->config()['sku']===CajunWebClient::SKU_LITE_MONTH);
    try{(new LicenseManager($root,$made(CajunWebClient::SKU_PRO_MONTH)))->replaceLicense($key,$domain);$ok=false;}catch(RuntimeException){$ok=true;}
    $check('Admin replacement cannot change Lite to Pro without migration',$ok);
    $check('Wrong-edition replacement preserves config',$manager->config()['sku']===CajunWebClient::SKU_LITE_MONTH);
    $manager->replaceLicense($key,$domain);
    $check('Same-edition key replacement remains authorized',$manager->status($domain)==='valid');
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
