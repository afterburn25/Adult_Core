<?php

declare(strict_types=1);

namespace AdultCore\Backup;

use AdultCore\Database\Schema;
use AdultCore\Installer\InstallationGate;
use AdultCore\Installer\InstallationState;
use PDO;
use RuntimeException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use Throwable;

/** CLI-only private-files + InnoDB DDL/data backup, and restore into a FRESH target. */
final class SiteBackup
{
    private const MAX_FILES=50000;
    private const MAX_BYTES=20 * 1024 * 1024 * 1024;
    private const CHUNK=65536;
    private const ALLOWED_CONFIG=['app-key.php','license.php','email.php','verification.php','pro-agent-secret.php'];

    public function __construct(private readonly string $root) {}

    public static function safePath(string $rel):bool
    {
        if(strlen($rel)>600||!preg_match('~^(?:storage|config)/[a-zA-Z0-9._-]+(?:/[a-zA-Z0-9._-]+)*$~D',$rel))return false;
        $parts=explode('/',$rel);
        if(in_array('..',$parts,true)||in_array('.',$parts,true))return false;
        if($parts[0]==='config')return in_array($parts[1],self::ALLOWED_CONFIG,true) && count($parts)===2
            || (count($parts)===3 && $parts[1]==='trusted-publishers' && str_ends_with($parts[2],'.pem'));
        // Never restore logged-in sessions, old rate-limit state, locks, or crash journals.
        if(in_array($parts[1],['sessions','member-sessions'],true))return false;
        if(str_starts_with($parts[1],'.')||str_starts_with($parts[1],'install-audit'))return false;
        return true;
    }

    /** CLI-managed export; quiesce site and Pro workers before calling. */
    public function create(PDO $pdo,string $destination,string $hexKey):array
    {
        if((new InstallationGate($this->root))->status()!==InstallationGate::READY)throw new RuntimeException('Only a completed, unlocked installation can be backed up.');
        $this->assertMysql($pdo);
        Schema::verify($pdo);
        $dir=realpath(dirname($destination));$root=realpath($this->root);
        if($dir===false||$root===false||$dir===$root||str_starts_with($dir,$root.'/')||is_link(dirname($destination))
            ||!str_ends_with($destination,'.acfull'))throw new RuntimeException('Write new .acfull backup outside the source site root.');
        $tables=$this->listTables($pdo);
        $fileList=$this->listFiles();
        foreach(['config/app-key.php','config/license.php','storage/install-state.json','storage/license-cache.json'] as $required)
            if(!in_array($required,$fileList,true))throw new RuntimeException('Essential private installation file is missing.');
        $edition=(string)$pdo->query('SELECT edition FROM ac_installations WHERE id=1')->fetchColumn();
        if(!in_array($edition,['lite','pro'],true))throw new RuntimeException('Installed edition missing.');
        $stats=['tables'=>count($tables),'rows'=>0,'files'=>0,'file_bytes'=>0];
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction(); // repeatable-read snapshot; operator must pause schema migrations and file writers
        try {
            StreamArchive::write($destination,$hexKey,function(callable $emit)use($pdo,$tables,$fileList,$edition,&$stats):void{
                $emit(['type'=>'meta','source_version'=>trim((string)file_get_contents($this->root.'/VERSION')),
                    'edition'=>$edition,'created_at'=>gmdate('c'),'tables'=>count($tables)]);
                foreach($tables as $table){
                    $ddl=$pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM);
                    if(!is_array($ddl)||!is_string($ddl[1]??null))throw new RuntimeException('Could not export table schema.');
                    $this->checkDdl($table,$ddl[1]);
                    $emit(['type'=>'table','name'=>$table,'ddl'=>$ddl[1]]);
                }
                if(defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY'))$pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
                foreach($tables as $table){
                    $query=$pdo->query('SELECT * FROM `'.$table.'`');
                    while(($row=$query->fetch(PDO::FETCH_ASSOC))!==false){
                        $fields=[];
                        foreach($row as $column=>$value){
                            if(!self::identifier($column)||($value!==null&&!is_scalar($value)))throw new RuntimeException('Invalid database field.');
                            $fields[$column]=$value===null?null:base64_encode((string)$value);
                        }
                        $emit(['type'=>'row','table'=>$table,'fields'=>$fields]);
                        if(++$stats['rows']>3000000)throw new RuntimeException('Database exceeds tested backup row limit.');
                    }
                    $query->closeCursor();
                }
                foreach($fileList as $rel){
                    $path=$this->root.'/'.$rel;
                    if(is_link($path)||!is_file($path))throw new RuntimeException('Backup file changed while exporting.');
                    $start=stat($path);if(!is_array($start)||$start['size']<0||$start['size']>self::MAX_BYTES)throw new RuntimeException('Invalid backup input file.');
                    $fp=@fopen($path,'rb');if(!is_resource($fp))throw new RuntimeException('Cannot read backup file.');
                    $hash=hash_init('sha256');$size=0;
                    $emit(['type'=>'file_start','path'=>$rel,'bytes'=>$start['size']]);
                    try {
                        while(!feof($fp)){
                            $bytes=fread($fp,self::CHUNK);
                            if(!is_string($bytes))throw new RuntimeException('Backup input read failed.');
                            if($bytes===''){if(!feof($fp))throw new RuntimeException('Backup input read stalled.');break;}
                            $size+=strlen($bytes);$stats['file_bytes']+=strlen($bytes);
                            if($size>self::MAX_BYTES||$stats['file_bytes']>self::MAX_BYTES)throw new RuntimeException('Media exceeds backup safety limit.');
                            hash_update($hash,$bytes);
                            $emit(['type'=>'file_chunk','path'=>$rel,'data'=>base64_encode($bytes)]);
                        }
                    }finally{fclose($fp);}
                    clearstatcache(true,$path);$end=stat($path);
                    if(!is_array($end)||$size!==$start['size']||$start['mtime']!==$end['mtime']||$end['size']!==$size)throw new RuntimeException('Input file changed; quiesce uploads before backup.');
                    $emit(['type'=>'file_end','path'=>$rel,'sha256'=>hash_final($hash)]);
                    $stats['files']++;
                }
                $emit(['type'=>'end']+$stats);
            });
            $pdo->commit();
            return $stats;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();@unlink($destination);throw $e;}
    }

