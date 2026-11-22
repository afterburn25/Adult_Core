<?php

declare(strict_types=1);

require dirname(__DIR__).'/src/Support/Autoload.php';

use AdultCore\Backup\BackupVerifier;
use AdultCore\Backup\StreamArchive;
use AdultCore\Installer\InstallationGate;
use AdultCore\Installer\InstallationState;
use AdultCore\Release\SourceBackupEvidence;

$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{
    if(!$ok)throw new RuntimeException('Release backup evidence: '.$label);
    ++$checks;
};
$reject=static function(callable $fn,string $label)use($check):void{
    try{$fn();}catch(RuntimeException){$check(true,$label);return;}
    $check(false,$label);
};
$remove=static function(string $path)use(&$remove):void{
    if(is_link($path)||is_file($path)){unlink($path);return;}
    if(!is_dir($path))return;
    foreach(scandir($path)?:[] as $x)if($x!=='.'&&$x!=='..')$remove($path.'/'.$x);
    rmdir($path);
};
$repo=dirname(__DIR__);
$tmp=sys_get_temp_dir().'/ac-backup-evidence-'.bin2hex(random_bytes(8));
mkdir($tmp,0700);
try{
    $site=$tmp.'/site';$vault=$tmp.'/vault';$open=$tmp.'/open';
    mkdir($site,0700);mkdir($site.'/config',0700);mkdir($site.'/storage',0700);
    mkdir($vault,0700);mkdir($open,0755);
    $appKey=bin2hex(random_bytes(32));$backupKey=bin2hex(random_bytes(32));
    $version=trim((string)file_get_contents($repo.'/VERSION'));
    file_put_contents($site.'/VERSION',$version."\n");
    file_put_contents($site.'/config/app-key.php',"<?php\nreturn '".$appKey."';\n");
    file_put_contents($site.'/config/database.php',
        "<?php return ['driver'=>'mysql','database'=>'fixture','username'=>'fixture'];\n");
    file_put_contents($site.'/config/license.php',"<?php return ['fixture'=>'PRESERVE'];\n");
    file_put_contents($site.'/storage/install-state.json',InstallationState::encode('lite',$appKey));
    file_put_contents($site.'/storage/license-cache.json','{}');
    $beforeLicense=file_get_contents($site.'/config/license.php');
    $beforeVersion=file_get_contents($site.'/VERSION');

    $fixture=static function(string $file,string $key,string $installedKey,
        string $edition,string $sourceVersion):void{
        $files=[
            'config/app-key.php'=>"<?php\nreturn '".$installedKey."';\n",
            'config/license.php'=>"<?php return ['fixture'=>'BACKUP'];\n",
            'storage/install-state.json'=>InstallationState::encode($edition,$installedKey),
            'storage/license-cache.json'=>'{}',
            'storage/private-media/artificial.bin'=>'synthetic-fixture-only',
        ];
        StreamArchive::write($file,$key,static function(callable $emit)
            use($files,$edition,$sourceVersion):void{
            $emit(['type'=>'meta','source_version'=>$sourceVersion,'edition'=>$edition,'tables'=>5]);
            for($i=0;$i<5;$i++){
                $name='ac_fixture_'.$i;
                $ddl='CREATE TABLE '.chr(96).$name.chr(96)
                    .' ('.chr(96).'id'.chr(96).' int NOT NULL) ENGINE=InnoDB';
                $emit(['type'=>'table','name'=>$name,'ddl'=>$ddl]);
            }
            $emit(['type'=>'row','table'=>'ac_fixture_0',
                'fields'=>['id'=>base64_encode('1')]]);
            $total=0;
            foreach($files as $name=>$bytes){
                $emit(['type'=>'file_start','path'=>$name,'bytes'=>strlen($bytes)]);
                $emit(['type'=>'file_chunk','path'=>$name,'data'=>base64_encode($bytes)]);
                $emit(['type'=>'file_end','path'=>$name,'sha256'=>hash('sha256',$bytes)]);
                $total+=strlen($bytes);
            }
            $emit(['type'=>'end','tables'=>5,'rows'=>1,'files'=>count($files),
                'file_bytes'=>$total]);
        });
    };
    $archive=$vault.'/matching.acfull';
    $fixture($archive,$backupKey,$appKey,'lite',$version);
    $inspect=static fn()=>SourceBackupEvidence::inspect($site,$archive,$backupKey);
    $report=$inspect();
    $check($report['encrypted_archive_verified']===true
        &&$report['same_installed_site_verified']===true
        &&$report['installed_edition']==='lite'
        &&$report['installed_version']===$version,
        'Full encrypted backup belongs to exact installed Lite site/version');
    $check($report['database_tables_verified']===5
        &&$report['private_files_verified']===5
        &&$report['backup_sha256']===hash_file('sha256',$archive),
        'Verified encrypted archive includes authentic schema and private files');
    $check($report['source_activation_authorized']===false
        &&$report['restore_drill_completed']===false
        &&$report['database_writers_quiesced']===false
        &&$report['backup_age_certified']===false
        &&$report['production_ready']===false,
        'Valid recovery archive cannot authorize update or certify production');
    $check(!str_contains(json_encode($report),$appKey)
        &&!str_contains(json_encode($report),$backupKey),
        'Redacted report never exposes backup key or private installation identity');

    $reject(static fn()=>SourceBackupEvidence::inspect($site,$archive,bin2hex(random_bytes(32))),
        'Wrong AES-GCM key refused');
    $reject(static fn()=>(new BackupVerifier())->verify($archive,$backupKey,bin2hex(random_bytes(32))),
        'Existing backup verifier refuses a different installed-site key');
    $wrongSite=$vault.'/different-site.acfull';
    $fixture($wrongSite,$backupKey,bin2hex(random_bytes(32)),'lite',$version);
    $reject(static fn()=>SourceBackupEvidence::inspect($site,$wrongSite,$backupKey),
        'Different installation with matching version and edition refused');
    $wrongEdition=$vault.'/pro-site.acfull';
    $fixture($wrongEdition,$backupKey,$appKey,'pro',$version);
    $reject(static fn()=>SourceBackupEvidence::inspect($site,$wrongEdition,$backupKey),
        'Pro recovery archive cannot be used for installed Lite edition');
    $wrongVersion=$vault.'/old-source.acfull';
    $fixture($wrongVersion,$backupKey,$appKey,'lite','0.7.1-old-dev');
    $reject(static fn()=>SourceBackupEvidence::inspect($site,$wrongVersion,$backupKey),
        'Older signed backup version refused');

    $raw=file_get_contents($archive);
    $lines=explode("\n",trim($raw));
    $record=json_decode($lines[2],true,8,JSON_THROW_ON_ERROR);
    $cipher=base64_decode($record['ciphertext'],true);
    $cipher[0]=chr(ord($cipher[0])^1);
    $record['ciphertext']=base64_encode($cipher);
    $lines[2]=json_encode($record,JSON_THROW_ON_ERROR);
    $corrupt=$vault.'/corrupt.acfull';
    file_put_contents($corrupt,implode("\n",$lines)."\n");chmod($corrupt,0600);
    $reject(static fn()=>SourceBackupEvidence::inspect($site,$corrupt,$backupKey),
        'Encrypted ciphertext tampering refused');
    chmod($archive,0644);
    $reject($inspect,'World-readable recovery archive refused');
    chmod($archive,0600);
    copy($archive,$open.'/public.acfull');chmod($open.'/public.acfull',0600);
    $reject(static fn()=>SourceBackupEvidence::inspect($site,$open.'/public.acfull',$backupKey),
        'World-traversable vault refused');
    copy($archive,$site.'/storage/inside.acfull');chmod($site.'/storage/inside.acfull',0600);
    $reject(static fn()=>SourceBackupEvidence::inspect(
        $site,$site.'/storage/inside.acfull',$backupKey),
        'Backup inside installed website refused as external recovery vault');
    if(function_exists('symlink') && symlink($archive,$vault.'/linked.acfull')){
        $reject(static fn()=>SourceBackupEvidence::inspect($site,$vault.'/linked.acfull',$backupKey),
            'Symlink recovery archive refused');
    }
    file_put_contents($site.'/storage/.backup-restore-pending','pending');
    $reject($inspect,'Pending backup restore blocks the identity evidence gate');
    unlink($site.'/storage/.backup-restore-pending');
    mkdir($site.'/install',0700);
    $reject($inspect,'Unremoved installer is not a ready site');
    rmdir($site.'/install');
    file_put_contents($site.'/storage/.source-update-maintenance',"maintenance\n");
    $check((new InstallationGate($site))->status()===InstallationGate::SOURCE_UPDATE_MAINTENANCE
        &&$inspect()['encrypted_archive_verified']===true,
        'Read-only backup evidence can be inspected while source HTTP is blocked');
    unlink($site.'/storage/.source-update-maintenance');

    $cmd=escapeshellarg(PHP_BINARY).' '
        .escapeshellarg($repo.'/scripts/check-update-backup.php').' '
        .escapeshellarg($site).' '.escapeshellarg($archive);
    $pipes=[];$proc=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($proc))throw new RuntimeException('Cannot spawn backup evidence CLI.');
    fwrite($pipes[0],$backupKey."\n");fclose($pipes[0]);
    $stdout=stream_get_contents($pipes[1]);fclose($pipes[1]);
    $stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);
    $exit=proc_close($proc);
    $cli=json_decode($stdout,true);
    $check($exit===0&&is_array($cli)
        &&$cli['same_installed_site_verified']===true
        &&$cli['source_activation_authorized']===false,
        'CLI authenticates identical installed site using offline key on STDIN');
    $check(!str_contains($stdout,$appKey)&&!str_contains($stdout,$backupKey)
        &&file_get_contents($site.'/VERSION')===$beforeVersion
        &&file_get_contents($site.'/config/license.php')===$beforeLicense
        &&file_get_contents($archive)===$raw,
        'Evidence checks never modify installed source, customer license or backup');
    echo "Passed {$checks} private site backup release evidence tests.\n";
}finally{$remove($tmp);}
