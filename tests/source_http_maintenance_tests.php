<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Installer\InstallationGate;
use AdultCore\Installer\InstallationState;
use AdultCore\Release\SourceOperationLease;
use AdultCore\Release\SourceUpdateMaintenance;

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
    foreach([
        'public/index.php',
        'src/Support/Autoload.php',
        'src/Installer/InstallationGate.php',
        'src/Installer/InstallationState.php',
        'src/Installer/LockedScreen.php',
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

    $claim=SourceOperationLease::acquire($site,$coord);
    $token=$claim['release_token'];
    $check(SourceOperationLease::matches($site,$coord,$token),
        'One-time coordinator token authenticates same installed root');
    $check(!SourceOperationLease::matches($site,$coord,str_repeat('f',64)),
        'Wrong token never authenticates maintenance ownership');
    $begin=SourceUpdateMaintenance::begin($site,$coord,$token);
    $check($begin['status']==='front_controller_maintenance'
        && $begin['site_http_front_controller_blocked']===true
        && $begin['static_assets_blocked']===false
        && $begin['pro_workers_blocked']===false
        && $begin['ready_for_activation']===false,
        'Source marker blocks only dynamic application HTTP, not workers or static assets');
    $marker=$site.'/storage/.source-update-maintenance';
    $check(is_file($marker)&&(fileperms($marker)&0077)===0
        && $gate->status()===InstallationGate::SOURCE_UPDATE_MAINTENANCE
        && !$gate->eligibleForBackup(),
        'Private marker blocks all application routes and concurrent backup eligibility');
    $receipt=file_get_contents($marker);
    $check(!str_contains($receipt,$token)
        && str_contains($receipt,hash('sha256',$token)),
        'Maintenance marker never stores raw operator lease token');
    $status=SourceUpdateMaintenance::status($site,$coord);
    $check($status['status']==='front_controller_maintenance'
        && !isset($status['lease_token_sha256'])
        && !isset($status['release_token']),
        'Read-only maintenance status cannot disclose credential hashes');
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
