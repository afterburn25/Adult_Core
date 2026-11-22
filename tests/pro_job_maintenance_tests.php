<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Pro\JobEnvelope;
use AdultCore\Pro\JobInbox;
use AdultCore\Pro\ProJobAdmission;
use AdultCore\Release\SourceHttpRequestLock;

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Pro maintenance admission: ' . $label);
    ++$checks;
};
$reject = static function (callable $task, string $label) use ($check): void {
    try { $task(); } catch (RuntimeException) { $check(true, $label); return; }
    $check(false, $label);
};
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') $remove($path . '/' . $name);
    }
    rmdir($path);
};
$tmp = sys_get_temp_dir() . '/ac-pro-maintenance-' . bin2hex(random_bytes(8));
mkdir($tmp,0700);
try {
    $root=$tmp.'/site';
    mkdir($root,0700);
    mkdir($root.'/storage',0700);
    $storage=$root.'/storage';
    $secret=str_repeat('k',32);
    $inbox=new JobInbox($root);
    $status=ProJobAdmission::status($root);
    $check($status['job_receipt_admission_open']===true
        && $status['existing_jobs_drained']===false
        && $status['pro_worker_quiesced']===false
        && $status['production_ready']===false,
        'Open receipt inbox does not claim production worker readiness');
    $baseline=JobEnvelope::create('baseline-pro-job-00001','video.thumbnail',['asset_id'=>3],$secret);
    $check($inbox->accept($baseline,$secret),'Signed Pro job admitted before maintenance');
    $oldReceipt=$storage.'/pro-jobs/'.hash('sha256','baseline-pro-job-00001').'.json';
    $beforeHash=hash_file('sha256',$oldReceipt);
    $count=0;
    foreach ([
        '.source-update-maintenance',
        '.backup-maintenance',
        '.backup-restore-pending',
        '.edition-upgrade-pending',
        '.license-update-pending',
    ] as $marker) {
        $path=$storage.'/'.$marker;
        file_put_contents($path,"private-marker\n");
        chmod($path,0600);
        $newId='blocked-pro-job-'.(++$count).'-test';
        $envelope=JobEnvelope::create($newId,'video.transcode',['asset_id'=>4],$secret);
        $expectedReceipt=$storage.'/pro-jobs/'.hash('sha256',$newId).'.json';
        $reject(static fn()=> $inbox->accept($envelope,$secret),
            'New signed Pro job refused during '.$marker);
        $reject(static fn()=> $inbox->accept($baseline,$secret),
            'Even duplicate job receipt cannot bypass '.$marker);
        $check(!file_exists($expectedReceipt),
            'Maintenance refusal never creates a Pro job receipt');
        $check(ProJobAdmission::status($root)['job_receipt_admission_open']===false,
            'Admission status fail-closed for '.$marker);
        unlink($path);
        $check(ProJobAdmission::status($root)['job_receipt_admission_open']===true,
            'Clearing maintenance marker restores normal intake');
    }
    $check(hash_file('sha256',$oldReceipt)===$beforeHash,
        'Maintenance never modifies an existing signed Pro job receipt');

    // Even WITHOUT a marker, an operator may own the exclusive source
    // barrier while preparing to publish one. Pro receipt admission must
    // refuse before creating anything; its status must also say closed.
    $pendingId='blocked-exclusive-pro-0001';
    $pendingEnv=JobEnvelope::create($pendingId,'video.transcode',['asset_id'=>12],$secret);
    $pendingPath=$storage.'/pro-jobs/'.hash('sha256',$pendingId).'.json';
    $exclusive=SourceHttpRequestLock::exclusive($root);
    try{
        $reject(static fn()=>$inbox->accept($pendingEnv,$secret),
            'Operator exclusive source guard refuses signed receipt before marker exists');
        $check(ProJobAdmission::status($root)['job_receipt_admission_open']===false,
            'Status must report receipt admission closed under exclusive operator guard');
        $check(!is_file($pendingPath),'Refused receipt leaves no private signed job file');
    }finally{fclose($exclusive);}
    $check(ProJobAdmission::status($root)['job_receipt_admission_open']===true,
        'Releasing unused exclusive guard restores signed receipt admission');
    $check($inbox->accept($pendingEnv,$secret),
        'Signed receipt succeeds when exclusive operator guard is released');
    $check(!$inbox->accept($pendingEnv,$secret),
        'Replay still idempotent after lock-aware admission');

    // Independent PHP process owns an exclusive flock; the current process
    // must refuse intake until that owner explicitly releases it.
    $holderCode='require '.var_export(dirname(__DIR__).'/src/Support/Autoload.php',true).';'
        .'$guard=\AdultCore\Release\SourceHttpRequestLock::exclusive('
        .var_export($root,true).');'
        .'echo "PRO_GATE_EXCLUSIVE\n";flush();fgets(STDIN);fclose($guard);';
    $cmd=escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($holderCode);
    $pipes=[];
    $proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($proc))throw new RuntimeException('Cannot launch separate Pro operator barrier.');
    stream_set_timeout($pipes[1],8);
    $ready=fgets($pipes[1]);
    $blockedId='cross-process-pro-job-0001';
    $blockedEnv=JobEnvelope::create($blockedId,'video.thumbnail',['asset_id'=>17],$secret);
    $blockedPath=$storage.'/pro-jobs/'.hash('sha256',$blockedId).'.json';
    try{
        $check($ready==="PRO_GATE_EXCLUSIVE\n",
            'Independent process has exclusive source operation lock');
        $reject(static fn()=>$inbox->accept($blockedEnv,$secret),
            'Independent operator exclusive lock prevents new Pro job receipt');
        $check(!is_file($blockedPath)
            &&ProJobAdmission::status($root)['job_receipt_admission_open']===false,
            'Cross-process lock refuses signed receipt without creating partial files');
    }finally{
        fwrite($pipes[0],"\n");fclose($pipes[0]);
        stream_get_contents($pipes[1]);fclose($pipes[1]);
        stream_get_contents($pipes[2]);fclose($pipes[2]);
        $exit=proc_close($proc);
    }
    $check($exit===0,'Independent operator released lock cleanly');
    $check($inbox->accept($blockedEnv,$secret),
        'Signed Pro job admission resumes after cross-process operator lock closes');

    // A malicious alternate lock inode/symlink must fail closed.
    $receiptLock=$storage.'/.source-http-requests.lock';
    $savedLock=$tmp.'/saved-source-request-lock';
    rename($receiptLock,$savedLock);
    if(function_exists('symlink') && symlink($savedLock,$receiptLock)){
        $aliasEnv=JobEnvelope::create('alias-lock-pro-job','video.thumbnail',['asset_id'=>19],$secret);
        $reject(static fn()=>$inbox->accept($aliasEnv,$secret),
            'Signed receipt refuses symbolic-linked source lock alias');
        $check(ProJobAdmission::status($root)['job_receipt_admission_open']===false,
            'Symlinked source lock makes admission status fail closed');
        unlink($receiptLock);
    }
    rename($savedLock,$receiptLock);

    $sourceMarker=$storage.'/.source-update-maintenance';
    if (function_exists('symlink') && symlink($oldReceipt,$sourceMarker)) {
        $env=JobEnvelope::create('symlink-blocked-pro-job','video.thumbnail',['asset_id'=>9],$secret);
        $reject(static fn()=> $inbox->accept($env,$secret),
            'Symlink maintenance marker blocks Pro job intake even without regular file');
        $check(ProJobAdmission::status($root)['job_receipt_admission_open']===false,
            'Symlink marker remains an active denial condition');
        unlink($sourceMarker);
    }
    mkdir($sourceMarker,0700);
    $dirBlocked=JobEnvelope::create('directory-blocked-job','video.thumbnail',['asset_id'=>10],$secret);
    $reject(static fn()=> $inbox->accept($dirBlocked,$secret),
        'Directory at maintenance marker path blocks Pro job intake');
    rmdir($sourceMarker);

    $invalid=$root.'/storage';
    $saved=$root.'/storage-original';
    rename($invalid,$saved);
    if (function_exists('symlink') && symlink($saved,$invalid)) {
        $reject(static fn()=>ProJobAdmission::assertMayAccept($root),
            'Symlink storage root cannot be used for job intake');
        $check(ProJobAdmission::status($root)['job_receipt_admission_open']===false,
            'Unsafe private storage is reported as closed');
        unlink($invalid);
    }
    rename($saved,$invalid);
    $check($inbox->accept(JobEnvelope::create(
        'after-maintenance-job','video.transcode',['asset_id'=>11],$secret
    ),$secret),'Clean signed intake resumes after all maintenance markers are removed');
    $check(ProJobAdmission::status($root)['job_receipt_admission_open']===true
        && ProJobAdmission::status($root)['pro_worker_quiesced']===false,
        'Accepting signed receipts still does not imply worker drain or execution');
    echo "Passed {$checks} Pro job maintenance admission checks.\n";
} finally {
    $remove($tmp);
}
