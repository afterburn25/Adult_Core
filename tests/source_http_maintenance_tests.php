<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Installer\InstallationGate;
use AdultCore\Installer\InstallationState;
use AdultCore\Release\SourceOperationLease;
use AdultCore\Release\SourceUpdateMaintenance;
use AdultCore\Release\SourceHttpRequestLock;
use AdultCore\Release\BackupMaintenanceAdmission;
use AdultCore\Pro\JobInbox;
use AdultCore\Pro\JobEnvelope;
use AdultCore\Pro\ProWorkerExecutionGuard;
use AdultCore\Pro\ProSourceMaintenanceReceiptSnapshot;

$passed=0;
$check=static function(bool $value,string $label) use (&$passed):void{
    if (!$value) throw new RuntimeException('Source HTTP maintenance: '.$label);
    ++$passed;
};
$reject=static function(callable $task,string $label)use($check):void{
    try {$task();}catch(RuntimeException){$check(true,$label);return;}
    $check(false,$label);
};
$remove=static function(string $path)use(&$remove):void{
    if(is_file($path)||is_link($path)){unlink($path);return;}
    if(!is_dir($path))return;
    foreach(scandir($path)?:[] as $entry) {
        if($entry!=='.'&&$entry!=='..')$remove($path.'/'.$entry);
    }
    rmdir($path);
};
$repo=dirname(__DIR__);
$tmp=sys_get_temp_dir().'/ac-source-http-maintenance-'.bin2hex(random_bytes(8));
mkdir($tmp,0700);
try{
    $site=$tmp.'/installed'; $coord=$tmp.'/private-coordination';
    mkdir($site,0700);mkdir($coord,0700);mkdir($site.'/storage',0700);
    mkdir($site.'/config',0700);mkdir($site.'/public',0700);
    mkdir($site.'/src',0700);mkdir($site.'/src/Support',0700);
    mkdir($site.'/src/Installer',0700);
    mkdir($site.'/src/Release',0700);
    foreach([
        'public/index.php',
        'src/Support/Autoload.php',
        'src/Installer/InstallationGate.php',
        'src/Installer/InstallationState.php',
        'src/Installer/LockedScreen.php',
        'src/Release/SourceHttpRequestLock.php',
    ] as $p) {
        if(!copy($repo.'/'.$p,$site.'/'.$p)){
            throw new RuntimeException('Unable to copy HTTP maintenance fixture '.$p);
        }
    }
    $key=str_repeat('a',64);
    file_put_contents($site.'/VERSION',"0.8.12-dev\n");
    file_put_contents($site.'/config/app-key.php',"<?php return '".$key."';\n");
    file_put_contents($site.'/config/database.php',
        "<?php return ['driver'=>'mysql','database'=>'testdb','username'=>'tester'];\n");
    file_put_contents($site.'/config/license.php',"<?php return ['secret'=>'DO-NOT-ALTER'];\n");
    file_put_contents($site.'/storage/install-state.json',InstallationState::encode('lite',$key));
    $originalLicense=file_get_contents($site.'/config/license.php');
    $originalVersion=file_get_contents($site.'/VERSION');
    $gate=new InstallationGate($site);
    $check($gate->status()===InstallationGate::READY,'Signed installed fixture initially ready');
    $check(SourceUpdateMaintenance::status($site,$coord)['status']==='inactive',
        'Maintenance initially inactive and cannot claim activation readiness');
    $reject(static fn()=>SourceUpdateMaintenance::begin($site,$coord,str_repeat('0',64)),
        'Cannot begin HTTP maintenance without an owned lease');
    $check(!file_exists($site.'/storage/.source-update-maintenance'),
        'Refused begin creates no marker');

    // The real cron backup entry point now shares the lifetime lock with
    // active PHP requests and signed Pro receipt writers. It cannot set the
    // maintenance marker during an active reader.
    $inflightBackupReader=SourceHttpRequestLock::enter($site);
    $reject(static fn()=>BackupMaintenanceAdmission::begin($site),
        'Backup maintenance refuses active dynamic HTTP or Pro receipt reader');
    $check(!file_exists($site.'/storage/.backup-maintenance'),
        'Rejected backup maintenance never publishes a premature marker');
    fclose($inflightBackupReader);

    $backupBegin=BackupMaintenanceAdmission::begin($site);
    $backupToken=$backupBegin['finish_token'];
    $backupMarker=$site.'/storage/.backup-maintenance';
    $check($backupBegin['status']==='backup_http_and_receipt_admission_blocked'
        &&$backupBegin['signed_receipt_writers_drained_at_entry']===true
        &&$backupBegin['external_pro_workers_stopped']===false
        &&$backupBegin['database_writers_quiesced']===false
        &&$backupBegin['production_ready']===false
        &&$gate->status()===InstallationGate::BACKUP_MAINTENANCE,
        'Private backup marker blocks the site without claiming Pro/DB quiescence');
    $check(is_file($backupMarker)
        &&(fileperms($backupMarker)&0077)===0
        &&preg_match('/^[a-f0-9]{64}$/D',$backupToken)===1
        &&!str_contains((string)file_get_contents($backupMarker),$backupToken)
        &&$gate->eligibleForBackup(),
        'Private backup marker stores only the digest of the random finish token');
    $reject(static fn()=>BackupMaintenanceAdmission::finish($site,str_repeat('0',64)),
        'Wrong owner token cannot reopen backup maintenance');
    $reject(static fn()=>BackupMaintenanceAdmission::finish($site,'bad-token'),
        'Malformed owner token cannot reopen backup maintenance');
    $check($gate->status()===InstallationGate::BACKUP_MAINTENANCE,
        'Refused finish does not clear the original backup maintenance marker');

    $backupTestInbox=new JobInbox($site);
    // One installed Pro site uses one trusted local job-receipt HMAC secret.
    $backupTestSecret=str_repeat('p',32);
    $backupDenied=JobEnvelope::create('backup-colliding-pro-0001',
        'video.thumbnail',['asset_id'=>51],$backupTestSecret);
    $reject(static fn()=>$backupTestInbox->accept($backupDenied,$backupTestSecret),
        'New signed Pro receipts refuse active backup marker');
    $reject(static fn()=>BackupMaintenanceAdmission::begin($site),
        'An existing backup marker never admits a second backup');
    $originalBackupMarker=file_get_contents($backupMarker);
    file_put_contents($backupMarker,"corrupt untrusted marker\n");
    chmod($backupMarker,0600);
    $reject(static fn()=>BackupMaintenanceAdmission::finish($site,$backupToken),
        'Corrupted backup marker is retained even when caller knows its owner token');
    $check($gate->status()===InstallationGate::BACKUP_MAINTENANCE,
        'Incomplete backup marker continues to deny dynamic HTTP');
    file_put_contents($backupMarker,$originalBackupMarker);
    chmod($backupMarker,0600);
    BackupMaintenanceAdmission::finish($site,$backupToken);
    $reject(static fn()=>BackupMaintenanceAdmission::finish($site,$backupToken),
        'Consumed backup owner token cannot finish again without a marker');
    $check($gate->status()===InstallationGate::READY
        &&!file_exists($backupMarker),
        'Only a verified unchanged backup marker can reopen the site');
    $check($backupTestInbox->accept($backupDenied,$backupTestSecret),
        'Signed Pro receipt resumes after completed safe backup maintenance');

    $claim=SourceOperationLease::acquire($site,$coord);
    $token=$claim['release_token'];
    $check(SourceOperationLease::matches($site,$coord,$token),
        'One-time coordinator token authenticates same installed root');
    $check(!SourceOperationLease::matches($site,$coord,str_repeat('f',64)),
        'Wrong token never authenticates maintenance ownership');
    // Local execution workers must be Pro-edition and must retain the
    // SAME shared lifetime guard as HTTP and signed job receipt intake.
    $reject(static fn()=>ProWorkerExecutionGuard::enter($site),
        'Lite signed installation cannot start a Pro worker');
    $afterLiteRefusal=SourceHttpRequestLock::exclusive($site);
    $check(is_resource($afterLiteRefusal),
        'Refused Lite worker releases its shared source lock');
    fclose($afterLiteRefusal);
    file_put_contents($site.'/storage/install-state.json',
        InstallationState::encode('pro',$key));
    $worker=ProWorkerExecutionGuard::enter($site);
    try {
        $check(is_resource($worker),'Signed Pro installation admits a cooperative job guard');
        $reject(static fn()=>BackupMaintenanceAdmission::begin($site),
            'Active local Pro execution prevents backup marker entry');
        $reject(static fn()=>SourceUpdateMaintenance::begin($site,$coord,$token),
            'Active local Pro execution prevents source marker entry');
        $check($gate->status()===InstallationGate::READY,
            'Blocked maintenance leaves signed Pro site ready and unmodified');
    } finally {
        fclose($worker);
    }
    $exclusiveWorkerTest=SourceHttpRequestLock::exclusive($site);
    try {
        $reject(static fn()=>ProWorkerExecutionGuard::enter($site),
            'Operator exclusive guard refuses new Pro work before its marker exists');
    } finally {
        fclose($exclusiveWorkerTest);
    }

    // Independent executor process must hold its shared guard until the
    // operator has actually observed the job's completion.
    $workerCode='require '.var_export($repo.'/src/Support/Autoload.php',true).';'
        .'$guard=\\AdultCore\\Pro\\ProWorkerExecutionGuard::enter('
        .var_export($site,true).');'
        .'echo "PRO_WORKER_RUNNING\\n";flush();fgets(STDIN);fclose($guard);';
    $workerCmd=escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($workerCode);
    $workerPipes=[];
    $workerProcess=proc_open($workerCmd,
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$workerPipes);
    if(!is_resource($workerProcess))throw new RuntimeException('Cannot start disposable Pro worker holder.');
    stream_set_timeout($workerPipes[1],8);
    $workerReady=fgets($workerPipes[1]);
    try {
        $check($workerReady==="PRO_WORKER_RUNNING\n",
            'Independent Pro worker acquired its signed-edition lifetime guard');
        $reject(static fn()=>BackupMaintenanceAdmission::begin($site),
            'Active independent Pro worker prevents local backup entry');
        $reject(static fn()=>SourceUpdateMaintenance::begin($site,$coord,$token),
            'Active independent Pro worker prevents source maintenance entry');
    } finally {
        fwrite($workerPipes[0],"\n");fclose($workerPipes[0]);
        stream_get_contents($workerPipes[1]);fclose($workerPipes[1]);
        stream_get_contents($workerPipes[2]);fclose($workerPipes[2]);
        $workerExit=proc_close($workerProcess);
    }
    $check($workerExit===0,'Independent Pro worker guard released on process exit');
    $workerAfter=ProWorkerExecutionGuard::enter($site);
    fclose($workerAfter);
    // A newly published backup marker must also deny any Pro worker
    // that starts AFTER the prior execution guard was released.
    $idleBackup=BackupMaintenanceAdmission::begin($site);
    try {
        $reject(static fn()=>ProWorkerExecutionGuard::enter($site),
            'New Pro execution refuses the active backup maintenance marker');
    } finally {
        BackupMaintenanceAdmission::finish($site,$idleBackup['finish_token']);
    }
    $check($gate->status()===InstallationGate::READY,
        'Finished backup maintenance reopens signed Pro worker admission');
    $workerResume=ProWorkerExecutionGuard::enter($site);
    fclose($workerResume);
    file_put_contents($site.'/storage/install-state.json',
        InstallationState::encode('lite',$key));
    $check($gate->status()===InstallationGate::READY,
        'Pro execution fixture restores original signed Lite edition');

    // An actual signed Pro receipt uses the same lifetime shared barrier
    // as HTTP. Receipts created before maintenance remain untouched.
    $proInbox=new JobInbox($site);
    $proSecret=str_repeat('p',32);
    $beforePro=JobEnvelope::create('source-pro-guard-0001','video.transcode',
        ['asset_id'=>44],$proSecret);
    $check($proInbox->accept($beforePro,$proSecret),
        'Pro receipt writer can safely admit before source marker is published');
    $proReceipt=$site.'/storage/pro-jobs/'.hash('sha256','source-pro-guard-0001').'.json';
    $oldProHash=hash_file('sha256',$proReceipt);
    // Real requests hold a shared flock until their PHP execution ends.
    // Operator maintenance may not begin while any request is in flight.
    $requestLease=SourceHttpRequestLock::enter($site);
    $check(is_resource($requestLease),'Dynamic request obtains lifetime source lock');
    $lockFile=$site.'/storage/.source-http-requests.lock';
    $check(is_file($lockFile)&&(fileperms($lockFile)&0077)===0
        &&filesize($lockFile)===0,'Persistent request lock is private and holds no secret');
    $secondLease=SourceHttpRequestLock::enter($site);
    $check(is_resource($secondLease),'Parallel HTTP requests hold shared locks');
    $reject(static fn()=>SourceHttpRequestLock::exclusive($site),
        'Exclusive maintenance lock is blocked by active shared requests');
    $reject(static fn()=>SourceUpdateMaintenance::begin($site,$coord,$token),
        'Maintenance cannot start with in-flight requests');
    $check(!file_exists($site.'/storage/.source-update-maintenance'),
        'A rejected maintenance begin never publishes an early marker');
    fclose($requestLease);
    $reject(static fn()=>SourceUpdateMaintenance::begin($site,$coord,$token),
        'One remaining active request blocks maintenance');
    fclose($secondLease);

    // A different PHP process must honor the same filesystem lock.
    $code='require '.var_export($repo.'/src/Support/Autoload.php',true).';'
        .'$request=\AdultCore\Release\SourceHttpRequestLock::enter('
        .var_export($site,true).');'
        .'echo "REQUEST_LOCKED\n";flush();fgets(STDIN);fclose($request);';
    $commandHold=escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($code);
    $holderPipes=[];
    $holder=proc_open($commandHold,
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$holderPipes);
    if(!is_resource($holder))throw new RuntimeException('Cannot run separate PHP request holder.');
    stream_set_timeout($holderPipes[1],8);
    $ready=fgets($holderPipes[1]);
    try {
        $check($ready==="REQUEST_LOCKED\n",'Independent PHP process holds shared request lock');
        $reject(static fn()=>SourceUpdateMaintenance::begin($site,$coord,$token),
            'Maintenance cannot start with an independent in-flight PHP request');
        $check(!file_exists($site.'/storage/.source-update-maintenance'),
            'Independent active request keeps maintenance marker absent');
    } finally {
        fwrite($holderPipes[0],"\n");fclose($holderPipes[0]);
        stream_get_contents($holderPipes[1]);fclose($holderPipes[1]);
        stream_get_contents($holderPipes[2]);fclose($holderPipes[2]);
        $holderExit=proc_close($holder);
    }
    $check($holderExit===0,'Independent request finished successfully');

    $begin=SourceUpdateMaintenance::begin($site,$coord,$token);
    $check($begin['status']==='front_controller_maintenance'
        && $begin['site_http_front_controller_blocked']===true
        && $begin['static_assets_blocked']===false
        && $begin['pro_workers_blocked']===false
        && $begin['ready_for_activation']===false
        && $begin['dynamic_requests_drained_at_entry']===true,
        'Source marker blocks only dynamic application HTTP, not workers or static assets');
    $marker=$site.'/storage/.source-update-maintenance';
    $check(is_file($marker)&&(fileperms($marker)&0077)===0
        && $gate->status()===InstallationGate::SOURCE_UPDATE_MAINTENANCE
        && !$gate->eligibleForBackup(),
        'Private marker blocks all application routes and concurrent backup eligibility');
    $blockedPro=JobEnvelope::create('source-pro-guard-0002','video.thumbnail',
        ['asset_id'=>48],$proSecret);
    $reject(static fn()=>$proInbox->accept($blockedPro,$proSecret),
        'Real source operator maintenance prevents new signed Pro receipts');
    $reject(static fn()=>$proInbox->accept($beforePro,$proSecret),
        'Real source operator maintenance blocks even duplicate receipt replay');
    $check(hash_file('sha256',$proReceipt)===$oldProHash,
        'Source maintenance never rewrites prior signed Pro receipt');
    $receipt=file_get_contents($marker);
    $check(!str_contains($receipt,$token)
        && str_contains($receipt,hash('sha256',$token)),
        'Maintenance marker never stores raw operator lease token');
    $status=SourceUpdateMaintenance::status($site,$coord);
    $check($status['status']==='front_controller_maintenance'
        && !isset($status['lease_token_sha256'])
        && !isset($status['release_token']),
        'Read-only maintenance status cannot disclose credential hashes');
    // A maintenance operator may snapshot an authenticated local Pro inbox,
    // but pending/processing receipts, wrong ownership and even an already
    // held shared request guard MUST fail closed.
    $localSnapshot=static fn()=>ProSourceMaintenanceReceiptSnapshot::inspect(
        $site,$coord,$token,$proSecret
    );
    $reject($localSnapshot,
        'Signed Lite installation cannot claim Pro receipt maintenance evidence');
    file_put_contents($site.'/storage/install-state.json',
        InstallationState::encode('pro',$key));
    $reject(static fn()=>ProSourceMaintenanceReceiptSnapshot::inspect(
        $site,$coord,str_repeat('f',64),$proSecret),
        'Wrong lease token cannot inspect Pro maintenance receipts');
    $reject($localSnapshot,
        'Outstanding signed pending job refuses Pro maintenance snapshot');

    $runProSnapshotCli=static function(string $inputToken)use(
        $repo,$site,$coord,$proSecret
    ):array{
        $cli=escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($repo.'/scripts/check-pro-source-receipts.php').' '
            .escapeshellarg($site).' '.escapeshellarg($coord);
        $pipes=[];$process=proc_open($cli,
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))
            throw new RuntimeException('Cannot run Pro maintenance receipt CLI fixture.');
        fwrite($pipes[0],$inputToken."\n".$proSecret."\n");
        fclose($pipes[0]);
        $stdout=stream_get_contents($pipes[1]);fclose($pipes[1]);
        $stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);
        return [proc_close($process),$stdout,$stderr];
    };
    [$pendingExit,$pendingStdout]=$runProSnapshotCli($token);
    $check($pendingExit!==0 && $pendingStdout==='',
        'CLI refuses pending signed Pro receipts without misleading readiness JSON');

    $backupSignedReceipt=$site.'/storage/pro-jobs/'.
        hash('sha256','backup-colliding-pro-0001').'.json';
    $originalProReceipt=file_get_contents($proReceipt);
    $originalBackupReceipt=file_get_contents($backupSignedReceipt);
    foreach([$proReceipt=>$originalProReceipt,
        $backupSignedReceipt=>$originalBackupReceipt] as $path=>$rawReceipt){
        $completedReceipt=json_decode($rawReceipt,true,12,JSON_THROW_ON_ERROR);
        $completedReceipt['status']='done';
        $completedReceipt['mac']=hash_hmac('sha256',
            "adult-core-pro-receipt-v1\n".json_encode(
                array_slice($completedReceipt,0,4,true),
                JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES
            ),$proSecret);
        file_put_contents($path,json_encode($completedReceipt,JSON_THROW_ON_ERROR));
        chmod($path,0600);
    }
    $verifiedSnapshot=$localSnapshot();
    $check($verifiedSnapshot['read_only']===true
        &&$verifiedSnapshot['signed_pro_edition_verified']===true
        &&$verifiedSnapshot['owned_source_maintenance_marker_verified']===true
        &&$verifiedSnapshot['cooperating_local_guard_exclusive_at_scan']===true
        &&$verifiedSnapshot['signed_local_receipts_verified']===2
        &&$verifiedSnapshot['pending_receipts']===0
        &&$verifiedSnapshot['processing_receipts']===0
        &&$verifiedSnapshot['no_unfinished_signed_local_receipts_at_scan']===true
        &&$verifiedSnapshot['external_pro_workers_quiesced']===false
        &&$verifiedSnapshot['database_writers_quiesced']===false
        &&$verifiedSnapshot['ready_for_activation']===false,
        'Signed completed local Pro receipts produce no false external worker or launch proof');
    $inflightAuditReader=SourceHttpRequestLock::enter($site);
    $reject($localSnapshot,
        'Live shared local activity prevents exclusive maintenance receipt snapshot');
    fclose($inflightAuditReader);

    [$cliCode,$cliStdout,$cliStderr]=$runProSnapshotCli($token);
    $cliReport=json_decode($cliStdout,true);
    $check($cliCode===0 && is_array($cliReport)
        &&$cliReport['ready_for_activation']===false
        &&$cliReport['signed_local_receipts_verified']===2
        &&!str_contains($cliStdout,$token)
        &&!str_contains($cliStdout,$proSecret),
        'Private two-line-STDIN CLI emits only redacted local snapshot evidence');
    [$wrongCliExit,$wrongCliStdout]=$runProSnapshotCli(str_repeat('a',64));
    $check($wrongCliExit!==0&&$wrongCliStdout==='',
        'Wrong CLI lease credential cannot produce a receipt readiness report');

    $ownedMarker=file_get_contents($marker);
    $tamperedOwner=json_decode($ownedMarker,true,8,JSON_THROW_ON_ERROR);
    $tamperedOwner['lease_token_sha256']=str_repeat('0',64);
    file_put_contents($marker,json_encode($tamperedOwner,JSON_THROW_ON_ERROR)."\n");
    chmod($marker,0600);
    $reject($localSnapshot,
        'Correct lease token rejects an otherwise well-formed alien maintenance marker');
    file_put_contents($marker,$ownedMarker);
    chmod($marker,0600);
    $check($localSnapshot()['signed_local_receipts_verified']===2,
        'Restoring original owner marker permits read-only receipt snapshot');
    file_put_contents($proReceipt,$originalProReceipt);
    chmod($proReceipt,0600);
    file_put_contents($backupSignedReceipt,$originalBackupReceipt);
    chmod($backupSignedReceipt,0600);
    file_put_contents($site.'/storage/install-state.json',
        InstallationState::encode('lite',$key));
    $check(hash_file('sha256',$proReceipt)===$oldProHash
        &&$gate->status()===InstallationGate::SOURCE_UPDATE_MAINTENANCE,
        'Maintenance audit restored original signed receipt and Lite test state');

    $reject(static fn()=>SourceUpdateMaintenance::begin($site,$coord,$token),
        'Double begin cannot overwrite existing maintenance marker');
    $reject(static fn()=>SourceOperationLease::release($site,$coord,$token),
        'Cannot release coordinator lease while HTTP maintenance active');
    $reject(static fn()=>SourceUpdateMaintenance::end($site,$coord,str_repeat('f',64)),
        'Wrong operator token cannot restore HTTP requests');
    $check($gate->status()===InstallationGate::SOURCE_UPDATE_MAINTENANCE,
        'Bad token leaves site blocked');

    // Exercise the ACTUAL public/index.php in a disposable copied
    // application root, without any licensed vendor connection or DB.
    $preload=$tmp.'/http-request-fixture.php';
    file_put_contents($preload,
        '<?php $_SERVER["REQUEST_URI"]="/admin";'
        .'register_shutdown_function(static function(){'
        .'fwrite(STDERR,"HTTP_CODE=".http_response_code()."\n");});');
    $command=escapeshellarg(PHP_BINARY).' -d auto_prepend_file='
        .escapeshellarg($preload).' '
        .escapeshellarg($site.'/public/index.php').' 2>&1';
    $output=[];exec($command,$output,$exit);
    $html=implode("\n",$output);
    $check($exit===0 && str_contains($html,'HTTP_CODE=503')
        && str_contains($html,'Source update maintenance'),
        'Real front controller returns 503 maintenance screen for /admin');

    // An operator exclusive guard rejects new front controller PHP requests.
    $exclusive=SourceHttpRequestLock::exclusive($site);
    try {
        $busyOutput=[];exec($command,$busyOutput,$busyExit);
        $busyHtml=implode("\n",$busyOutput);
        $check($busyExit===0 && str_contains($busyHtml,'HTTP_CODE=503')
            && str_contains($busyHtml,'Source update maintenance'),
            'Existing marker still blocks dynamic HTTP under operator exclusive lock');
    } finally {
        fclose($exclusive);
    }

    file_put_contents($marker,'{"invalid":"damaged"}');chmod($marker,0600);
    $check(SourceUpdateMaintenance::status($site,$coord)['status']
        ==='incomplete_manual_recovery_required',
        'Damaged marker preserves blocking state for manual recovery');
    $reject(static fn()=>SourceUpdateMaintenance::end($site,$coord,$token),
        'Corrupt marker cannot be cleared even with correct token');
    $check($gate->status()===InstallationGate::SOURCE_UPDATE_MAINTENANCE,
        'Corrupt marker still blocks HTTP fail closed');
    file_put_contents($marker,$receipt);

    // An unrelated database/restore pending condition must win priority.
    file_put_contents($site.'/storage/.backup-restore-pending',"pending\n");
    $check($gate->status()===InstallationGate::BACKUP_RESTORE_PENDING,
        'Interrupted restore takes priority over source maintenance');
    $reject(static fn()=>SourceUpdateMaintenance::end($site,$coord,$token),
        'Source maintenance cannot bypass pending backup recovery');
    unlink($site.'/storage/.backup-restore-pending');

    if (function_exists('symlink')) {
        $saved=$tmp.'/saved-maintenance-marker';
        rename($marker,$saved);
        if(symlink($saved,$marker)){
            $check($gate->status()===InstallationGate::SOURCE_UPDATE_MAINTENANCE,
                'Symlink marker still blocks application HTTP');
            $check(SourceUpdateMaintenance::status($site,$coord)['status']
                ==='incomplete_manual_recovery_required',
                'Symlink marker cannot be treated as a trusted operator state');
            $reject(static fn()=>SourceUpdateMaintenance::end($site,$coord,$token),
                'Symlink marker cannot authorize maintenance exit');
            unlink($marker);
        }
        rename($saved,$marker);
    }

    // CLI read-only status and token-over-stdin end across independent PHP
    // processes; the raw token never appears in process arguments.
    $cli=escapeshellarg(PHP_BINARY).' '
        .escapeshellarg($repo.'/scripts/source-http-maintenance.php');
    $statusOutput=[];
    exec($cli.' status '.escapeshellarg($site).' '.escapeshellarg($coord)
        .' 2>/dev/null',$statusOutput,$cliStatus);
    $json=json_decode(implode("\n",$statusOutput),true);
    $check($cliStatus===0 && is_array($json)
        && $json['status']==='front_controller_maintenance'
        && !isset($json['lease_token_sha256']),
        'Offline maintenance CLI status is private and read-only');

    $stillRendering=SourceHttpRequestLock::enter($site);
    $reject(static fn()=>SourceUpdateMaintenance::end($site,$coord,$token),
        'Maintenance cannot reopen HTTP during another active request');
    $check($gate->status()===InstallationGate::SOURCE_UPDATE_MAINTENANCE,
        'Failed maintenance end retains private blocking marker');
    fclose($stillRendering);

    $endCmd=$cli.' end '.escapeshellarg($site).' '.escapeshellarg($coord);
    $pipes=[];
    $proc=proc_open($endCmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($proc))throw new RuntimeException('Cannot run maintenance CLI exit fixture.');
    fwrite($pipes[0],$token."\n");fclose($pipes[0]);
    $stdout=stream_get_contents($pipes[1]);fclose($pipes[1]);
    $stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);
    $code=proc_close($proc);
    $endStatus=json_decode($stdout,true);
    $check($code===0&&is_array($endStatus)
        &&$endStatus['status']==='inactive'
        &&$gate->status()===InstallationGate::READY,
        'CLI end removes private marker with current token and reopens dynamic HTTP');
    // With READY state and no maintenance marker, contention itself must
    // still deny a new dynamic PHP request with generic 503 before routing.
    $exclusiveReady=SourceHttpRequestLock::exclusive($site);
    try {
        $busyReadyOutput=[];exec($command,$busyReadyOutput,$busyReadyExit);
        $busyReadyHtml=implode("\n",$busyReadyOutput);
        $check($busyReadyExit===0
            && str_contains($busyReadyHtml,'HTTP_CODE=503')
            && str_contains($busyReadyHtml,'Source maintenance in progress'),
            'Ready site refuses incoming request while maintenance obtains exclusive lock');
    } finally {
        fclose($exclusiveReady);
    }
    $check($proInbox->accept($blockedPro,$proSecret),
        'Signed Pro receipts resume only after source maintenance is ended');
    $check(hash_file('sha256',$proReceipt)===$oldProHash,
        'Previously accepted Pro receipt remains intact after maintenance');
    // Untrusted lock aliases must return 503 before any application controller.
    $savedLock=$tmp.'/saved-request-gate-lock';
    rename($lockFile,$savedLock);
    if(function_exists('symlink') && symlink($savedLock,$lockFile)){
        $unsafeOutput=[];exec($command,$unsafeOutput,$unsafeExit);
        $unsafeHtml=implode("\n",$unsafeOutput);
        $check($unsafeExit===0 && str_contains($unsafeHtml,'HTTP_CODE=503')
            && str_contains($unsafeHtml,'Source maintenance in progress'),
            'Symlinked request lock returns 503 instead of serving application');
        unlink($lockFile);
    }
    rename($savedLock,$lockFile);

    $check(SourceOperationLease::status($site,$coord)['status']==='operator_coordination_only',
        'Ending maintenance does not silently release operator coordination token');
    SourceOperationLease::release($site,$coord,$token);
    $check(SourceOperationLease::status($site,$coord)['status']==='not_acquired',
        'Only explicitly separate step releases coordination lease');

    // Independent CLI begin must work after taking a fresh lease.
    $newClaim=SourceOperationLease::acquire($site,$coord);
    $token2=$newClaim['release_token'];
    $beginCmd=$cli.' begin '.escapeshellarg($site).' '.escapeshellarg($coord);
    $pipes=[];
    $proc=proc_open($beginCmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($proc))throw new RuntimeException('Cannot run maintenance CLI begin fixture.');
    fwrite($pipes[0],$token2."\n");fclose($pipes[0]);
    $cliBegin=stream_get_contents($pipes[1]);fclose($pipes[1]);
    $err=stream_get_contents($pipes[2]);fclose($pipes[2]);
    $code=proc_close($proc);
    $json=json_decode($cliBegin,true);
    $check($code===0&&is_array($json)
        &&$json['site_http_front_controller_blocked']===true
        &&$gate->status()===InstallationGate::SOURCE_UPDATE_MAINTENANCE,
        'Independent CLI begin blocks dynamic website through shared InstallationGate');
    SourceUpdateMaintenance::end($site,$coord,$token2);
    SourceOperationLease::release($site,$coord,$token2);

    $check(file_get_contents($site.'/VERSION')===$originalVersion
        && file_get_contents($site.'/config/license.php')===$originalLicense
        && $gate->status()===InstallationGate::READY,
        'Source maintenance never modifies customer licensing, installed version or readiness');

    echo "Passed {$passed} source HTTP maintenance safety tests.\n";
} finally {
    $remove($tmp);
}