    /** Restores only to same-version, uninstalled source with a separately provisioned EMPTY MySQL DB. */
    public function restore(PDO $pdo,string $source,string $hexKey):array
    {
        $this->assertMysql($pdo);
        if(!is_file($this->root.'/install/index.php')||!is_file($this->root.'/VERSION')||!is_file($this->root.'/config/database.php')
            ||is_file($this->root.'/storage/install-state.json')||is_link($this->root.'/storage/install-state.json')
            ||is_file($this->root.'/config/app-key.php')||is_link($this->root.'/config/app-key.php')
            ||is_file($this->root.'/storage/.backup-restore-pending')||is_link($this->root.'/storage/.backup-restore-pending')){
            throw new RuntimeException('Restore requires fresh, uninstalled source, intact installer and a private target database config.');
        }
        $this->assertEmptyDatabase($pdo);
        // Only initially empty storage is safe: do not merge restored private files into another installation.
        foreach(new \DirectoryIterator($this->root.'/storage') as $entry){
            if($entry->isDot()||$entry->getFilename()==='.gitkeep')continue;
            throw new RuntimeException('Restore requires empty private storage.');
        }
        $stage=$this->root.'/storage/.restore-stage-'.bin2hex(random_bytes(8));
        if(!mkdir($stage,0700))throw new RuntimeException('Cannot stage recovered files.');
        $committed=false;
        $ddl=[];$meta=null;$stats=['tables'=>0,'rows'=>0,'files'=>0,'file_bytes'=>0];
        $current=null;$sink=null;$hash=null;$fileBytes=0;$plannedSize=0;$seen=[];$phase=0;$ending=false;
        try {
            StreamArchive::read($source,$hexKey,function(array $r)use(&$ddl,&$meta,&$stats,&$current,&$sink,&$hash,&$fileBytes,&$plannedSize,&$seen,&$phase,&$ending,$stage):void{
                $type=$r['type'];
                if($ending)throw new RuntimeException('Records after completion.');
                if($type==='meta'){
                    if($phase!==0||!is_string($r['source_version']??null)||!in_array($r['edition']??null,['lite','pro'],true)
                        ||!is_int($r['tables']??null)||$r['tables']<5||$r['tables']>250
                        ||!hash_equals(trim((string)file_get_contents($this->root.'/VERSION')),$r['source_version']))throw new RuntimeException('Archive edition or source version incompatible.');
                    $meta=$r;$phase=1;
                }elseif($type==='table'){
                    if($phase!==1||!is_string($r['name']??null)||!is_string($r['ddl']??null)||!self::tableName($r['name'])||isset($ddl[$r['name']])||count($ddl)>=250)
                        throw new RuntimeException('Invalid or duplicate table schema.');
                    $this->checkDdl($r['name'],$r['ddl']);$ddl[$r['name']]=$r['ddl'];$stats['tables']++;
                }elseif($type==='row'){
                    if(!in_array($phase,[1,2],true)||$stats['tables']!==($meta['tables']??null)||!is_string($r['table']??null)||!isset($ddl[$r['table']])
                        ||!is_array($r['fields']??null)||array_is_list($r['fields'])||count($r['fields'])<1||count($r['fields'])>200)
                        throw new RuntimeException('Invalid database row record.');
                    foreach($r['fields'] as $column=>$value)if(!self::identifier($column)||($value!==null&&(!is_string($value)||base64_decode($value,true)===false)))
                        throw new RuntimeException('Invalid database field encoding.');
                    $phase=2;$stats['rows']++;if($stats['rows']>3000000)throw new RuntimeException('Too many rows in recovery archive.');
                }elseif($type==='file_start'){
                    if(!in_array($phase,[1,2,3],true)||$current!==null||!is_string($r['path']??null)||!self::safePath($r['path'])
                        ||isset($seen[$r['path']])||!is_int($r['bytes']??null)||$r['bytes']<0||$r['bytes']>self::MAX_BYTES||count($seen)>=self::MAX_FILES){
                        throw new RuntimeException('Unsafe or duplicate recovery file.');
                    }
                    $phase=3;$current=$r['path'];$seen[$current]=true;$fileBytes=0;$plannedSize=$r['bytes'];$hash=hash_init('sha256');
                    $path=$stage.'/'.$current;$this->makeDirs(dirname($path),$stage);
                    $sink=@fopen($path,'xb');if(!is_resource($sink))throw new RuntimeException('Cannot safely stage recovered file.');
                    @chmod($path,0600);
                }elseif($type==='file_chunk'){
                    if($phase!==3||!is_resource($sink)||($r['path']??null)!==$current||!is_string($r['data']??null))throw new RuntimeException('Unordered recovery media.');
                    $bytes=base64_decode($r['data'],true);
                    if(!is_string($bytes)||strlen($bytes)<1||strlen($bytes)>self::CHUNK||$fileBytes+strlen($bytes)>$plannedSize)throw new RuntimeException('Invalid recovery media chunk.');
                    $offset=0;while($offset<strlen($bytes)){$n=fwrite($sink,substr($bytes,$offset));if(!is_int($n)||$n<1)throw new RuntimeException('Staging file write failed.');$offset+=$n;}
                    hash_update($hash,$bytes);$fileBytes+=strlen($bytes);
                }elseif($type==='file_end'){
                    if($phase!==3||!is_resource($sink)||($r['path']??null)!==$current||!is_string($r['sha256']??null)
                        ||!preg_match('/^[a-f0-9]{64}$/D',$r['sha256'])||$fileBytes!==$plannedSize||!hash_equals(hash_final($hash),$r['sha256'])){
                        throw new RuntimeException('Recovery media integrity check failed.');
                    }
                    if(!fflush($sink))throw new RuntimeException('Recovery file flush failed.');
                    fclose($sink);$sink=null;$stats['files']++;$stats['file_bytes']+=$fileBytes;
                    if($stats['file_bytes']>self::MAX_BYTES)throw new RuntimeException('Recovery media exceeds safety limits.');
                    $current=null;
                }elseif($type==='end'){
                    if($current!==null||$meta===null||$stats['tables']!==$meta['tables']||$phase===0)throw new RuntimeException('Incomplete recovery archive.');
                    foreach($stats as $name=>$count)if(($r[$name]??null)!==$count)throw new RuntimeException('Recovery archive totals do not match.');
                    $ending=true;
                }else throw new RuntimeException('Unknown recovery archive record type.');
            });
            if(!$ending||!isset($seen['config/app-key.php'],$seen['config/license.php'],$seen['storage/install-state.json'],$seen['storage/license-cache.json']))throw new RuntimeException('Missing critical signed installation material.');
            $keySource=file_get_contents($stage.'/config/app-key.php');
            if(!is_string($keySource)||!preg_match('/return\s+[\'\"]([a-f0-9]{64})[\'\"]\s*;/',$keySource,$match)
                ||!InstallationState::valid((string)file_get_contents($stage.'/storage/install-state.json'),$match[1])){
                throw new RuntimeException('Restored installation signature is invalid.');
            }
            $installed=json_decode((string)file_get_contents($stage.'/storage/install-state.json'),true,8,JSON_THROW_ON_ERROR);
            if(($installed['edition']??null)!==$meta['edition'])throw new RuntimeException('Restored edition does not match the archive.');
            $this->assertEmptyDatabase($pdo);
            $marker=$this->root.'/storage/.backup-restore-pending';
            $pending=@fopen($marker,'x');if(!is_resource($pending))throw new RuntimeException('Unable to lock installation during recovery.');
            fwrite($pending,"Recovery in progress. Leave this marker until an operator reinitializes this failed target.\n");fclose($pending);@chmod($marker,0600);
            $committed=true; // after this line, failure leaves marker to fail closed
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            try {
                foreach($ddl as $sql)$pdo->exec($sql);
                $insertCache=[];
                $pdo->beginTransaction();
                StreamArchive::read($source,$hexKey,static function(array $r)use($pdo,&$insertCache):void{
                    if(($r['type']??null)!=='row')return;
                    $table=$r['table'];$cols=array_keys($r['fields']);
                    if(!self::tableName($table)||$cols===[])throw new RuntimeException('Invalid recovery row.');
                    $signature=$table."\0".implode('|',$cols);
                    if(!isset($insertCache[$signature])){
                        foreach($cols as $col)if(!self::identifier($col))throw new RuntimeException('Invalid recovery column.');
                        $columns=implode(',',array_map(static fn($c)=>'`'.$c.'`',$cols));
                        $marks=implode(',',array_fill(0,count($cols),'?'));
                        $insertCache[$signature]=$pdo->prepare('INSERT INTO `'.$table.'` ('.$columns.') VALUES ('.$marks.')');
                    }
                    $values=[];foreach($r['fields'] as $value)$values[]=$value===null?null:base64_decode($value,true);
                    $insertCache[$signature]->execute($values);
                });
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            finally{$pdo->exec('SET FOREIGN_KEY_CHECKS=1');}
            Schema::verify($pdo);
            if($pdo->query('SELECT edition FROM ac_installations WHERE id=1')->fetchColumn()!==$meta['edition']){
                throw new RuntimeException('Restored database edition does not match signed files.');
            }
            foreach(array_keys($seen) as $rel){
                $dst=$this->root.'/'.$rel;
                if(file_exists($dst)||is_link($dst))throw new RuntimeException('Recovery target file unexpectedly exists.');
                $this->makeDirs(dirname($dst),$this->root);
                if(!rename($stage.'/'.$rel,$dst))throw new RuntimeException('Could not publish private recovered file.');
                @chmod($dst,0600);
            }
            // Config/database.php is supplied for the TARGET DB, never overwritten by old credentials.
            @unlink($marker);
            $this->deleteStage($stage);
            return $stats;
        }finally{
            if(is_resource($sink))fclose($sink);
            if(!$committed)$this->deleteStage($stage);
        }
    }

    private function listTables(PDO $pdo):array
    {
        $all=$pdo->query('SELECT TABLE_NAME, ENGINE, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_ASSOC);
        $selected=[];
        foreach($all as $row){
            $name=(string)$row['TABLE_NAME'];
            if(!str_starts_with($name,'ac_'))continue;
            if(!self::tableName($name)||$row['ENGINE']!=='InnoDB'||$row['TABLE_TYPE']!=='BASE TABLE')throw new RuntimeException('Only ordinary ac_ InnoDB tables are supported.');
            $selected[]=$name;
        }
        if(count($selected)<5||count($selected)>250)throw new RuntimeException('Invalid Adult_Core database tables.');
        return $selected;
    }

    private function listFiles():array
    {
        $files=[];
        foreach(self::ALLOWED_CONFIG as $name)if(file_exists($this->root.'/config/'.$name)||is_link($this->root.'/config/'.$name))$files[]='config/'.$name;
        foreach(['storage','config/trusted-publishers'] as $sub){
            $dir=$this->root.'/'.$sub;if(is_link($dir))throw new RuntimeException('Backup refuses symlinked private directories.');if(!is_dir($dir))continue;
            $walker=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS));
            foreach($walker as $file){
                $path=$file->getPathname();$rel=substr($path,strlen($this->root)+1);
                if($sub==='storage' && (str_starts_with($rel,'storage/sessions/')||str_starts_with($rel,'storage/member-sessions/')||str_starts_with($rel,'storage/.')))continue;
                if(is_link($path))throw new RuntimeException('Backup refuses symlinked private files.');
                if(!$file->isFile()||!self::safePath($rel)){
                    if($rel==='storage/.gitkeep'||$rel==='storage/install-audit.jsonl'||$rel==='config/trusted-publishers/.gitkeep')continue;
                    throw new RuntimeException('Unexpected private file in site storage.');
                }
                $files[]=$rel;
            }
        }
        $files=array_values(array_unique($files));sort($files,SORT_STRING);
        foreach($files as $rel)if(is_link($this->root.'/'.$rel)||!is_file($this->root.'/'.$rel))throw new RuntimeException('Backup refuses non-regular private files.');
        if(count($files)>self::MAX_FILES)throw new RuntimeException('Too many media files to back up.');
        return $files;
    }

