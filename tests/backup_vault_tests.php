<?php

declare(strict_types=1);

require dirname(__DIR__).'/src/Support/Autoload.php';
use AdultCore\Backup\BackupVerifier;
use AdultCore\Backup\BackupVault;
use AdultCore\Backup\StreamArchive;
use AdultCore\Installer\InstallationGate;
use AdultCore\Installer\InstallationState;

$n = 0;
function vaultCheck(bool $test, string $message): void {global $n; if (!$test) throw new RuntimeException('Backup vault: '.$message); $n++;}
function vaultReject(callable $callback, string $message): void {
    try {$callback();} catch (RuntimeException|JsonException) {vaultCheck(true,$message);return;}
    throw new RuntimeException('Backup vault failed to reject '.$message);
}
function vaultTreeDelete(string $path): void {
    if (!is_dir($path)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) {if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname()); else unlink($entry->getPathname());}
    rmdir($path);
}
function vaultFixture(string $path, string $backupKey, string $appKey, string $edition = 'lite', bool $badHash = false,
    bool $omitLicense = false, bool $badDdl = false, bool $badPath = false): void {
    $state = InstallationState::encode($edition,$appKey,'2026-11-20T20:00:00Z');
    $files = [
        'config/app-key.php' => "<?php return '".$appKey."';\n",
        'config/license.php' => "<?php return ['example' => 'synthetic-fixture'];\n",
        'storage/install-state.json' => $state,
        'storage/license-cache.json' => '{}',
        'storage/private-media/fixture.webp' => str_repeat('strictly-private-artificial-test-content',6000),
    ];
    if ($omitLicense) unset($files['config/license.php']);
    if ($badPath) $files['storage/../public/index.php'] = 'BAD';
    StreamArchive::write($path,$backupKey,static function(callable $emit)use($edition,$files,$badHash,$badDdl):void{
        $emit(['type'=>'meta','source_version'=>'0.8.8-backup-dev','edition'=>$edition,'tables'=>5,'created_at'=>'2026-11-20T20:00:00+00:00']);
        for ($i=0;$i<5;$i++) {
            $name='ac_fixture_'.$i;
            $ddl='CREATE TABLE `'.$name.'` (`id` int NOT NULL) ENGINE=InnoDB';
            if ($badDdl && $i===2) $ddl='CREATE TABLE `'.$name.'` (`id` int); DROP TABLE customers';
            $emit(['type'=>'table','name'=>$name,'ddl'=>$ddl]);
        }
        $emit(['type'=>'row','table'=>'ac_fixture_0','fields'=>['id'=>base64_encode('1')]]);
        $bytes=0;
        foreach($files as $name=>$content) {
            $emit(['type'=>'file_start','path'=>$name,'bytes'=>strlen($content)]);
            for($offset=0;$offset<strlen($content);$offset+=65536)
                $emit(['type'=>'file_chunk','path'=>$name,'data'=>base64_encode(substr($content,$offset,65536))]);
            $sha=hash('sha256',$content);
            if ($badHash && $name==='storage/private-media/fixture.webp') $sha=str_repeat('0',64);
            $emit(['type'=>'file_end','path'=>$name,'sha256'=>$sha]);
            $bytes+=strlen($content);
        }
        $emit(['type'=>'end','tables'=>5,'rows'=>1,'files'=>count($files),'file_bytes'=>$bytes]);
    });
}

