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
