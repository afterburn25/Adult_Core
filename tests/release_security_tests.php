<?php

declare(strict_types=1);
require dirname(__DIR__).'/src/Support/Autoload.php';
use AdultCore\Backup\EncryptedSnapshot;
use AdultCore\Release\SignedManifest;
$checks=0;
function testCheck(bool $yes,string $desc): void {global $checks; if (!$yes)throw new RuntimeException('FAIL: '.$desc);$checks++;}
function testReject(callable $call,string $desc):void{try{$call();}catch(RuntimeException){testCheck(true,$desc);return;}throw new RuntimeException('FAIL: '.$desc);}
$key=bin2hex(random_bytes(32));
$plain=json_encode(['version'=>1,'tables'=>['ac_test'=>[['a'=>1],['a'=>2]]]],JSON_THROW_ON_ERROR);
$encrypted=EncryptedSnapshot::seal($plain,$key);
testCheck(EncryptedSnapshot::open($encrypted,$key)===$plain,'Encrypted snapshot roundtrip');
testCheck(!str_contains($encrypted,'ac_test'),'No cleartext database rows exposed');
testReject(fn()=>EncryptedSnapshot::open($encrypted,bin2hex(random_bytes(32))),'Wrong backup key blocked');
$changed=json_decode($encrypted,true);$changed['ciphertext']=base64_encode(str_repeat('a',20));
testReject(fn()=>EncryptedSnapshot::open(json_encode($changed),$key),'Tampered archive blocked');
testReject(fn()=>EncryptedSnapshot::seal($plain,'bad-key'),'Weak backup key blocked');
testReject(fn()=>EncryptedSnapshot::open(str_repeat('a',23*1024*1024+1),$key),'Excessive backup blocked');
$rsa=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
testCheck($rsa!==false,'Temporary release signing key');
$public=openssl_pkey_get_details($rsa)['key'];
$manifest=['format'=>'adult-core-release-v1','version'=>'0.8.2-dev','files'=>[['path'=>'public/index.php','sha256'=>str_repeat('a',64)]]];
$json=json_encode($manifest,JSON_THROW_ON_ERROR);
openssl_sign($json,$sig,$rsa,OPENSSL_ALGO_SHA256);
testCheck(SignedManifest::verify($json,base64_encode($sig),$public)===$manifest,'Signed release manifest verified');
testReject(fn()=>SignedManifest::verify($json.' ' ,base64_encode($sig),$public),'Tampered signed manifest blocked');
testReject(fn()=>SignedManifest::verify($json,base64_encode(random_bytes(strlen($sig))),$public),'Untrusted signature blocked');
$manifest['files'][0]['path']='../../config/app-key.php';$bad=json_encode($manifest,JSON_THROW_ON_ERROR);openssl_sign($bad,$badSig,$rsa,OPENSSL_ALGO_SHA256);
testReject(fn()=>SignedManifest::verify($bad,base64_encode($badSig),$public),'Signed path traversal blocked');
echo "Passed $checks release security checks.\n";
