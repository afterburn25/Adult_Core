<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';
use AdultCore\Billing\WebhookInbox;
use AdultCore\Pro\JobEnvelope;
use AdultCore\Theme\ThemeAssets;
$checks=0;
function ensure(bool $condition,string $msg):void{global $checks;if(!$condition)throw new RuntimeException($msg);$checks++;}
$secret=str_repeat('k',32);
$body='{"event":"paid"}';$signature=hash_hmac('sha256',$body,$secret);
ensure(WebhookInbox::verifyHmac($body,$signature,$secret),'Valid HMAC');
ensure(!WebhookInbox::verifyHmac($body.' ', $signature,$secret),'Modified HMAC body');
ensure(!WebhookInbox::verifyHmac($body,$signature,'short'),'Weak secret');
$env=JobEnvelope::create('job-0000001','video.transcode',['asset_id'=>4],$secret);
ensure(JobEnvelope::verify($env,$secret),'Valid Pro job');
$env['data']['payload']['asset_id']=999;
ensure(!JobEnvelope::verify($env,$secret),'Tampered Pro job');
$env=JobEnvelope::create('job-0000002','video.thumbnail',[],$secret);
ensure(!JobEnvelope::verify($env,$secret,time()+999),'Expired Pro job');
try{JobEnvelope::create('job-0000003','shell.exec',[],$secret);throw new RuntimeException('Unsupported job accepted');}catch(RuntimeException $e){ensure($e->getMessage()==='Unsupported job envelope.','Rejected unsupported job type');}
ensure((new ThemeAssets(dirname(__DIR__)))->css('lite',[])===null,'No theme enabled');
echo "Passed $checks advanced foundation tests.\n";
