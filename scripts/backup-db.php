<?php

declare(strict_types=1);

/** CLI-only encrypted small-database diagnostic; NOT a full site backup or restore. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/src/Support/Autoload.php';
use AdultCore\Backup\EncryptedSnapshot;
use AdultCore\Database\Connection;
use AdultCore\Database\Schema;
use AdultCore\Installer\InstallationGate;
use AdultCore\Installer\InstallationState;
use AdultCore\Release\BackupMaintenanceAdmission;

$root=realpath(dirname(__DIR__));
$output=$argv[1]??'';
$key=getenv('AC_BACKUP_KEY');
$outputDir=realpath(dirname($output));
$mode=$outputDir!==false ? @fileperms($outputDir) : false;
if (count($argv)!==2 || !is_string($key) || !preg_match('/^[a-f0-9]{64}$/D',$key)
    || !str_ends_with($output,'.acbak') || file_exists($output) || is_link($output)
    || $root===false || $outputDir===false || is_link(dirname($output))
    || $outputDir===$root || str_starts_with($outputDir,$root.'/')
    || !is_int($mode) || ($mode&0077)!==0
    || getenv('AC_BACKUP_WRITES_PAUSED')!=='YES') {
    fwrite(STDERR,"Usage: AC_BACKUP_KEY=<64-hex-chars> AC_BACKUP_WRITES_PAUSED=YES php scripts/backup-db.php /private/output.acbak\nUse an owner-only directory outside the site. On Pro, stop workers and set AC_PRO_JOBS_STOPPED=YES.\n");exit(2);
}
$token=null;
$pdo=null;
$exit=0;
try {
    $admission=BackupMaintenanceAdmission::begin($root);
    $token=$admission['finish_token'];
    $gate=new InstallationGate($root);
    if($gate->status()!==InstallationGate::BACKUP_MAINTENANCE||!$gate->eligibleForBackup())
        throw new RuntimeException('Installed backup maintenance state is inconsistent.');
    $appKey=require $root.'/config/app-key.php';
    $rawState=file_get_contents($root.'/storage/install-state.json');
    if(!is_string($appKey)||!is_string($rawState)||!InstallationState::valid($rawState,$appKey))
        throw new RuntimeException('Signed installation identity is invalid.');
    $edition=json_decode($rawState,true,8,JSON_THROW_ON_ERROR)['edition'];
    if($edition==='pro'&&getenv('AC_PRO_JOBS_STOPPED')!=='YES')
        throw new RuntimeException('Operator must stop Pro workers before snapshot.');
    $pdo=Connection::open($root);
    $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    $pdo->beginTransaction();
    Schema::verify($pdo);
    if($pdo->query('SELECT edition FROM ac_installations WHERE id=1')->fetchColumn()!==$edition)
        throw new RuntimeException('Database edition differs from signed installation.');
    $tables=$pdo->query("SELECT TABLE_NAME,ENGINE,TABLE_TYPE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND LEFT(TABLE_NAME,3)='ac_' ORDER BY TABLE_NAME LIMIT 251")
        ->fetchAll(\PDO::FETCH_ASSOC);
    if(count($tables)<5||count($tables)>250)
        throw new RuntimeException('Snapshot table inventory exceeds the supported range.');
    $rows=[];$count=0;
    if(defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY'))$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
    foreach($tables as $table){
        $name=$table['TABLE_NAME'];
        if(!is_string($name)||preg_match('/^ac_[a-z0-9_]{1,60}$/D',$name)!==1
            ||$table['ENGINE']!=='InnoDB'||$table['TABLE_TYPE']!=='BASE TABLE')
            throw new RuntimeException('Small snapshot requires ordinary Adult_Core InnoDB tables.');
        $rows[$name]=[];
        $query=$pdo->query('SELECT * FROM `'.$name.'`');
        try{
            while(($row=$query->fetch(\PDO::FETCH_ASSOC))!==false){
                $rows[$name][]=$row;
                if(++$count>100000||memory_get_usage(true)>24*1024*1024)
                    throw new RuntimeException('Backup too large for small-DB snapshot tool.');
            }
        }finally{$query->closeCursor();}
    }
    $document=json_encode(['version'=>1,'created_at'=>gmdate('c'),'tables'=>$rows],JSON_THROW_ON_ERROR);
    $pdo->commit();
    $sealed=EncryptedSnapshot::seal($document,$key);
    // Do not overwrite or follow a replacement file, even on failure.
    $mask=umask(0077);
    try{$fp=@fopen($output,'x+b');}finally{umask($mask);}
    if(!is_resource($fp))throw new RuntimeException('Snapshot destination already exists or is unavailable.');
    $opened=fstat($fp);$success=false;
    $sameFile=static function()use($output,$opened):bool{
        clearstatcache(true,$output);
        $named=@lstat($output);
        return is_array($opened)&&is_array($named)
            &&($opened['mode']&0170000)===0100000&&($named['mode']&0170000)===0100000
            &&($named['mode']&0077)===0
            &&$opened['dev']===$named['dev']&&$opened['ino']===$named['ino'];
    };
    try{
        if(!$sameFile())throw new RuntimeException('Snapshot output is not a private regular file.');
        $written=0;
        while($written<strlen($sealed)){
            $n=fwrite($fp,substr($sealed,$written));
            if(!is_int($n)||$n<1)throw new RuntimeException('Snapshot write failed.');
            $written+=$n;
        }
        if(!fflush($fp)||(function_exists('fsync')&&!fsync($fp))||!rewind($fp))
            throw new RuntimeException('Snapshot sync failed.');
        $saved=stream_get_contents($fp,strlen($sealed)+1);
        if(!is_string($saved)||!hash_equals($sealed,$saved)
            ||!hash_equals($document,EncryptedSnapshot::open($saved,$key))||!$sameFile())
            throw new RuntimeException('Encrypted snapshot verification failed.');
        $success=true;
    }finally{
        if(!$success&&$sameFile())@unlink($output);
        fclose($fp);
    }
}catch(Throwable $error){
    if($pdo instanceof PDO&&$pdo->inTransaction())$pdo->rollBack();
    fwrite(STDERR,"Backup failed: ".$error->getMessage()."\n");
    $exit=1;
}finally{
    if($token!==null){
        try{BackupMaintenanceAdmission::finish($root,$token);}
        catch(Throwable $error){
            fwrite(STDERR,"Maintenance cleanup refused; operator review required.\n");
            $exit=1;
        }
    }
}
if($exit!==0)exit($exit);
echo "Encrypted private database snapshot written and verified. This does NOT include media or allow restore.\n";
