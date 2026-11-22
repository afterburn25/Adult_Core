<?php

declare(strict_types=1);
require dirname(__DIR__).'/src/Support/Autoload.php';
use AdultCore\Pro\JobInbox;
use AdultCore\Pro\JobEnvelope;
use AdultCore\Support\ReleaseReadiness;
use AdultCore\Media\PrivateMedia;
$checks=0;
function ck(bool $x,string $m):void{global $checks;if(!$x)throw new RuntimeException($m);$checks++;}
$root=sys_get_temp_dir().'/ac-pro-inbox-'.bin2hex(random_bytes(5));mkdir($root);mkdir($root.'/storage');
$secret=str_repeat('s',32);$inbox=new JobInbox($root);
$job=JobEnvelope::create('pro-job-00001','video.transcode',['asset_id'=>7],$secret);
ck($inbox->accept($job,$secret),'Valid envelope recorded');
ck(!$inbox->accept($job,$secret),'Replay blocked');
$conflict=JobEnvelope::create('pro-job-00001','video.transcode',['asset_id'=>999],$secret);
try {$inbox->accept($conflict,$secret);throw new RuntimeException('Conflicting job accepted');}
catch(RuntimeException $e){ck($e->getMessage()!=='Conflicting job accepted','Reused Pro job ID with different asset rejected');}
$receiptPath=$root.'/storage/pro-jobs/'.hash('sha256','pro-job-00001').'.json';
$original=file_get_contents($receiptPath);
file_put_contents($receiptPath,'{"unfinished":');
try {$inbox->accept($job,$secret);throw new RuntimeException('Truncated receipt accepted');}
catch(RuntimeException $e){ck($e->getMessage()!=='Truncated receipt accepted','Truncated Pro job receipt rejected');}
file_put_contents($receiptPath,$original);
$changed=json_decode($original,true,12,JSON_THROW_ON_ERROR);
$changed['job']['payload']['asset_id']=987;
file_put_contents($receiptPath,json_encode($changed,JSON_THROW_ON_ERROR));
try {$inbox->accept($job,$secret);throw new RuntimeException('Altered persisted receipt accepted');}
catch(RuntimeException $e){ck($e->getMessage()!=='Altered persisted receipt accepted','Conflicting on-disk job receipt rejected');}
file_put_contents($receiptPath,$original);
$alteredSignature=json_decode($original,true,12,JSON_THROW_ON_ERROR);
$alteredSignature['signature']=str_repeat('0',64);
file_put_contents($receiptPath,json_encode($alteredSignature,JSON_THROW_ON_ERROR));
try {$inbox->accept($job,$secret);throw new RuntimeException('Tampered receipt signature accepted');}
catch(RuntimeException $e){ck($e->getMessage()!=='Tampered receipt signature accepted','Tampered stored Pro job signature rejected');}
file_put_contents($receiptPath,$original);
$changedStatus=json_decode($original,true,12,JSON_THROW_ON_ERROR);
$changedStatus['status']='done';
file_put_contents($receiptPath,json_encode($changedStatus,JSON_THROW_ON_ERROR));
try{$inbox->accept($job,$secret);throw new RuntimeException('Tampered status accepted');}
catch(RuntimeException $e){ck($e->getMessage()!=='Tampered status accepted',
    'Receipt status cannot be changed without a matching signed MAC');}
$changedTimestamp=json_decode($original,true,12,JSON_THROW_ON_ERROR);
$changedTimestamp['received_at']++;
file_put_contents($receiptPath,json_encode($changedTimestamp,JSON_THROW_ON_ERROR));
try{$inbox->accept($job,$secret);throw new RuntimeException('Tampered receipt timestamp accepted');}
catch(RuntimeException $e){ck($e->getMessage()!=='Tampered receipt timestamp accepted',
    'Receipt timestamp cannot be changed without a matching signed MAC');}
$legacyReceipt=json_decode($original,true,12,JSON_THROW_ON_ERROR);
unset($legacyReceipt['mac']);
file_put_contents($receiptPath,json_encode($legacyReceipt,JSON_THROW_ON_ERROR));
try{$inbox->accept($job,$secret);throw new RuntimeException('Unsigned legacy receipt accepted');}
catch(RuntimeException $e){ck($e->getMessage()!=='Unsigned legacy receipt accepted',
    'Legacy unsealed receipt fails closed for operator review');}
file_put_contents($receiptPath,$original);
ck(!$inbox->accept($job,$secret),'Restored valid job remains idempotent');
$bad=JobEnvelope::create('pro-job-00002','video.transcode',['asset_id'=>7,'path'=>'/etc/passwd'],$secret);
try{$inbox->accept($bad,$secret);throw new RuntimeException('Path payload accepted');}catch(RuntimeException $e){ck($e->getMessage()!=='Path payload accepted','Payload allowlist');}
$job['signature']=str_repeat('0',64);
try{$inbox->accept($job,$secret);throw new RuntimeException('Forged envelope accepted');}catch(RuntimeException $e){ck($e->getMessage()!=='Forged envelope accepted','Signature check');}
ck(!PrivateMedia::validKey('../outside'),'Media traversal rejected');
ck(PrivateMedia::validKey(str_repeat('a',40)),'Opaque image key valid');
ck(PrivateMedia::validMime('image/jpeg'),'Image allowlist');
ck(!PrivateMedia::validMime('application/x-php'),'Executable MIME blocked');
ck(PrivateMedia::validDimensions([2,2]),'Small legitimate private image dimensions accepted');
ck(PrivateMedia::validDimensions([8000,4000]),'Maximum permitted pixel count accepted');
foreach ([[8193,1],[1,8193],[8000,5000],[0,100],[200,0],[-1,400],[],[1.2,1],['500',500],false] as $size) {
    ck(!PrivateMedia::validDimensions($size),'Unsafe or malformed private image dimensions refused');
}
ck(!ReleaseReadiness::productionReady(),'Production release fails closed');
// Never allow a signed receipt to be redirected through a linked storage
// parent, even though the envelope itself is authentic.
$linkRoot=sys_get_temp_dir().'/ac-pro-linked-'.bin2hex(random_bytes(5));
mkdir($linkRoot);
mkdir($linkRoot.'/real-storage');
try {
    if (!symlink($linkRoot.'/real-storage',$linkRoot.'/storage')) {
        throw new RuntimeException('Pro linked storage fixture unavailable.');
    }
    $linkedInbox=new JobInbox($linkRoot);
    $guarded=JobEnvelope::create('pro-storage-link-01','video.thumbnail',['asset_id'=>5],$secret);
    try {
        $linkedInbox->accept($guarded,$secret);
        throw new RuntimeException('Linked storage accepted a signed Pro receipt.');
    } catch (RuntimeException $e) {
        ck($e->getMessage() !== 'Linked storage accepted a signed Pro receipt.',
            'Pro inbox rejects linked storage parent');
    }
    ck(!is_dir($linkRoot.'/real-storage/pro-jobs'),
        'Refused linked Pro storage writes no receipt');
} finally {
    @unlink($linkRoot.'/storage');
    @rmdir($linkRoot.'/real-storage');
    @rmdir($linkRoot);
}
foreach(glob($root.'/storage/pro-jobs/*')?:[] as $p)unlink($p);
rmdir($root.'/storage/pro-jobs');unlink($root.'/storage/.source-http-requests.lock');rmdir($root.'/storage');rmdir($root);
echo "Passed $checks Pro/release checks.\n";
