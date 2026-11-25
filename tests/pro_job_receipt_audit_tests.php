<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Pro\JobInbox;
use AdultCore\Pro\JobEnvelope;
use AdultCore\Pro\ProJobReceiptAudit;

$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{
    if(!$ok)throw new RuntimeException('Pro receipt audit test: '.$label);
    ++$checks;
};
$reject=static function(callable $fn,string $label)use($check):void{
    try{$fn();}catch(RuntimeException){$check(true,$label);return;}
    $check(false,$label);
};
$remove=static function(string $p)use(&$remove):void{
    if(is_link($p)||is_file($p)){unlink($p);return;}
    if(!is_dir($p))return;
    foreach(scandir($p)?:[] as $name) {
        if($name!=='.'&&$name!=='..')$remove($p.'/'.$name);
    }
    rmdir($p);
};
$tmp=sys_get_temp_dir().'/ac-pro-inbox-audit-'.bin2hex(random_bytes(8));
mkdir($tmp,0700);
try{
    $site=$tmp.'/site';mkdir($site,0700);mkdir($site.'/storage',0700);
    $storage=$site.'/storage';
    $secret=str_repeat('v',40);
    $inspect=static fn()=>ProJobReceiptAudit::inspect($site,$secret);
    $empty=$inspect();
    $check($empty['inbox_present']===false&&$empty['signed_receipts_verified']===0
        &&$empty['ready_for_activation']===false
        &&$empty['external_workers_quiesced']===false,
        'Missing receipt inbox never certifies an absent Pro worker');
    $reject(static fn()=>ProJobReceiptAudit::inspect($site,'short'),
        'Short or weak audit secret refused');
    $inbox=new JobInbox($site);
    $job=JobEnvelope::create('verified-job-00001','video.transcode',['asset_id'=>7],$secret);
    $check($inbox->accept($job,$secret),'Persist first signed job receipt');
    $report=$inspect();
    $check($report['inbox_present']===true
        &&$report['signed_receipts_verified']===1
        &&$report['pending_receipts']===1
        &&$report['no_locally_open_receipts']===false
        &&$report['external_workers_quiesced']===false,
        'Pending signed receipt is counted but does not prove worker drain');
    $path=$storage.'/pro-jobs/'.hash('sha256','verified-job-00001').'.json';
    $original=file_get_contents($path);
    $check(is_string($original),'Read original signed receipt fixture');
    $sealed=json_decode($original,true);

    $done=$sealed;
    $done['status']='done';
    $done['mac']=hash_hmac('sha256',
        "adult-core-pro-receipt-v1\n".
        json_encode(array_slice($done,0,4,true),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
        $secret);
    file_put_contents($path,json_encode($done));
    $report=$inspect();
    $check($report['pending_receipts']===0
        &&$report['done_receipts']===1
        &&$report['no_locally_open_receipts']===true
        &&$report['external_workers_quiesced']===false
        &&$report['database_writes_quiesced']===false
        &&$report['ready_for_activation']===false,
        'Locally completed receipt never certifies independent worker/DB quiescence');
    file_put_contents($path,$original);

    $altered=$sealed;
    $altered['job']['payload']['asset_id']=999;
    file_put_contents($path,json_encode($altered));
    $reject($inspect,'Modified signed job data rejected');
    file_put_contents($path,$original);

    $wrongJob=$sealed;
    $wrongJob['signature']=str_repeat('0',64);
    $wrongJob['mac']=hash_hmac('sha256',
        "adult-core-pro-receipt-v1\n".
        json_encode(array_slice($wrongJob,0,4,true),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),
        $secret);
    file_put_contents($path,json_encode($wrongJob));
    $reject($inspect,'Receipt MAC alone cannot conceal forged underlying job envelope');
    file_put_contents($path,$original);

    $wrongStatus=$sealed;
    $wrongStatus['status']=['processing'];
    file_put_contents($path,json_encode($wrongStatus));
    $reject($inspect,'Non-string status safely refused without unhandled offset errors');
    file_put_contents($path,$original);

    $wrongTime=$sealed;
    $wrongTime['received_at']++;
    file_put_contents($path,json_encode($wrongTime));
    $reject($inspect,'Unsealed timestamp change rejected');
    file_put_contents($path,$original);

    file_put_contents($path,'{"unfinished":');
    $reject($inspect,'Truncated invalid JSON rejected');
    file_put_contents($path,$original);

    chmod($path,0644);
    $reject($inspect,'Publicly readable Pro receipt rejected');
    chmod($path,0600);

    $renamed=$storage.'/pro-jobs/'.str_repeat('0',64).'.json';
    rename($path,$renamed);
    $reject($inspect,'Misnamed receipt cannot assume another job identity');
    rename($renamed,$path);

    $extra=$storage.'/pro-jobs/unrecognized.txt';
    file_put_contents($extra,'unexpected');
    $reject($inspect,'Unknown extra entries in inbox rejected');
    unlink($extra);

    $over=$storage.'/pro-jobs/'.str_repeat('1',64).'.json';
    file_put_contents($over,str_repeat('X',ProJobReceiptAudit::MAX_RECORD_BYTES+1));
    chmod($over,0600);
    $reject($inspect,'Oversized receipt rejected before JSON parsing');
    unlink($over);

    if(function_exists('symlink')&&symlink($path,$storage.'/pro-jobs/'.str_repeat('2',64).'.json')){
        $reject($inspect,'Symlink receipt refused');
        unlink($storage.'/pro-jobs/'.str_repeat('2',64).'.json');
    }
    $other=$storage.'/pro-jobs/'.str_repeat('3',64).'.json';
    mkdir($other,0700);
    $reject($inspect,'Directory disguised as receipt refused');
    rmdir($other);

    file_put_contents($storage.'/.source-update-maintenance',"active\n");
    $during=$inspect();
    $check($during['pending_receipts']===1
        &&$during['external_workers_quiesced']===false,
        'Offline signed receipt audit remains read-only during HTTP maintenance');
    unlink($storage.'/.source-update-maintenance');

    $keyFromStdin=$secret."\n";
    $cmd=escapeshellarg(PHP_BINARY).' '
        .escapeshellarg(dirname(__DIR__).'/scripts/audit-pro-job-inbox.php').' '
        .escapeshellarg($site);
    $pipes=[];
    $p=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($p))throw new RuntimeException('Cannot start offline inbox CLI test.');
    fwrite($pipes[0],$keyFromStdin);fclose($pipes[0]);
    $stdout=stream_get_contents($pipes[1]);fclose($pipes[1]);
    $stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);
    $exit=proc_close($p);
    $cli=json_decode($stdout,true);
    $check($exit===0&&is_array($cli)
        &&$cli['signed_receipts_verified']===1
        &&$cli['pending_receipts']===1
        &&$cli['ready_for_activation']===false,
        'Operator CLI audits receipts using secret via stdin and prints redacted counts');
    $check(!str_contains($stdout,$secret)
        &&file_get_contents($path)===$original
        &&$inspect()['signed_receipts_verified']===1,
        'CLI does not leak signing secret or modify an existing signed receipt');
    // The lifecycle receives a trusted test handler, not a command or
    // media path from a signed envelope. No actual media processing occurs.
    mkdir($site.'/config',0700);
    $appKey=bin2hex(random_bytes(32));
    file_put_contents($site.'/config/app-key.php','<?php return '.var_export($appKey,true).';');
    file_put_contents($site.'/config/database.php',"<?php return ['driver'=>'mysql','database'=>'fixture','username'=>'fixture'];");
    $state=$storage.'/install-state.json';
    file_put_contents($state,\AdultCore\Installer\InstallationState::encode('lite',$appKey));
    $lifecycle=new \AdultCore\Pro\ProJobLifecycle($site);
    $ran=0;
    $handler=static function(array $data)use(&$ran):bool{++$ran;return true;};
    $reject(static fn()=>$lifecycle->execute('verified-job-00001',$secret,$handler),'Lite installation cannot execute jobs');
    $check($ran===0&&file_get_contents($path)===$original,'Lite denial preserves pending receipt');
    file_put_contents($state,\AdultCore\Installer\InstallationState::encode('pro',$appKey));
    $reject(static fn()=>$lifecycle->execute('verified-job-00001',str_repeat('x',40),$handler),
        'Wrong worker signing secret refused');
    $check($ran===0&&file_get_contents($path)===$original,'Wrong secret never runs handler or mutates receipt');
    $second=JobEnvelope::create('verified-job-00002','video.thumbnail',['asset_id'=>8],$secret);
    $third=JobEnvelope::create('verified-job-00003','video.thumbnail',['asset_id'=>9],$secret);
    $check($inbox->accept($second,$secret)&&$inbox->accept($third,$secret),'Two additional signed test jobs accepted');
    $operator=\AdultCore\Release\SourceHttpRequestLock::exclusive($site);
    try{
        $reject(static fn()=>$lifecycle->execute('verified-job-00001',$secret,$handler),'Active maintenance guard refuses execution');
    }finally{fclose($operator);}
    $check($lifecycle->execute('verified-job-00001',$secret,static function(array $data)use(
        $check,$reject,$inspect,$site,$lifecycle,$secret,$handler,$inbox,$job,&$ran
    ):bool{
        ++$ran;
        $check($data===$job['data']&&$inspect()['processing_receipts']===1,
            'Original verified envelope and durable processing state precede handler');
        $reject(static fn()=>\AdultCore\Release\BackupMaintenanceAdmission::begin($site),
            'Active local handler blocks backup maintenance');
        $reject(static fn()=>$lifecycle->execute('verified-job-00002',$secret,$handler),
            'Second local worker cannot execute another job concurrently');
        $check(!$inbox->accept($job,$secret),'Receipt replay during processing remains idempotent');
        return true;
    }),'Trusted successful handler publishes done');
    $check($ran===1&&$inspect()['done_receipts']===1,'Successful handler called once and receipt remains authenticated');
    $reject(static fn()=>$lifecycle->execute('verified-job-00001',$secret,$handler),'Done receipt cannot be rerun');
    $check(!$lifecycle->execute('verified-job-00002',$secret,static fn(array $data)=>false),
        'Explicit settled handler failure publishes failed');
    $reject(static fn()=>$lifecycle->execute('verified-job-00002',$secret,$handler),'Failed receipt cannot be rerun');
    $reject(static fn()=>$lifecycle->execute('verified-job-00003',$secret,static function(array $data):bool{
        throw new RuntimeException('Synthetic uncertain worker failure.');
    }),'Uncertain handler failure propagates');
    $report=$inspect();
    $check($report['done_receipts']===1&&$report['failed_receipts']===1&&$report['processing_receipts']===1
        &&$report['pending_receipts']===0&&!$report['no_locally_open_receipts'],
        'Uncertain handler leaves signed processing receipt for review');
    $reject(static fn()=>$lifecycle->execute('verified-job-00003',$secret,$handler),'Uncertain processing receipt cannot be rerun');
    $check($ran===1,'All rejected replays and concurrent executions leave handler count unchanged');
    $owned=\AdultCore\Release\BackupMaintenanceAdmission::begin($site);
    try{
        $reject(static fn()=>$lifecycle->execute('verified-job-00003',$secret,$handler),'Maintenance marker blocks execution');
    }finally{\AdultCore\Release\BackupMaintenanceAdmission::finish($site,$owned['finish_token']);}
    $check((fileperms($storage.'/.pro-worker-execution.lock')&0077)===0,
        'Persistent local worker mutex is owner-only and maintenance can resume after execution');
    echo "Passed {$checks} private Pro receipt integrity audit checks.\n";
}finally{$remove($tmp);}
