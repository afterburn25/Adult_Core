<?php

declare(strict_types=1);

/** Real disposable MySQL export -> empty database -> restore integration. Never use a customer database. */
require dirname(__DIR__).'/src/Support/Autoload.php';
use AdultCore\Backup\SiteBackup;
use AdultCore\Backup\StreamArchive;
use AdultCore\Backup\BackupVerifier;
use AdultCore\Backup\BackupVault;
use AdultCore\Release\SourceHttpRequestLock;
use AdultCore\Database\Schema;
use AdultCore\Database\Connection;
use AdultCore\Installer\InstallService;
use AdultCore\Installer\InstallationGate;
use AdultCore\Licensing\CajunWebClient;
use AdultCore\Website\SiteSchema;
use AdultCore\Website\PageRevisionSchema;
use AdultCore\Website\PageNavigationSchema;
use AdultCore\Website\PageHistorySchema;
use AdultCore\Website\SiteIdentitySchema;
use AdultCore\Website\SiteIdentity;
use AdultCore\Website\PageRepository;
use AdultCore\Member\MemberSchema;
use AdultCore\Member\MemberEmailSchema;
use AdultCore\Member\PasswordRecoverySchema;
use AdultCore\Member\PasswordRecovery;
use AdultCore\Member\MemberSafetySchema;
use AdultCore\Member\MemberProfileSchema;
use AdultCore\Member\MemberProfiles;
use AdultCore\Member\LocalMemberSafety;
use AdultCore\Member\EmailConfirmations;
use AdultCore\Creator\CreatorSchema;
use AdultCore\Creator\CreatorReviewSchema;
use AdultCore\Creator\CreatorFeedbackSchema;
use AdultCore\Creator\CreatorFeedbackHistory;
use AdultCore\Creator\LocalCreators;
use AdultCore\Creator\CreatorDraftReview;
use AdultCore\Design\DesignSchema;
use AdultCore\Design\HighlightSchema;

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
    SiteIdentitySchema::migrate($pdo);
    SiteIdentitySchema::migrate($pdo);
    $localBrand=new SiteIdentity($pdo);
    verifyRecovery($localBrand->current()===['site_name'=>'Recover Me','revision'=>1],
        'Installer local brand stays intact during repeatable site identity migration');
    $localBrand->rename('Recovered <Local Brand> & More',1);
    $beforeIdentityRecovery=$localBrand->current();
    verifyRecovery($beforeIdentityRecovery===['site_name'=>'Recovered <Local Brand> & More','revision'=>2],
        'Site-local public brand and optimistic revision are part of private site backup');
    PageRevisionSchema::migrate($pdo);
    PageNavigationSchema::migrate($pdo);
    PageHistorySchema::migrate($pdo);
    HighlightSchema::migrate($pdo);
    MemberEmailSchema::migrate($pdo);
    PasswordRecoverySchema::migrate($pdo);
    MemberSafetySchema::migrate($pdo);
    MemberSafetySchema::migrate($pdo);
    MemberProfileSchema::migrate($pdo);
    MemberProfileSchema::migrate($pdo);
    CreatorSchema::migrate($pdo);
    // Deliberately leave m14 unapplied until after one m13-era draft is saved.
    // This proves the additive review upgrade preserves existing private data.
    $pdo->prepare("UPDATE ac_pages SET title=?, body=?, status='published' WHERE slug='home'")
        ->execute(['Recovered creator page','Original member content preserved.']);
    // Preserve versioned content and local navigation in a full site archive.
    $pages=new PageRepository($pdo);
    $homeId=(int)$pdo->query("SELECT id FROM ac_pages WHERE slug='home'")->fetchColumn();
    $pages->save($homeId,'home','Recovered creator page',
        'Original member content preserved.','published',1,true,15);
    $pages->save(0,'backup-unlisted','Backup unlisted page',
        'Public direct URL with intentionally hidden navigation.','published',
        null,false,7);
    $beforePageRecovery=$pdo->query("SELECT id,slug,title,body,status,revision,show_in_menu,nav_position
        FROM ac_pages ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    verifyRecovery(count($beforePageRecovery)===2
        && (int)$beforePageRecovery[0]['revision']===2
        && (int)$beforePageRecovery[1]['show_in_menu']===0,
        'Revisioned homepage and intentionally unlisted page prepared for encrypted recovery');
    $beforeHistoryRecovery=$pdo->query('SELECT page_id,revision,slug,title,body,status,
        show_in_menu,nav_position FROM ac_page_history ORDER BY page_id,revision')
        ->fetchAll(PDO::FETCH_ASSOC);
    verifyRecovery(count($beforeHistoryRecovery)===3,
        'Owner page revision history includes baseline, new homepage revision and unlisted page');
    $pdo->exec("UPDATE ac_design_settings SET theme_key='velvet-plum' WHERE id=1");
    $pdo->prepare('UPDATE ac_design_settings SET
        highlight_1_title=?,highlight_1_body=? WHERE id=1')
        ->execute(['Recovered <welcome>','Owner-only backup verifies local information card text.']);
    $beforeHighlightRecovery=$pdo->query('SELECT highlight_1_title,highlight_1_body,
        highlight_2_title,highlight_2_body,highlight_3_title,highlight_3_body
        FROM ac_design_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    verifyRecovery(is_array($beforeHighlightRecovery)
        && $beforeHighlightRecovery['highlight_1_title']==='Recovered <welcome>',
        'Customized safe homepage cards included before encrypted backup');
    $pdo->prepare('INSERT INTO ac_users(email,password_hash,role,created_at,status) VALUES(?,?,?,UTC_TIMESTAMP(),?)')
        ->execute(['member@example.test',password_hash('member pass',PASSWORD_DEFAULT),'member','pending']);
    $memberId=(int)$pdo->lastInsertId();
    $ownerId=(int)$pdo->query("SELECT id FROM ac_users WHERE email='owner@example.test' AND role='admin'")->fetchColumn();
    verifyRecovery($memberId>0 && $ownerId>0,'Disposable local owner and member accounts exist');
    $privateProfile=new MemberProfiles($pdo);
    $privateProfile->save($memberId,0,'Saved private <Name>');
    $privateProfile->save($memberId,1,'Recovered <Private Name> & Co');
    $beforeMemberProfile=$pdo->query('SELECT user_id,display_name,revision
        FROM ac_member_profiles ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC);
    verifyRecovery(count($beforeMemberProfile)===1
        && $privateProfile->current($memberId)===[
            'display_name'=>'Recovered <Private Name> & Co','revision'=>2],
        'Private revisioned local member profile exists before encrypted backup');
    $emailConfirmation=new EmailConfirmations($pdo);
    verifyRecovery($emailConfirmation->confirm($emailConfirmation->issue($memberId)),
        'Local member email confirmation fixture established');
    $creators=new LocalCreators($pdo);
    $creatorId=$creators->provision($ownerId,'member@example.test','Recoverable <Creator>');
    $archiveDraftId=$creators->saveDraft($memberId,null,null,
        'Original private <plan>','This is local private planning text for an encrypted backup.');
    $legacySelect=$pdo->prepare('SELECT title,status,revision FROM ac_creator_drafts WHERE id=?');
    $legacySelect->execute([$archiveDraftId]);
    $beforeUpgrade=$legacySelect->fetch(PDO::FETCH_ASSOC);
    verifyRecovery(is_array($beforeUpgrade) && $beforeUpgrade['title']==='Original private <plan>'
        && $beforeUpgrade['status']==='private' && (int)$beforeUpgrade['revision']===1,
        'Local creator draft persists under m13 before optional text-review upgrade');
    CreatorReviewSchema::migrate($pdo);
    CreatorReviewSchema::migrate($pdo);
    CreatorFeedbackSchema::migrate($pdo);
    CreatorFeedbackSchema::migrate($pdo);
    $upgradedDraft=$creators->ownDrafts($memberId)[0]??null;
    verifyRecovery(is_array($upgradedDraft)
        && $upgradedDraft['title']===$beforeUpgrade['title']
        && $upgradedDraft['status']==='private'
        && (int)$upgradedDraft['revision']===1
        && (string)$upgradedDraft['review_note']==='',
        'Repeatable m14 review migration preserves prior m13 creator title, state and revision');
    $review=new CreatorDraftReview($pdo);
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
    $beforeFeedback=(new CreatorFeedbackHistory($pdo))->forOwner($ownerId,$creatorId);
    verifyRecovery(count($beforeFeedback)===2
        && $beforeFeedback[0]['decision']==='rejected'
        && $beforeFeedback[1]['decision']==='changes',
        'Revision-bound private feedback snapshots exist before encrypted backup');
    verifyRecovery(is_array($beforeCreatorRecovery)
        && count($beforeCreatorRecovery['drafts'])===3
        && count($beforeCreatorRecovery['events'])===6
        && count($review->queue($ownerId))===1,
        'Local creator private text states and numeric review event history seeded');
    $localSafety=new LocalMemberSafety($pdo);
    verifyRecovery($localSafety->change($ownerId,$memberId,'suspend','pending',
        'Private encrypted backup suspension decision')==='suspended',
        'Suspension preserves creator records but revokes member access');
    verifyRecovery($localSafety->change($ownerId,$memberId,'restore_pending','suspended',
        'Restore pending only after private review')==='pending',
        'Restore never activates member, payment, identity or consent flags');
    $beforeMemberAudit=$pdo->query('SELECT member_id,actor_id,old_status,new_status,reason
        FROM ac_member_status_actions ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    $beforeMemberGeneration=(new PasswordRecovery($pdo))->generation($memberId);
    verifyRecovery(count($beforeMemberAudit)===2 && $beforeMemberGeneration===2,
        'Member account safety audit and two session invalidations exist before backup');
    mkdir($src.'/storage/design-assets');
    $asset='\x89PNG\r\n\x1a\n'.random_bytes(300000); // binary-safe data preservation, not a public image
    file_put_contents($src.'/storage/design-assets/creator.png',$asset);
    mkdir($src.'/storage/private-media');file_put_contents($src.'/storage/private-media/private.dat',random_bytes(320000));
    mkdir($src.'/storage/sessions');file_put_contents($src.'/storage/sessions/session-a','secret cookie');
    file_put_contents($src.'/storage/.admin-login-ip-abc','[123]');
    verifyRecovery((new InstallationGate($src))->status()===InstallationGate::READY,'Source is installed');
    $originalState=file_get_contents($src.'/storage/install-state.json');
    $originalMfaHash=(string)$pdo->query("SELECT password_hash FROM ac_users WHERE email='owner@example.test'")->fetchColumn();
    $manual=new SiteBackup($src);
    $rejectManual=static function(bool $paused,bool $proStopped)use($manual,$archive,$backupKey):void{
        try{
            $manual->createCoordinated($archive,$backupKey,$paused,$proStopped);
            throw new RuntimeException('Unsafe manual backup accepted.');
        }catch(RuntimeException $error){
            if($error->getMessage()==='Unsafe manual backup accepted.')throw $error;
        }
    };
    $rejectManual(false,false);
    verifyRecovery(!file_exists($archive)&&!file_exists($src.'/storage/.backup-maintenance'),
        'Manual backup requires explicit operator writer pause before taking a marker');
    $reader=SourceHttpRequestLock::enter($src);
    try{$rejectManual(true,false);}finally{fclose($reader);}
    verifyRecovery(!file_exists($archive)&&!file_exists($src.'/storage/.backup-maintenance'),
        'Active local request refuses manual backup before marker or archive');
    $pdo->exec("UPDATE ac_installations SET edition='pro' WHERE id=1");
    try{
        $rejectManual(true,true);
        verifyRecovery(!file_exists($archive)&&!file_exists($src.'/storage/.backup-maintenance'),
            'Manual backup refuses mismatched signed/database edition and clears only its own marker');
    }finally{$pdo->exec("UPDATE ac_installations SET edition='lite' WHERE id=1");}
    $appKey=require $src.'/config/app-key.php';
    file_put_contents($src.'/storage/install-state.json',
        \AdultCore\Installer\InstallationState::encode('pro',$appKey));
    $pdo->exec("UPDATE ac_installations SET edition='pro' WHERE id=1");
    try{
        $rejectManual(true,false);
        verifyRecovery(!file_exists($archive)&&!file_exists($src.'/storage/.backup-maintenance'),
            'Signed Pro manual backup requires separate stopped-worker acknowledgment');
    }finally{
        $pdo->exec("UPDATE ac_installations SET edition='lite' WHERE id=1");
        file_put_contents($src.'/storage/install-state.json',$originalState);
    }
    $report=$manual->createCoordinated($archive,$backupKey,true,false);
    $stats=array_intersect_key($report,array_flip(['tables','rows','files','file_bytes']));
    verifyRecovery($report['edition']==='lite' && $report['sha256']===hash_file('sha256',$archive),
        'Manual coordinated backup verifies exact-site encrypted archive before returning');
    verifyRecovery((new InstallationGate($src))->status()===InstallationGate::READY
        && !file_exists($src.'/storage/.backup-maintenance'),
        'Manual backup releases only its own temporary maintenance marker');
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
    $rejectManual(true,false);
    verifyRecovery(hash_file('sha256',$archive)===$originalArchiveHash
        && !file_exists($src.'/storage/.backup-maintenance'),
        'Coordinated manual backup refuses existing archive without changing site or bytes');
    // Execute the real scheduled CLI against this disposable installed root.
    // Only its autoloader is forwarded to the checked-out source tree.
    mkdir($src.'/scripts',0700);
    mkdir($src.'/src/Support',0700,true);
    copy(dirname(__DIR__).'/scripts/backup-job.php',$src.'/scripts/backup-job.php');
    file_put_contents($src.'/src/Support/Autoload.php',
        '<?php require '.var_export(dirname(__DIR__).'/src/Support/Autoload.php',true).';');
    $scheduledStage=$base.'/scheduled-stage';
    $scheduledVault=$base.'/scheduled-vault';
    mkdir($scheduledStage,0700);mkdir($scheduledVault,0700);
    $runScheduled=static function(bool $paused,bool $proStopped)use(
        $src,$scheduledStage,$scheduledVault,$backupKey
    ):array{
        $env=array_merge(getenv(),[
            'AC_BACKUP_KEY'=>$backupKey,
            'AC_BACKUP_WRITES_PAUSED'=>$paused?'YES':'NO',
            'AC_PRO_JOBS_STOPPED'=>$proStopped?'YES':'NO',
        ]);
        $pipes=[];
        $process=proc_open([PHP_BINARY,$src.'/scripts/backup-job.php',$scheduledStage,$scheduledVault,'2'],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
        if(!is_resource($process))throw new RuntimeException('Cannot launch disposable scheduled backup CLI.');
        fclose($pipes[0]);$stdout=stream_get_contents($pipes[1]);fclose($pipes[1]);
        $stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);
        return [proc_close($process),$stdout,$stderr];
    };
    $rejectScheduled=static function(bool $paused,bool $proStopped)use(
        $runScheduled,$src,$scheduledStage,$scheduledVault
    ):void{
        [$exit,$stdout,$stderr]=$runScheduled($paused,$proStopped);
        verifyRecovery($exit!==0&&!str_contains($stdout,'backup complete')
            &&!(glob($scheduledStage.'/*.acfull')?:[])
            &&!(glob($scheduledVault.'/*.acfull')?:[])
            &&!file_exists($src.'/storage/.backup-maintenance')
            &&!file_exists($src.'/storage/.backup-job.lock'),
            'Refused scheduled backup does not publish archives or leave owned lock');
    };
    $rejectScheduled(false,false);
    $pdo->exec("UPDATE ac_installations SET edition='pro' WHERE id=1");
    try{$rejectScheduled(true,true);}
    finally{$pdo->exec("UPDATE ac_installations SET edition='lite' WHERE id=1");}
    file_put_contents($src.'/storage/install-state.json',
        \AdultCore\Installer\InstallationState::encode('pro',$appKey));
    $pdo->exec("UPDATE ac_installations SET edition='pro' WHERE id=1");
    try{$rejectScheduled(true,false);}
    finally{
        $pdo->exec("UPDATE ac_installations SET edition='lite' WHERE id=1");
        file_put_contents($src.'/storage/install-state.json',$originalState);
    }
    [$scheduledExit,$scheduledOut,$scheduledErr]=$runScheduled(true,false);
    $staged=glob($scheduledStage.'/*.acfull')?:[];
    $shipped=glob($scheduledVault.'/*.acfull')?:[];
    verifyRecovery($scheduledExit===0&&count($staged)===1&&count($shipped)===1
        &&str_contains($scheduledOut,'Encrypted lite backup complete')
        &&!str_contains($scheduledOut,$backupKey)
        &&!str_contains($scheduledOut,$appKey)
        &&!file_exists($src.'/storage/.backup-maintenance')
        &&!file_exists($src.'/storage/.backup-job.lock'),
        'Real scheduled CLI creates and ships one verified backup without leaking credentials');
    $scheduledReport=(new BackupVerifier())->verify($shipped[0],$backupKey,$appKey);
    verifyRecovery($scheduledReport['sha256']===hash_file('sha256',$staged[0])
        &&$scheduledReport['edition']==='lite','Scheduled vault contains the exact installed-site archive');

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
    $afterMemberProfile=$pdo->query('SELECT user_id,display_name,revision
        FROM ac_member_profiles ORDER BY user_id')->fetchAll(PDO::FETCH_ASSOC);
    verifyRecovery(MemberProfileSchema::installed($pdo)
        && $afterMemberProfile===$beforeMemberProfile
        && (new MemberProfiles($pdo))->current($memberId)===[
            'display_name'=>'Recovered <Private Name> & Co','revision'=>2],
        'Encrypted full-site restore retains exact m22 private member display name and revision');
    $afterMemberAudit=$pdo->query('SELECT member_id,actor_id,old_status,new_status,reason
        FROM ac_member_status_actions ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);
    verifyRecovery(MemberSafetySchema::installed($pdo)
        && $beforeMemberAudit===$afterMemberAudit
        && (new PasswordRecovery($pdo))->generation($memberId)===$beforeMemberGeneration
        && (new LocalMemberSafety($pdo))->member($ownerId,$memberId)['status']==='pending',
        'Encrypted backup preserves complete member safety audit, pending state and invalidated session epochs');
    verifyRecovery($pdo->query("SELECT title FROM ac_pages WHERE slug='home'")->fetchColumn()==='Recovered creator page','Creator page retained');
    verifyRecovery((new SiteIdentity($pdo))->current()===$beforeIdentityRecovery
        && SiteIdentitySchema::installed($pdo),
        'Encrypted restore retained exact local site name and revision without contacting licensing issuer');
    $afterPageRecovery=$pdo->query("SELECT id,slug,title,body,status,revision,show_in_menu,nav_position
        FROM ac_pages ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    verifyRecovery($afterPageRecovery===$beforePageRecovery,
        'Encrypted restore preserves every published page revision and local navigation setting');
    $afterHistoryRecovery=$pdo->query('SELECT page_id,revision,slug,title,body,status,
        show_in_menu,nav_position FROM ac_page_history ORDER BY page_id,revision')
        ->fetchAll(PDO::FETCH_ASSOC);
    verifyRecovery($afterHistoryRecovery===$beforeHistoryRecovery
        && PageHistorySchema::installed($pdo),
        'Encrypted restore retains all private page snapshots, version markers and earlier draft text');
    $afterHighlightRecovery=$pdo->query('SELECT highlight_1_title,highlight_1_body,
        highlight_2_title,highlight_2_body,highlight_3_title,highlight_3_body
        FROM ac_design_settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    verifyRecovery($afterHighlightRecovery===$beforeHighlightRecovery
        && HighlightSchema::installed($pdo),
        'Encrypted restore preserves customizable homepage card text and migration marker');
    $restoredPages=new PageRepository($pdo);
    verifyRecovery($restoredPages->published('backup-unlisted')!==null
        && !in_array('backup-unlisted',array_column($restoredPages->publishedMenu(),'slug'),true)
        && PageRevisionSchema::installed($pdo)
        && PageNavigationSchema::installed($pdo),
        'Recovered unlisted page remains public by URL but absent from menu with both migration markers');
    verifyRecovery($pdo->query('SELECT theme_key FROM ac_design_settings WHERE id=1')->fetchColumn()==='velvet-plum','Selected theme retained');
    $creatorAfterRecovery=(new LocalCreators($pdo))->privatePlansForOwner($ownerId,$creatorId);
    verifyRecovery($creatorAfterRecovery===$beforeCreatorRecovery,
        'All private local creator drafts, submitted/rejected/archived statuses, feedback and numeric audit are recovered exactly');
    $restoredFeedback=(new CreatorFeedbackHistory($pdo))->forOwner($ownerId,$creatorId);
    verifyRecovery($restoredFeedback===$beforeFeedback,
        'Both historical private review feedback decisions survive encrypted restore exactly');
    verifyRecovery((new EmailConfirmations($pdo))->verified($memberId)
        && (int)$pdo->query("SELECT COUNT(*) FROM ac_schema_migrations WHERE version IN ('m13_local_creator_workspace_v1','m14_creator_text_review_v1','m15_creator_feedback_history_v1')")->fetchColumn()===3,
        'Local creator account confirmation and additive migration markers survived restore');
    $restoredQueue=(new CreatorDraftReview($pdo))->queue($ownerId);
    verifyRecovery(count($restoredQueue)===1
        && (int)$restoredQueue[0]['id']===$submittedDraftId,
        'Private creator moderator queue is restored without public publication');
    $publicContentTable=(int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ac_content'")->fetchColumn();
    verifyRecovery($publicContentTable===0
        || (int)$pdo->query('SELECT COUNT(*) FROM ac_content')->fetchColumn()===0,
        'Restored local creator plans did not create public content on a fresh Lite installation');
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
