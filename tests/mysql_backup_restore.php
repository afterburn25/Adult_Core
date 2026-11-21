<?php

declare(strict_types=1);

/** Real disposable MySQL export -> empty database -> restore integration. Never use a customer database. */
require dirname(__DIR__).'/src/Support/Autoload.php';
use AdultCore\Backup\SiteBackup;
use AdultCore\Backup\StreamArchive;
use AdultCore\Backup\BackupVerifier;
use AdultCore\Backup\BackupVault;
use AdultCore\Database\Schema;
use AdultCore\Database\Connection;
use AdultCore\Installer\InstallService;
use AdultCore\Installer\InstallationGate;
use AdultCore\Licensing\CajunWebClient;
use AdultCore\Website\SiteSchema;
use AdultCore\Member\MemberSchema;
use AdultCore\Member\MemberEmailSchema;
use AdultCore\Member\EmailConfirmations;
use AdultCore\Creator\CreatorSchema;
use AdultCore\Creator\CreatorReviewSchema;
use AdultCore\Creator\LocalCreators;
use AdultCore\Creator\CreatorDraftReview;
use AdultCore\Design\DesignSchema;

if(getenv('AC_TEST_DB_CONFIRM')!=='YES_THIS_IS_A_DISPOSABLE_DATABASE')throw new RuntimeException('Do not run against a real installation.');
$host=getenv('AC_TEST_DB_HOST')?:'127.0.0.1';$port=(int)(getenv('AC_TEST_DB_PORT')?:3306);
$db=getenv('AC_TEST_DB_NAME');$user=getenv('AC_TEST_DB_USER');$pass=getenv('AC_TEST_DB_PASSWORD');
if(!is_string($db)||!is_string($user)||!is_string($pass)||!preg_match('/^[A-Za-z0-9_]{1,64}$/D',$db))throw new RuntimeException('Missing disposable database connection.');
$pdo=new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
Schema::assertFresh($pdo);
$base=sys_get_temp_dir().'/adult-core-backup-mysql-'.bin2hex(random_bytes(8));mkdir($base,0700);
$src=$base.'/source';$dst=$base.'/restored';$archive=$base.'/recovery.acfull';$vaultDir=$base.'/vault';mkdir($vaultDir,0700);
$backupKey=bin2hex(random_bytes(32));$check=0;
function verifyRecovery(bool $condition,string $msg):void{global $check;if(!$condition)throw new RuntimeException('Backup restore regression: '.$msg);$check++;}
function testRoot(string $root):void{
    mkdir($root,0700);foreach(['config','storage','install','public','config/editions','config/trusted-publishers'] as $dir)mkdir($root.'/'.$dir,0700,true);
    foreach(['lite','pro'] as $ed)copy(dirname(__DIR__).'/config/editions/'.$ed.'.json',$root.'/config/editions/'.$ed.'.json');
    file_put_contents($root.'/VERSION',file_get_contents(dirname(__DIR__).'/VERSION'));
    file_put_contents($root.'/install/index.php','<?php // fixture installer');
    foreach (['index.php','router.php','.htaccess'] as $webFile) {
        copy(dirname(__DIR__) . '/public/' . $webFile, $root . '/public/' . $webFile);
    }
    file_put_contents($root.'/storage/.gitkeep','');
    file_put_contents($root.'/config/trusted-publishers/.gitkeep','');
}
function removeDisposableTables(PDO $pdo):void{
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){
        if(!is_string($table)||!preg_match('/^ac_[a-z0-9_]+$/D',$table))throw new RuntimeException('Unexpected non-test table; refusing cleanup.');
        $pdo->exec('DROP TABLE `'.$table.'`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}
function removeTree(string $dir):void{
    if(!is_dir($dir))return;
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $f){if($f->isDir()&&!$f->isLink())rmdir($f->getPathname());else unlink($f->getPathname());}
    rmdir($dir);
}
try{
    testRoot($src);
    $inputs=['license_key'=>'ABCD-EF12-3456-7890','license_domain'=>'127.0.0.1','db_host'=>$host,'db_port'=>$port,
        'db_name'=>$db,'db_user'=>$user,'db_password'=>$pass,'site_name'=>'Recover Me',
        'admin_email'=>'owner@example.test','admin_password'=>'strong-temporary-passphrase'];
    $client=new CajunWebClient(static function(string $body):array{
        $row=json_decode($body,true,8,JSON_THROW_ON_ERROR);
        return ['status'=>200,'body'=>(($row['product_id']??null)===CajunWebClient::SKU_LITE_ONCE?'{"authorized":true}':'{"authorized":false}')];
    });
    (new InstallService($src,$client))->install($inputs);
    unlink($src.'/install/index.php');rmdir($src.'/install');
    SiteSchema::migrate($pdo);MemberSchema::migrate($pdo);DesignSchema::migrate($pdo);
    MemberEmailSchema::migrate($pdo);
    CreatorSchema::migrate($pdo);
    CreatorReviewSchema::migrate($pdo);
    $pdo->prepare("UPDATE ac_pages SET title=?, body=?, status='published' WHERE slug='home'")
        ->execute(['Recovered creator page','Original member content preserved.']);
    $pdo->exec("UPDATE ac_design_settings SET theme_key='velvet-plum' WHERE id=1");
    $pdo->prepare('INSERT INTO ac_users(email,password_hash,role,created_at,status) VALUES(?,?,?,UTC_TIMESTAMP(),?)')
        ->execute(['member@example.test',password_hash('member pass',PASSWORD_DEFAULT),'member','pending']);
    $memberId=(int)$pdo->lastInsertId();
    $ownerId=(int)$pdo->query("SELECT id FROM ac_users WHERE email='owner@example.test' AND role='admin'")->fetchColumn();
    verifyRecovery($memberId>0 && $ownerId>0,'Disposable local owner and member accounts exist');
    $emailConfirmation=new EmailConfirmations($pdo);
    verifyRecovery($emailConfirmation->confirm($emailConfirmation->issue($memberId)),
        'Local member email confirmation fixture established');
    $creators=new LocalCreators($pdo);
    $review=new CreatorDraftReview($pdo);
    $creatorId=$creators->provision($ownerId,'member@example.test','Recoverable <Creator>');
    $archiveDraftId=$creators->saveDraft($memberId,null,null,
        'Original private <plan>','This is local private planning text for an encrypted backup.');
    $review->submit($memberId,$archiveDraftId,1);
    $review->decide($ownerId,$archiveDraftId,2,'changes',
        'Revise the plan privately and do not upload identity evidence.');
    $creators->saveDraft($memberId,$archiveDraftId,3,
        'Archived revised <plan>','This revised planning text must remain private even after a restore.');
    $creators->changeDraftArchive($memberId,$archiveDraftId,4,'archive');
    $submittedDraftId=$creators->saveDraft($memberId,null,null,
        'Pending local review','This submission remains private while awaiting a local moderator.');
    $review->submit($memberId,$submittedDraftId,1);
    $rejectedDraftId=$creators->saveDraft($memberId,null,null,
        'Rejected local plan','A rejected plan with preserved private feedback and review history.');
    $review->submit($memberId,$rejectedDraftId,1);
    $review->decide($ownerId,$rejectedDraftId,2,'rejected',
        'Rejected <script>example</script> feedback that must remain in private storage.');
    $beforeCreatorRecovery=$creators->privatePlansForOwner($ownerId,$creatorId);
    verifyRecovery(is_array($beforeCreatorRecovery)
        && count($beforeCreatorRecovery['drafts'])===3
        && count($beforeCreatorRecovery['events'])===6
        && count($review->queue($ownerId))===1,
        'Local creator private text states and numeric review event history seeded');
    mkdir($src.'/storage/design-assets');
    $asset='\x89PNG\r\n\x1a\n'.random_bytes(300000); // binary-safe data preservation, not a public image
    file_put_contents($src.'/storage/design-assets/creator.png',$asset);
    mkdir($src.'/storage/private-media');file_put_contents($src.'/storage/private-media/private.dat',random_bytes(320000));
    mkdir($src.'/storage/sessions');file_put_contents($src.'/storage/sessions/session-a','secret cookie');
    file_put_contents($src.'/storage/.admin-login-ip-abc','[123]');
    verifyRecovery((new InstallationGate($src))->status()===InstallationGate::READY,'Source is installed');
    $originalState=file_get_contents($src.'/storage/install-state.json');
    $originalMfaHash=(string)$pdo->query("SELECT password_hash FROM ac_users WHERE email='owner@example.test'")->fetchColumn();
    file_put_contents($src.'/storage/.backup-maintenance','site writes paused for disposal fixture');
    verifyRecovery((new InstallationGate($src))->status()===InstallationGate::BACKUP_MAINTENANCE,'Maintenance gate locks HTTP during real database backup');
    $stats=(new SiteBackup($src))->create(Connection::open($src),$archive,$backupKey);
    unlink($src.'/storage/.backup-maintenance');
    verifyRecovery((new InstallationGate($src))->status()===InstallationGate::READY,'Temporary backup maintenance releases signed site');
    verifyRecovery($stats['tables']>=7&&$stats['rows']>=6&&$stats['files']>=6,'Encrypted backup contains schema, rows and private files');
    verifyRecovery(is_file($archive)&&!str_contains((string)file_get_contents($archive),'Original member content preserved.'),'Archive is encrypted');
    $originalArchiveHash=hash_file('sha256',$archive);
    $checkReport=(new BackupVerifier())->verify($archive,$backupKey);
    verifyRecovery($checkReport['sha256']===$originalArchiveHash&&$checkReport['edition']==='lite','Read-only verifier authenticates real MySQL and private media archive');
    verifyRecovery($checkReport['tables']===$stats['tables']&&$checkReport['rows']===$stats['rows']&&$checkReport['files']===$stats['files'],'Read-only semantic counts match the real database backup');
    $vaultCopy=(new BackupVault($src))->ship($archive,$vaultDir,$backupKey,2);
    verifyRecovery(is_file($vaultCopy['archive'])&&$vaultCopy['sha256']===$originalArchiveHash,'Vault contains an identical authenticated encrypted backup');
    verifyRecovery((new BackupVerifier())->verify($vaultCopy['archive'],$backupKey)['file_bytes']===$stats['file_bytes'],'Transferred media blocks reverify with the offline key');
    try{(new SiteBackup($src))->create(Connection::open($src),$archive,$backupKey);throw new RuntimeException('Archive overwrite accepted');}
    catch(RuntimeException $e){if($e->getMessage()==='Archive overwrite accepted')throw $e;}
    verifyRecovery(is_file($archive)&&hash_file('sha256',$archive)===$originalArchiveHash,'Refused overwrite must preserve original backup byte-for-byte');
    removeDisposableTables($pdo);Schema::assertFresh($pdo);
    testRoot($dst);
    copy($src.'/config/database.php',$dst.'/config/database.php');
    $recovery=new SiteBackup($dst);
    try{$recovery->restore(Connection::open($dst),$archive,bin2hex(random_bytes(32)));throw new RuntimeException('Wrong-key restore accepted');}
    catch(RuntimeException $e){if($e->getMessage()==='Wrong-key restore accepted')throw $e;}
    Schema::assertFresh($pdo);
    verifyRecovery(!file_exists($dst.'/storage/.backup-restore-pending'),'Wrong-key preflight does not write DB or lock target');
    $broken=$base.'/truncated.acfull';$bytes=file_get_contents($archive);
    file_put_contents($broken,substr($bytes,0,-100));
    try{$recovery->restore(Connection::open($dst),$broken,$backupKey);throw new RuntimeException('Truncated restore accepted');}
    catch(RuntimeException $e){if($e->getMessage()==='Truncated restore accepted')throw $e;}
    Schema::assertFresh($pdo);
    verifyRecovery(!file_exists($dst.'/storage/.backup-restore-pending'),'Corrupt preflight refuses before modifying DB');
    $restored=$recovery->restore(Connection::open($dst),$vaultCopy['archive'],$backupKey);
    verifyRecovery($restored===$stats,'Backup and restore counters agree');
    verifyRecovery((int)$pdo->query('SELECT COUNT(*) FROM ac_users')->fetchColumn()===2,'Original owner and member accounts retained');
    verifyRecovery($pdo->query("SELECT title FROM ac_pages WHERE slug='home'")->fetchColumn()==='Recovered creator page','Creator page retained');
    verifyRecovery($pdo->query('SELECT theme_key FROM ac_design_settings WHERE id=1')->fetchColumn()==='velvet-plum','Selected theme retained');
    $creatorAfterRecovery=(new LocalCreators($pdo))->privatePlansForOwner($ownerId,$creatorId);
    verifyRecovery($creatorAfterRecovery===$beforeCreatorRecovery,
        'All private local creator drafts, submitted/rejected/archived statuses, feedback and numeric audit are recovered exactly');
    verifyRecovery((new EmailConfirmations($pdo))->verified($memberId)
        && (int)$pdo->query("SELECT COUNT(*) FROM ac_schema_migrations WHERE version IN ('m13_local_creator_workspace_v1','m14_creator_text_review_v1')")->fetchColumn()===2,
        'Local creator account confirmation and additive migration markers survived restore');
    $restoredQueue=(new CreatorDraftReview($pdo))->queue($ownerId);
    verifyRecovery(count($restoredQueue)===1
        && (int)$restoredQueue[0]['id']===$submittedDraftId,
        'Private creator moderator queue is restored without public publication');
    verifyRecovery((int)$pdo->query('SELECT COUNT(*) FROM ac_content')->fetchColumn()===0,
        'Restored local creator planning records did not create public content');
    verifyRecovery($pdo->query('SELECT edition FROM ac_installations WHERE id=1')->fetchColumn()==='lite','Edition retained');
    verifyRecovery($pdo->query("SELECT password_hash FROM ac_users WHERE email='owner@example.test'")->fetchColumn()===$originalMfaHash,'Password hash retained');
    verifyRecovery(file_get_contents($dst.'/storage/design-assets/creator.png')===$asset,'Design images recover byte-identically');
    verifyRecovery(hash_file('sha256',$src.'/storage/private-media/private.dat')===hash_file('sha256',$dst.'/storage/private-media/private.dat'),'Private content recovered byte-identically');
    verifyRecovery(file_get_contents($dst.'/storage/install-state.json')===$originalState,'Signed installation state retained');
    verifyRecovery(!is_dir($dst.'/storage/sessions')&&!file_exists($dst.'/storage/.admin-login-ip-abc'),'No sessions or rate counters restored');
    verifyRecovery((new InstallationGate($dst))->status()===InstallationGate::CLEANUP_REQUIRED,'Manual installer deletion still required after restore');
    verifyRecovery(!is_file($dst.'/storage/.backup-restore-pending'),'Recovery marker only clears upon success');
    verifyRecovery(is_file($dst.'/config/database.php'),'Provided target DB config remains intact');
    try{$recovery->restore(Connection::open($dst),$archive,$backupKey);throw new RuntimeException('Restore overwrote existing site');}
    catch(RuntimeException $e){if($e->getMessage()==='Restore overwrote existing site')throw $e;}
    $check++;
    echo "Passed $check real MySQL encrypted full-site recovery checks.\n";
}finally{
    removeDisposableTables($pdo);
    removeTree($base);
}
