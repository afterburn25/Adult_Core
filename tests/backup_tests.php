<?php

declare(strict_types=1);
require dirname(__DIR__).'/src/Support/Autoload.php';
use AdultCore\Backup\StreamArchive;
use AdultCore\Backup\SiteBackup;
use AdultCore\Installer\InstallationGate;

$n=0;
function backupAssert(bool $okay,string $text):void{global $n;if(!$okay)throw new RuntimeException('FAIL: '.$text);$n++;}
function backupReject(callable $run,string $text):void{try{$run();}catch(RuntimeException|JsonException){backupAssert(true,$text);return;}throw new RuntimeException('FAIL: '.$text);}
$key=bin2hex(random_bytes(32));$tmp=sys_get_temp_dir().'/ac-backup-unit-'.bin2hex(random_bytes(7));mkdir($tmp,0700);
$path=$tmp.'/portable.acfull';$payload=random_bytes(240000);$items=[];
try {
    StreamArchive::write($path,$key,static function(callable $emit)use($payload):void{
        $emit(['type'=>'meta','source_version'=>'unit','edition'=>'lite','tables'=>5]);
        for($pos=0;$pos<strlen($payload);$pos+=65536)$emit(['type'=>'file_chunk','data'=>base64_encode(substr($payload,$pos,65536))]);
        $emit(['type'=>'end','tables'=>5,'rows'=>0,'files'=>1,'file_bytes'=>strlen($payload)]);
    });
    StreamArchive::read($path,$key,static function(array $record)use(&$items):void{$items[]=$record;});
    backupAssert(count($items)===6,'Streaming archive emits authenticated records');
    backupAssert($items[0]['edition']==='lite'&&$items[5]['type']==='end','Archive record order and final marker');
    backupAssert(!str_contains((string)file_get_contents($path),base64_encode(substr($payload,0,60))),'No plaintext file chunk in archive');
    backupReject(static fn()=>StreamArchive::read($path,bin2hex(random_bytes(32)),static fn(array $r)=>null),'Wrong encryption key denied');
    backupReject(static fn()=>StreamArchive::write($path,$key,static fn($emit)=>$emit(['type'=>'end'])),'Cannot overwrite existing archive');
    backupReject(static fn()=>StreamArchive::write($tmp.'/weak.acfull','bad-key',static fn($emit)=>$emit(['type'=>'end'])),'Weak key denied');
    backupAssert(!is_file($tmp.'/weak.acfull'),'Invalid-key attempt cleans empty output');
    $authenticated=StreamArchive::readVerified($path,$key,static fn(array $r)=>null);
    backupAssert($authenticated['records']===6
        &&$authenticated['sha256']===hash_file('sha256',$path),'Digest covers exactly the authenticated encrypted stream');
    backupAssert((fileperms($path)&0077)===0,'New archive is created owner-only');
    $swapped=$tmp.'/swapped.acfull';
    copy($path,$swapped);
    $replacement=$tmp.'/replacement.acfull';
    StreamArchive::write($replacement,$key,static fn($emit)=>$emit(['type'=>'end']));
    $replacementHash=hash_file('sha256',$replacement);
    $swappedDuringRead=false;
    backupReject(static function()use($swapped,$replacement,$key,&$swappedDuringRead):void{
        StreamArchive::readVerified($swapped,$key,static function(array $r)use(
            $swapped,$replacement,&$swappedDuringRead
        ):void{
            if(!$swappedDuringRead){
                $swappedDuringRead=true;
                if(!rename($replacement,$swapped))throw new RuntimeException('Cannot swap test archive.');
            }
        });
    },'Replacing the path during an authenticated read fails closed');
    backupAssert($swappedDuringRead&&hash_file('sha256',$swapped)===$replacementHash,
        'Rejected read preserves the substituted file for investigation');
    $output=$tmp.'/swapped-output.acfull';
    backupReject(static function()use($output,$tmp,$key):void{
        StreamArchive::write($output,$key,static function($emit)use($output,$tmp):void{
            $emit(['type'=>'meta']);
            if(!rename($output,$tmp.'/old-output.acfull'))throw new RuntimeException('Cannot move output fixture.');
            file_put_contents($output,'unrelated replacement');
            $emit(['type'=>'end']);
        });
    },'Replacing archive output during writing refuses success');
    backupAssert(file_get_contents($output)==='unrelated replacement',
        'Failed writer does not unlink a replacement file it did not create');
    $bytes=file_get_contents($path);$rows=explode("\n",trim($bytes));
    file_put_contents($tmp.'/truncated.acfull',implode("\n",array_slice($rows,0,-1))."\n");
    backupReject(static fn()=>StreamArchive::read($tmp.'/truncated.acfull',$key,static fn(array $r)=>null),'Truncated archive denied');
    // Change an actual encrypted byte deterministically. Replacing one
    // randomly generated base64 character with '0' was flaky: it could
    // already be '0', leaving the authenticated archive unchanged.
    $broken=$rows;
    $encrypted=json_decode($broken[2],true,8,JSON_THROW_ON_ERROR);
    $ciphertext=base64_decode($encrypted['ciphertext'],true);
    if(!is_string($ciphertext)||$ciphertext==='')throw new RuntimeException('Missing ciphertext test fixture.');
    $ciphertext[0]=chr(ord($ciphertext[0]) ^ 1);
    $encrypted['ciphertext']=base64_encode($ciphertext);
    $broken[2]=json_encode($encrypted,JSON_THROW_ON_ERROR);
    file_put_contents($tmp.'/modified.acfull',implode("\n",$broken)."\n");
    backupReject(static fn()=>StreamArchive::read($tmp.'/modified.acfull',$key,static fn(array $r)=>null),'Tampered ciphertext denied');
    // A deterministic authentication-tag mutation must also be refused.
    $tagged=$rows;
    $authentication=json_decode($tagged[2],true,8,JSON_THROW_ON_ERROR);
    $tag=base64_decode($authentication['tag'],true);
    if(!is_string($tag)||$tag==='')throw new RuntimeException('Missing tag test fixture.');
    $tag[0]=chr(ord($tag[0]) ^ 1);
    $authentication['tag']=base64_encode($tag);
    $tagged[2]=json_encode($authentication,JSON_THROW_ON_ERROR);
    file_put_contents($tmp.'/tag-modified.acfull',implode("\n",$tagged)."\n");
    backupReject(static fn()=>StreamArchive::read($tmp.'/tag-modified.acfull',$key,static fn(array $r)=>null),'Tampered authentication tag denied');
    $replay=$rows;$replay[2]=$replay[1];
    file_put_contents($tmp.'/replay.acfull',implode("\n",$replay)."\n");
    backupReject(static fn()=>StreamArchive::read($tmp.'/replay.acfull',$key,static fn(array $r)=>null),'Replayed/reordered encrypted record denied');
    file_put_contents($tmp.'/trailing.acfull',$bytes.'INJECTION');
    backupReject(static fn()=>StreamArchive::read($tmp.'/trailing.acfull',$key,static fn(array $r)=>null),'Trailing unauthenticated bytes denied');
    foreach(['storage/private-media/photo.webp','storage/pro-video-work/authorized.mp4','storage/pro-video-work/thumbnail.jpg','storage/design-assets/figure.png','storage/packages/registry.json',
        'config/app-key.php','config/trusted-publishers/owner.pem','config/pro-agent-secret.php',
        'storage/packages/module-licenses/paid-chat.php','storage/packages/module-licenses/paid-chat.json'] as $candidate){
        backupAssert(SiteBackup::safePath($candidate),'Allowed private path: '.$candidate);
    }
    foreach(['../../public/index.php','config/database.php','config/../public/index.php','storage/sessions/secret',
        'storage/member-sessions/cookie','storage/pro-video-work/.thumbnail-interrupted.jpg','storage/.license-update.lock','storage/.license-update-pending','storage/.edition-upgrade-pending','storage/install-audit.jsonl',
        'storage/packages/module-licenses/.lock','storage/packages/module-licenses/.pending-paid-chat',
        'config/trusted-publishers/../app-key.php','storage//empty','storage/private-media/a\\b'] as $candidate){
        backupAssert(!SiteBackup::safePath($candidate),'Blocked path: '.$candidate);
    }
    $root=$tmp.'/gate';mkdir($root,0700);mkdir($root.'/storage',0700);
    file_put_contents($root.'/storage/.backup-restore-pending','unfinished');
    backupAssert((new InstallationGate($root))->status()===InstallationGate::BACKUP_RESTORE_PENDING,'Unfinished recovery locks all HTTP routes');
    @unlink($root.'/storage/.backup-restore-pending');@rmdir($root.'/storage');@rmdir($root);
    echo "Passed $n portable recovery security tests.\n";
} finally {
    foreach(glob($tmp.'/*')?:[] as $file)@unlink($file);
    @rmdir($tmp);
}
