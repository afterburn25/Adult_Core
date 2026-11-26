<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/Support/Autoload.php';

use AdultCore\Installer\InstallationState;
use AdultCore\Pro\JobEnvelope;
use AdultCore\Pro\JobInbox;
use AdultCore\Pro\ProJobLifecycle;
use AdultCore\Pro\ProJobReceiptAudit;
use AdultCore\Pro\ProQueueRunner;
use AdultCore\Release\BackupMaintenanceAdmission;

$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{
    if(!$ok)throw new RuntimeException($message);
    ++$checks;
};
$reject=static function(callable $fn,string $message)use($check):void{
    try{$fn();}catch(RuntimeException){$check(true,$message);return;}
    $check(false,$message);
};
$remove=static function(string $p)use(&$remove):void{
    if(is_link($p)||is_file($p)){unlink($p);return;}
    if(!is_dir($p))return;
    foreach(new FilesystemIterator($p,FilesystemIterator::SKIP_DOTS) as $entry)
        $remove($entry->getPathname());
    rmdir($p);
};
$site=sys_get_temp_dir().'/ac-pro-queue-'.bin2hex(random_bytes(8));
mkdir($site,0700);
try{
    mkdir($site.'/storage',0700);mkdir($site.'/config',0700);
    $secret=str_repeat('q',40);$key=bin2hex(random_bytes(32));
    file_put_contents($site.'/config/app-key.php','<?php return '.var_export($key,true).';');
    file_put_contents($site.'/config/database.php',"<?php return ['driver'=>'mysql','database'=>'fixture','username'=>'fixture'];");
    $state=$site.'/storage/install-state.json';
    file_put_contents($state,InstallationState::encode('lite',$key));
    $inbox=new JobInbox($site);$runner=new ProQueueRunner($site);
    $lifecycle=new ProJobLifecycle($site);$ran=[];
    $handler=static function(array $data)use(&$ran):bool{$ran[]=$data['id'];return true;};
    $reject(static fn()=>$runner->runNext($secret,$handler),'Lite cannot dispatch');
    file_put_contents($state,InstallationState::encode('pro',$key));
    $check($runner->runNext($secret,$handler)==='idle'&&$ran===[],'Empty queue is idle');
    // Explicit signed receipt timestamps make ordering deterministic.
    $now=time();
    $seed=static function(string $id,int $received)use($site,$inbox,$secret,$now):void{
        $inbox->accept(JobEnvelope::create($id,'video.thumbnail',['asset_id'=>42],$secret),$secret);
        $path=$site.'/storage/pro-jobs/'.hash('sha256',$id).'.json';
        $record=json_decode(file_get_contents($path),true,12,JSON_THROW_ON_ERROR);
        $record['received_at']=$received;
        $record['mac']=hash_hmac('sha256',"adult-core-pro-receipt-v1\n".
            json_encode(array_slice($record,0,4,true),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$secret);
        file_put_contents($path,json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
    };
    $seed('queue-job-00003',$now);
    $seed('queue-job-00002',$now-1);
    $seed('queue-job-00001',$now-1);
    $check(ProJobReceiptAudit::oldestPending($site,$secret)==='queue-job-00001','Timestamp and ID ordering');
    $reject(static fn()=>$lifecycle->execute('queue-job-00003',$secret,$handler,true),'Stale or nonoldest claim refused');
    $reject(static fn()=>$runner->runNext(str_repeat('x',40),$handler),'Incorrect secret refused');
    $marker=BackupMaintenanceAdmission::begin($site);
    try{$reject(static fn()=>$runner->runNext($secret,$handler),'Maintenance blocks automatic dispatch');}
    finally{BackupMaintenanceAdmission::finish($site,$marker['finish_token']);}
    $check($ran===[],'Denied claims never invoke handler');
    $check($runner->runNext($secret,$handler)==='done'&&$ran===['queue-job-00001'],'Oldest receipt executed');
    $check($runner->runNext($secret,static fn(array $data):bool=>false)==='failed','Settled failure recorded');
    $check($runner->runNext($secret,$handler)==='done'&&$ran===['queue-job-00001','queue-job-00003'],'Next pending receipt executes');
    $check($runner->runNext($secret,$handler)==='idle','Terminal receipts are not retried');
    $seed('queue-job-00004',time());$seed('queue-job-00005',time());
    $reject(static fn()=>$runner->runNext($secret,static function(array $data):bool{
        throw new RuntimeException('Simulated interrupted job');
    }),'Handler interruption propagates');
    $reject(static fn()=>$runner->runNext($secret,$handler),'Unresolved processing receipt blocks next dispatch');
    $report=ProJobReceiptAudit::inspect($site,$secret);
    $check($report['processing_receipts']===1&&$report['pending_receipts']===1&&count($ran)===2,'No pending work dispatched after interruption');
    echo "Passed {$checks} Pro queue runner checks.\n";
}finally{$remove($site);}
