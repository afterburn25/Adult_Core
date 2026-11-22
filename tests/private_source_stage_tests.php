<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Release\SourceArchive;
use AdultCore\Release\SourceStage;

$checks = 0;
$check = static function (bool $okay, string $label) use (&$checks): void {
    if (!$okay) throw new RuntimeException('Private source staging: ' . $label);
    ++$checks;
};
$reject = static function (callable $fn, string $label) use ($check): void {
    try { $fn(); } catch (RuntimeException) { $check(true, $label); return; }
    $check(false, $label);
};
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $part) {
        if ($part !== '.' && $part !== '..') $remove($path . '/' . $part);
    }
    rmdir($path);
};
$repo = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/ac-private-stage-' . bin2hex(random_bytes(8));
if (!mkdir($tmp, 0700)) throw new RuntimeException('Could not create temporary staging fixture.');
try {
    $zip = $tmp . '/source.zip';
    exec(escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg($repo . '/scripts/build-release.php') . ' '
        . escapeshellarg($zip) . ' 2>&1', $output, $code);
    $check($code === 0 && is_file($zip), 'Real unified Lite/Pro source archive built');

    $key = openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
    if ($key === false) throw new RuntimeException('Could not create disposable signing key.');
    $trusted = openssl_pkey_get_details($key)['key'];
    $manifest = SourceArchive::manifestJson($zip);
    openssl_sign($manifest, $signatureBytes, $key, OPENSSL_ALGO_SHA256);
    $signature = base64_encode($signatureBytes);
    $inventory = SourceArchive::verifiedInventory($zip,$manifest,$signature,$trusted);
    $check(count($inventory['files']) > 150, 'Actual archive includes complete source inventory');

    $installed = $tmp . '/installed';
    mkdir($installed,0700); mkdir($installed.'/public',0700);
    mkdir($installed.'/src',0700); mkdir($installed.'/src/Support',0700);
    mkdir($installed.'/config',0700);
    file_put_contents($installed.'/VERSION', "0.8.11-old-dev\n");
    copy($repo.'/public/index.php', $installed.'/public/index.php');
    copy($repo.'/src/Support/Autoload.php', $installed.'/src/Support/Autoload.php');
    file_put_contents($installed.'/config/license.php', "<?php return ['key'=>'private-customer-key'];\n");
    $oldHash = hash_file('sha256',$installed.'/public/index.php');
    $oldLicense = file_get_contents($installed.'/config/license.php');

    $stage = $tmp.'/candidate-stage';
    $result = SourceStage::prepare($installed,$zip,$manifest,$signature,$trusted,$stage);
    $check($result['status']==='staged_only' && $result['read_only_installed']
        && $result['install_authorized']===false, 'Completion cannot approve an update');
    $check($result['file_count']===count($inventory['files'])
        && $result['version']===trim((string)file_get_contents($repo.'/VERSION')),
        'Receipt version and count match signed inventory');
    $receipt = json_decode((string)file_get_contents($stage.'/.ac-stage-complete.json'),true);
    $check(is_array($receipt) && $receipt['status']==='staged_only'
        && $receipt['source_inventory_sha256']===hash('sha256',$manifest)
        && !is_file($stage.'/.ac-stage-pending'), 'Completed stage has authenticated receipt without pending marker');
    $check((fileperms($stage)&0077)===0
        && (fileperms($stage.'/public')&0077)===0
        && (fileperms($stage.'/public/index.php')&0077)===0,
        'Stage directories and code files are private');
    $check(hash_file('sha256',$stage.'/public/index.php')===$oldHash
        && hash_file('sha256',$stage.'/VERSION')===hash_file('sha256',$repo.'/VERSION'),
        'Staged source bytes match signed distribution');
    $check(!is_file($stage.'/config/license.php') && !is_file($stage.'/storage/license-cache.json'),
        'Customer license and storage secrets not included in source stage');
    $check(file_get_contents($installed.'/config/license.php')===$oldLicense
        && hash_file('sha256',$installed.'/public/index.php')===$oldHash
        && file_get_contents($installed.'/VERSION')==="0.8.11-old-dev\n",
        'Installed site source, version and license unchanged');

    $reject(static fn()=>SourceStage::prepare($installed,$zip,$manifest,$signature,$trusted,$stage),
        'Existing stage cannot be overwritten');
    $forged=$tmp.'/forged-stage';
    $reject(static fn()=>SourceStage::prepare(
        $installed,$zip,$manifest,base64_encode(random_bytes(256)),$trusted,$forged
    ),'Forged signature refused before creating destination');
    $check(!file_exists($forged),'Untrusted ZIP never creates source stage');
    $inside=$installed.'/inside-live-source';
    $reject(static fn()=>SourceStage::prepare($installed,$zip,$manifest,$signature,$trusted,$inside),
        'Never stage inside installed application');
    $check(!file_exists($inside),'Unsafe staging refusal has no side effects');

    $publicParent=$tmp.'/public-parent';
    mkdir($publicParent,0755);
    $reject(static fn()=>SourceStage::prepare(
        $installed,$zip,$manifest,$signature,$trusted,$publicParent.'/bad'
    ),'World-readable staging parent refused');
    $check(!file_exists($publicParent.'/bad'),'Public staging destination remains absent');

    if (function_exists('symlink') && symlink($stage,$tmp.'/stage-link')) {
        $reject(static fn()=>SourceStage::prepare(
            $installed,$zip,$manifest,$signature,$trusted,$tmp.'/stage-link'
        ),'Symlink staging destination refused');
    }

    $manifestPath=$tmp.'/manifest.json'; $sigPath=$tmp.'/signature.txt'; $pubPath=$tmp.'/public.pem';
    file_put_contents($manifestPath,$manifest);
    file_put_contents($sigPath,$signature);
    file_put_contents($pubPath,$trusted);
    $cliStage=$tmp.'/stage-from-cli';
    $cli=escapeshellarg(PHP_BINARY).' '.escapeshellarg($repo.'/scripts/stage-source-release.php').' '
        .implode(' ',array_map('escapeshellarg',[
            $zip,$manifestPath,$sigPath,$pubPath,$installed,$cliStage
        ])).' 2>&1';
    $lines=[];exec($cli,$lines,$exit);
    $check($exit===0 && is_file($cliStage.'/.ac-stage-complete.json')
        && str_contains(implode("\n",$lines),'"status": "staged_only"'),
        'CLI stages authenticated source and returns explicit staged-only JSON');
    $check(file_get_contents($installed.'/config/license.php')===$oldLicense,
        'CLI also preserves the installed private license');
    echo "Passed {$checks} private source staging checks.\n";
} finally {
    $remove($tmp);
}
