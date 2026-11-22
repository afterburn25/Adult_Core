<?php

declare(strict_types=1);
require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Release\SourceArchive;
use AdultCore\Release\SourceStage;
use AdultCore\Release\SourceStageVerifier;

$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{
    if(!$ok)throw new RuntimeException('Stage verification: '.$label);
    ++$checks;
};
$reject=static function(callable $fn,string $label)use($check):void{
    try{$fn();}catch(RuntimeException){$check(true,$label);return;}
    $check(false,$label);
};
$remove=static function(string $path)use(&$remove):void{
    if(is_link($path)||is_file($path)){unlink($path);return;}
    if(!is_dir($path))return;
    foreach(scandir($path)?:[] as $item){
        if($item!=='.'&&$item!=='..')$remove($path.'/'.$item);
    }
    rmdir($path);
};
$repo=dirname(__DIR__);
$tmp=sys_get_temp_dir().'/ac-stage-audit-'.bin2hex(random_bytes(8));
if(!mkdir($tmp,0700))throw new RuntimeException('Unable to create disposable test directory.');
try{
    $zip=$tmp.'/candidate.zip';
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($repo.'/scripts/build-release.php')
        .' '.escapeshellarg($zip).' 2>&1',$buildLines,$status);
    $check($status===0&&is_file($zip),'Real unified Lite/Pro source ZIP built');
    $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
    if($key===false)throw new RuntimeException('No disposable signing key.');
    $trusted=openssl_pkey_get_details($key)['key'];
    $manifest=SourceArchive::manifestJson($zip);
    openssl_sign($manifest,$raw,$key,OPENSSL_ALGO_SHA256);
    $signature=base64_encode($raw);
    $inventory=SourceArchive::verifiedInventory($zip,$manifest,$signature,$trusted);
    $installed=$tmp.'/installed';
    mkdir($installed,0700);mkdir($installed.'/src',0700);
    mkdir($installed.'/src/Support',0700);mkdir($installed.'/public',0700);
    mkdir($installed.'/config',0700);
    file_put_contents($installed.'/VERSION',"0.8.11-old-dev\n");
    copy($repo.'/public/index.php',$installed.'/public/index.php');
    copy($repo.'/src/Support/Autoload.php',$installed.'/src/Support/Autoload.php');
    file_put_contents($installed.'/config/license.php',"private-customer-license\n");
    $stage=$tmp.'/stage';
    SourceStage::prepare($installed,$zip,$manifest,$signature,$trusted,$stage);
    $audit=static fn()=>SourceStageVerifier::verify($stage,$zip,$manifest,$signature,$trusted);
    $report=$audit();
    $check($report['stage_verified']===true&&$report['ready_for_install']===false
        &&$report['verified_files']===count($inventory['files'])
        &&$report['unexpected_files']===0,'All signed files verified without permitting installation');
    $check($report['version']===trim((string)file_get_contents($repo.'/VERSION')),
        'Version agrees with authenticated candidate');
    $reject(static fn()=>SourceStageVerifier::verify(
        $stage,$zip,$manifest,base64_encode(random_bytes(256)),$trusted
    ),'Forged source signature denied');
    $php=$stage.'/public/index.php';
    $old=file_get_contents($php);
    file_put_contents($php,"<?php echo 'tampered';");
    $reject($audit,'Modified staged PHP rejected');
    file_put_contents($php,$old);
    $check($audit()['stage_verified']===true,'Original signed bytes restored');
    $extra=$stage.'/public/unlisted.php';
    file_put_contents($extra,"<?php");chmod($extra,0600);
    $reject($audit,'Extra executable file rejected');
    unlink($extra);
    rename($php,$tmp.'/temporarily-removed.php');
    $reject($audit,'Missing signed file rejected');
    rename($tmp.'/temporarily-removed.php',$php);
    $check($audit()['stage_verified']===true,'Restored signed file accepted');
    file_put_contents($stage.'/.ac-stage-pending',"incomplete\n");
    chmod($stage.'/.ac-stage-pending',0600);
    $reject($audit,'Pending marker refuses completed status');
    unlink($stage.'/.ac-stage-pending');
    chmod($php,0644);
    $reject($audit,'Publicly readable staged PHP refused');
    chmod($php,0600);
    $receiptPath=$stage.'/.ac-stage-complete.json';
    $receipt=file_get_contents($receiptPath);
    $wrong=json_decode($receipt,true);$wrong['version']='9.9.9-fake';
    file_put_contents($receiptPath,json_encode($wrong));
    $reject($audit,'Altered completion receipt refused');
    file_put_contents($receiptPath,$receipt);
    if(function_exists('symlink')&&symlink($php,$stage.'/public/link.php')){
        $reject($audit,'Staged symbolic link refused');
        unlink($stage.'/public/link.php');
    }
    $check($audit()['stage_verified']===true,'Unmodified complete stage revalidates');
    $check(file_get_contents($installed.'/config/license.php')==="private-customer-license\n"
        &&file_get_contents($installed.'/VERSION')==="0.8.11-old-dev\n",
        'Audit never changes installed customer source or private license');
    $manifestPath=$tmp.'/manifest.json';
    $sigPath=$tmp.'/manifest.sig';$keyPath=$tmp.'/trusted.pem';
    file_put_contents($manifestPath,$manifest);
    file_put_contents($sigPath,$signature);
    file_put_contents($keyPath,$trusted);
    $command=escapeshellarg(PHP_BINARY).' '
        .escapeshellarg($repo.'/scripts/verify-source-stage.php').' '
        .implode(' ',array_map('escapeshellarg',[
            $stage,$zip,$manifestPath,$sigPath,$keyPath
        ])).' 2>&1';
    $out=[];exec($command,$out,$code);
    $check($code===0&&str_contains(implode("\n",$out),'"stage_verified": true')
        &&str_contains(implode("\n",$out),'"ready_for_install": false'),
        'CLI authenticates stage without authorizing deployment');
    echo "Passed {$checks} staged source integrity checks.\n";
}finally{$remove($tmp);}
