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
ck(!$inbox->accept($job,$secret),'Restored valid job remains idempotent');
$bad=JobEnvelope::create('pro-job-00002','video.transcode',['asset_id'=>7,'path'=>'/etc/passwd'],$secret);
try{$inbox->accept($bad,$secret);throw new RuntimeException('Path payload accepted');}catch(RuntimeException $e){ck($e->getMessage()!=='Path payload accepted','Payload allowlist');}
$job['signature']=str_repeat('0',64);
try{$inbox->accept($job,$secret);throw new RuntimeException('Forged envelope accepted');}catch(RuntimeException $e){ck($e->getMessage()!=='Forged envelope accepted','Signature check');}
ck(!PrivateMedia::validKey('../outside'),'Media traversal rejected');
ck(PrivateMedia::validKey(str_repeat('a',40)),'Opaque image key valid');
ck(PrivateMedia::validMime('image/jpeg'),'Image allowlist');
ck(!PrivateMedia::validMime('application/x-php'),'Executable MIME blocked');
ck(!ReleaseReadiness::productionReady(),'Production release fails closed');
foreach(glob($root.'/storage/pro-jobs/*')?:[] as $p)unlink($p);
rmdir($root.'/storage/pro-jobs');rmdir($root.'/storage');rmdir($root);
echo "Passed $checks Pro/release checks.\n";