$tmp=sys_get_temp_dir().'/adult-core-vault-test-'.bin2hex(random_bytes(7));
mkdir($tmp,0700);
try {
    foreach (['site','vault','stage','site/private-vault'] as $dir) mkdir($tmp.'/'.$dir,0700,true);
    $backupKey=bin2hex(random_bytes(32));$appKey=bin2hex(random_bytes(32));
    $archive=$tmp.'/stage/first.acfull';vaultFixture($archive,$backupKey,$appKey);
    $verifier=new BackupVerifier();$report=$verifier->verify($archive,$backupKey);
    vaultCheck($report['edition']==='lite'&&$report['source_version']==='0.8.8-backup-dev','Edition/version verified');
    vaultCheck($report['tables']===5&&$report['rows']===1&&$report['files']===5,'Database and file counts verified');
    vaultCheck($report['sha256']===hash_file('sha256',$archive),'Vault verification includes archive fingerprint');
    vaultCheck(!isset($report['app_key'],$report['license_key']),'Verification output excludes secrets');
    vaultReject(static fn()=> $verifier->verify($archive,bin2hex(random_bytes(32))),'Wrong key rejected before copy');
    vaultFixture($tmp.'/stage/bad-sha.acfull',$backupKey,$appKey,badHash:true);
    vaultReject(static fn()=> $verifier->verify($tmp.'/stage/bad-sha.acfull',$backupKey),'Authenticated but incorrect file checksum rejected');
    vaultFixture($tmp.'/stage/no-license.acfull',$backupKey,$appKey,omitLicense:true);
    vaultReject(static fn()=> $verifier->verify($tmp.'/stage/no-license.acfull',$backupKey),'Missing installation license rejected');
    vaultFixture($tmp.'/stage/bad-ddl.acfull',$backupKey,$appKey,badDdl:true);
    vaultReject(static fn()=> $verifier->verify($tmp.'/stage/bad-ddl.acfull',$backupKey),'DDL injection refused');
    vaultFixture($tmp.'/stage/bad-path.acfull',$backupKey,$appKey,badPath:true);
    vaultReject(static fn()=> $verifier->verify($tmp.'/stage/bad-path.acfull',$backupKey),'Unsafe path refused');
    vaultReject(static fn()=> (new BackupVault($tmp.'/site'))->ship($archive,$tmp.'/site/private-vault',$backupKey),'Vault nested inside site blocked');
    vaultReject(static fn()=> (new BackupVault($tmp.'/site'))->ship($archive,$tmp.'/vault',$backupKey,0),'Zero retention refused');
    $vault=new BackupVault($tmp.'/site');
    file_put_contents($tmp.'/vault/customer-notes.txt','never delete this file');
    $first=$vault->ship($archive,$tmp.'/vault',$backupKey,2);
    vaultCheck(is_file($first['archive'])&&$first['sha256']===$report['sha256'],'Verified and private vault copy preserved');
    vaultCheck((fileperms($first['archive'])&0007)===0,'Vault archive not world accessible');
    vaultReject(static fn()=> $vault->ship($first['archive'],$tmp.'/vault',$backupKey),'Source cannot already be inside vault');
    file_put_contents($tmp.'/vault/.adult-core-vault.lock','stale lock');
    vaultReject(static fn()=> $vault->ship($archive,$tmp.'/vault',$backupKey),'Stale vault lock blocks concurrent mutation');
    vaultCheck(file_exists($tmp.'/vault/.adult-core-vault.lock'),'Another worker lock is never removed');
    unlink($tmp.'/vault/.adult-core-vault.lock');
    usleep(3000);
    $second=$vault->ship($archive,$tmp.'/vault',$backupKey,2);
    usleep(3000);
    $third=$vault->ship($archive,$tmp.'/vault',$backupKey,2);
    vaultCheck($third['pruned']===1&&$third['retained']===2,'Rotation keeps two verified archives');
    vaultCheck(is_file($third['archive'])&&is_file($second['archive']),'Newest copies never pruned within the same second');
    vaultCheck(!is_file($first['archive']),'Oldest successfully verified archive pruned');
    vaultCheck(file_get_contents($tmp.'/vault/customer-notes.txt')==='never delete this file','Unrelated vault content untouched');
    // Existing bad/unknown archives must be preserved, never silently destroyed by retention.
    $older=$tmp.'/vault/adult-core-20000101T000000Z-012345abcdef.acfull';file_put_contents($older,'corrupt archive');
    $fourth=$vault->ship($archive,$tmp.'/vault',$backupKey,2);
    vaultCheck($fourth['skipped_invalid']===1&&is_file($older),'Corrupted historic archive preserved for operator review');
    vaultCheck(count(glob($tmp.'/vault/*.acfull'))===3,'Retention holds two verified and one quarantined corrupt file');
    vaultCheck(!file_exists($tmp.'/vault/.adult-core-vault.lock'),'Vault releases mutex after success');
    // Signed installation remains eligible for backup while *all* HTTP routes are locked.
    mkdir($tmp.'/site/storage');mkdir($tmp.'/site/config');
    file_put_contents($tmp.'/site/storage/install-state.json',InstallationState::encode('lite',$appKey));
    file_put_contents($tmp.'/site/config/app-key.php',"<?php return '$appKey';\n");
    file_put_contents($tmp.'/site/config/database.php',"<?php return ['driver'=>'mysql','database'=>'unit','username'=>'unit'];\n");
    $gate=new InstallationGate($tmp.'/site');
    vaultCheck($gate->status()===InstallationGate::READY&&$gate->eligibleForBackup(),'Signed installation ready for backup');
    file_put_contents($tmp.'/site/storage/.backup-maintenance','backup running');
    vaultCheck($gate->status()===InstallationGate::BACKUP_MAINTENANCE,'Backup maintenance locks public and admin HTTP');
    vaultCheck($gate->eligibleForBackup(),'CLI can export a valid signed site during maintenance');
    file_put_contents($tmp.'/site/storage/.backup-restore-pending','restore incomplete');
    vaultCheck($gate->status()===InstallationGate::BACKUP_RESTORE_PENDING&&!$gate->eligibleForBackup(),'Interrupted restore dominates backup maintenance');
    unlink($tmp.'/site/storage/.backup-restore-pending');
    file_put_contents($tmp.'/site/storage/.edition-upgrade-pending','upgrade incomplete');
    vaultCheck(!$gate->eligibleForBackup(),'Interrupted edition upgrade blocks backups');
    unlink($tmp.'/site/storage/.edition-upgrade-pending');
    unlink($tmp.'/site/storage/install-state.json');
    vaultCheck(!$gate->eligibleForBackup(),'Maintenance never bypasses unsigned installer state');
    echo "Passed $n verified-vault and maintenance safety tests.\n";
} finally {
    vaultTreeDelete($tmp);
}