    private function checkDdl(string $name,string $ddl):void
    {
        if(!self::tableName($name)||strlen($ddl)>150000||!preg_match('~^CREATE TABLE `'.preg_quote($name,'~').'`\s*\(~D',$ddl)
            ||!str_contains($ddl,'ENGINE=InnoDB')||str_contains($ddl,';'))throw new RuntimeException('Unsafe or unsupported database schema.');
    }

    private function assertMysql(PDO $pdo):void
    {
        if($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')throw new RuntimeException('Only MySQL/MariaDB backups are supported.');
    }

    private function assertEmptyDatabase(PDO $pdo):void
    {
        if((int)$pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn()!==0)
            throw new RuntimeException('Recovery requires a completely empty dedicated database.');
    }

    private static function identifier(mixed $name):bool{return is_string($name)&&preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/D',$name)===1;}
    private static function tableName(string $name):bool{return preg_match('/^ac_[a-z0-9_]{1,60}$/D',$name)===1;}

    private function makeDirs(string $target,string $boundary):void
    {
        $boundary=rtrim($boundary,'/');
        if($target===$boundary)return;
        if(!str_starts_with($target,$boundary.'/'))throw new RuntimeException('Unsafe private destination.');
        $parts=explode('/',substr($target,strlen($boundary)+1));$current=$boundary;
        foreach($parts as $part){
            if($part===''||$part==='.'||$part==='..')throw new RuntimeException('Invalid backup path segment.');
            $current.='/'.$part;
            if(is_link($current)||file_exists($current)&&!is_dir($current))throw new RuntimeException('Unsafe path inside recovery target.');
            if(!is_dir($current)&&!mkdir($current,0700))throw new RuntimeException('Could not create private directory.');
        }
    }

    private function deleteStage(string $dir):void
    {
        if(!is_dir($dir))return;
        $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach($it as $item){if($item->isLink()||$item->isFile())@unlink($item->getPathname());else @rmdir($item->getPathname());}
        @rmdir($dir);
    }
}
