<?php

declare(strict_types=1);

require dirname(__DIR__).'/src/Support/Autoload.php';

use AdultCore\Release\SignedCandidateDbContract;
use AdultCore\Release\SourceArchive;

if(!class_exists(ZipArchive::class)){
    throw new RuntimeException('Signed DB contract tests require ext-zip.');
}
$passed=0;
$check=static function(bool $ok,string $name)use(&$passed):void{
    if(!$ok)throw new RuntimeException('Signed DB contract review: '.$name);
    ++$passed;
};
$reject=static function(callable $fn,string $name)use($check):void{
    try{$fn();}catch(RuntimeException){$check(true,$name);return;}
    $check(false,$name);
};
$cleanup=static function(string $path)use(&$cleanup):void{
    if(is_file($path)||is_link($path)){unlink($path);return;}
    if(!is_dir($path))return;
    foreach(scandir($path)?:[] as $entry)
        if($entry!=='.'&&$entry!=='..')$cleanup($path.'/'.$entry);
    rmdir($path);
};
$repo=dirname(__DIR__);
$tmp=sys_get_temp_dir().'/ac-signed-db-review-'.bin2hex(random_bytes(8));
mkdir($tmp,0700);
try{
    $keys=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,
        'private_key_bits'=>2048]);
    if($keys===false)throw new RuntimeException('Cannot create disposable signing key.');
    $pub=openssl_pkey_get_details($keys)['key'];
    $sign=static function(string $raw)use($keys):string{
        if(!openssl_sign($raw,$sig,$keys,OPENSSL_ALGO_SHA256))
            throw new RuntimeException('Cannot sign disposable release.');
        return base64_encode($sig);
    };
    $contract=static function(int $revision,array $migrations=[]):string{
        return json_encode(['format'=>'adult-core-db-contract-v1',
            'family'=>'adult-core-mysql','revision'=>$revision,
            'declared_migrations'=>$migrations],
            JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    };
    $baseFiles=[
        'VERSION'=>"0.8.12-dev\n",
        'public/index.php'=>'<?php echo "fixture";',
        'public/.htaccess'=>'Options -Indexes',
        'install/index.php'=>'<?php echo "fixture installer";',
        'config/editions/lite.json'=>'{"edition":"lite"}',
        'config/editions/pro.json'=>'{"edition":"pro"}',
        'src/Support/Autoload.php'=>'<?php // autoload fixture',
        SignedCandidateDbContract::CONTRACT_PATH=>$contract(1),
        'public/assets/fixture.css'=>'body{padding:0}',
    ];
    $newFiles=$baseFiles;
    $newFiles['VERSION']="0.8.13-dev\n";
    $newFiles['public/assets/fixture.css']='body{padding:1px}';
    $writeZip=static function(string $path,array $contents):void{
        $zip=new ZipArchive();
        if($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)
            throw new RuntimeException('Cannot create source archive.');
        foreach($contents as $name=>$contentsText){
            if(!$zip->addFromString($name,$contentsText))
                throw new RuntimeException('Cannot create archive entry.');
            $zip->setExternalAttributesName($name,ZipArchive::OPSYS_UNIX,0100644<<16);
        }
        $zip->close();
    };
    $oldZip=$tmp.'/baseline.zip';$newZip=$tmp.'/candidate.zip';
    $writeZip($oldZip,$baseFiles);
    $writeZip($newZip,$newFiles);
    $oldManifest=SourceArchive::manifestJson($oldZip);
    $oldSignature=$sign($oldManifest);
    $newManifest=SourceArchive::manifestJson($newZip);
    $newSignature=$sign($newManifest);
    $inspect=static fn()=>SignedCandidateDbContract::compare(
        $oldZip,$oldManifest,$oldSignature,
        $newZip,$newManifest,$newSignature,$pub
    );
    $plan=$inspect();
    $check($plan['both_source_archives_authenticated']===true
        &&$plan['baseline_version']==='0.8.12-dev'
        &&$plan['candidate_version']==='0.8.13-dev'
        &&$plan['declared_database_contract_changed']===false
        &&$plan['manual_database_compatibility_review_required']===false,
        'Signed CSS-only candidate preserves unchanged declared schema');
    $check($plan['source_activation_authorized']===false
        &&$plan['candidate_schema_compatibility_verified']===false
        &&$plan['migrations_executed']===false
        &&$plan['production_ready']===false,
        'Even CSS-only changes never become automatic database clearance');

    $cliInputs=[$oldZip,$tmp.'/old.manifest.json',$tmp.'/old.sig',
        $newZip,$tmp.'/new.manifest.json',$tmp.'/new.sig',$tmp.'/publisher.pem'];
    foreach([$tmp.'/old.manifest.json'=>$oldManifest,$tmp.'/old.sig'=>$oldSignature,
        $tmp.'/new.manifest.json'=>$newManifest,$tmp.'/new.sig'=>$newSignature,
        $tmp.'/publisher.pem'=>$pub] as $path=>$bytes)file_put_contents($path,$bytes);
    $cmd=implode(' ',array_map('escapeshellarg',
        array_merge([PHP_BINARY,$repo.'/scripts/review-candidate-schema.php'],$cliInputs)));
    $output=[];exec($cmd.' 2>&1',$output,$exit);
    $check($exit===0&&str_contains(implode("\n",$output),
        '"candidate_schema_compatibility_verified": false'),
        'Redacted CLI requires separately signed source inputs and denies readiness');

    $modified=$newFiles;
    $modified['src/Website/PageRepository.php']='<?php // new SQL-risk content';
    $writeZip($newZip,$modified);
    $signed=SourceArchive::manifestJson($newZip);
    $report=SignedCandidateDbContract::compare(
        $oldZip,$oldManifest,$oldSignature,
        $newZip,$signed,$sign($signed),$pub);
    $check($report['manual_database_compatibility_review_required']===true
        &&$report['db_sensitive_source_changes']===[]
        &&$report['changed_php_application_files_count']===1,
        'Any new PHP application code requires manual SQL compatibility review');

    $modified=$newFiles;
    $modified['src/Website/PageSchema.php']='<?php // schema DDL may change';
    $writeZip($newZip,$modified);
    $signed=SourceArchive::manifestJson($newZip);
    $report=SignedCandidateDbContract::compare(
        $oldZip,$oldManifest,$oldSignature,
        $newZip,$signed,$sign($signed),$pub);
    $check(in_array('src/Website/PageSchema.php',
        $report['db_sensitive_source_changes'],true)
        &&$report['manual_database_compatibility_review_required']===true,
        'Signed new Schema.php implementation is surfaced for review');

    $modified=$newFiles;
    $modified[SignedCandidateDbContract::CONTRACT_PATH]=$contract(2,['add_archive_2026']);
    $writeZip($newZip,$modified);
    $signed=SourceArchive::manifestJson($newZip);
    $report=SignedCandidateDbContract::compare(
        $oldZip,$oldManifest,$oldSignature,
        $newZip,$signed,$sign($signed),$pub);
    $check($report['declared_database_contract_changed']===true
        &&$report['new_declared_migrations']===['add_archive_2026']
        &&$report['candidate_schema_revision']===2
        &&$report['source_activation_authorized']===false,
        'New publisher-declared migration creates a nonexecuting manual plan');

    $modified=$newFiles;
    $modified[SignedCandidateDbContract::CONTRACT_PATH]=$contract(1,['unexpected_new_migration']);
    $writeZip($newZip,$modified);
    $signed=SourceArchive::manifestJson($newZip);
    $report=SignedCandidateDbContract::compare(
        $oldZip,$oldManifest,$oldSignature,
        $newZip,$signed,$sign($signed),$pub);
    $check($report['declared_database_contract_changed']===true
        &&$report['manual_database_compatibility_review_required']===true,
        'Even an unbumped schema revision cannot conceal a new migration ID');

    // Test append-only migration history against an independently signed
    // baseline which already declares a prior version.
    $oldWithHistory=$baseFiles;
    $oldWithHistory[SignedCandidateDbContract::CONTRACT_PATH]=$contract(3,['start_2025']);
    $writeZip($oldZip,$oldWithHistory);
    $oldHistory=SourceArchive::manifestJson($oldZip);
    $oldHistorySignature=$sign($oldHistory);
    $modified=$newFiles;
    $modified[SignedCandidateDbContract::CONTRACT_PATH]=$contract(4,['rewritten_2025']);
    $writeZip($newZip,$modified);
    $nextHistory=SourceArchive::manifestJson($newZip);
    $reject(static fn()=>SignedCandidateDbContract::compare(
        $oldZip,$oldHistory,$oldHistorySignature,
        $newZip,$nextHistory,$sign($nextHistory),$pub),
        'Signed candidate cannot rewrite an earlier declared migration ID');

    $modified[SignedCandidateDbContract::CONTRACT_PATH]=$contract(2,['start_2025']);
    $writeZip($newZip,$modified);
    $nextHistory=SourceArchive::manifestJson($newZip);
    $reject(static fn()=>SignedCandidateDbContract::compare(
        $oldZip,$oldHistory,$oldHistorySignature,
        $newZip,$nextHistory,$sign($nextHistory),$pub),
        'Signed candidate cannot reduce the schema contract revision');

    $modified[SignedCandidateDbContract::CONTRACT_PATH]=$contract(4,['start_2025','start_2025']);
    $writeZip($newZip,$modified);
    $nextHistory=SourceArchive::manifestJson($newZip);
    $reject(static fn()=>SignedCandidateDbContract::compare(
        $oldZip,$oldHistory,$oldHistorySignature,
        $newZip,$nextHistory,$sign($nextHistory),$pub),
        'Duplicate declared migration IDs refused');

    $modified=$newFiles;
    unset($modified[SignedCandidateDbContract::CONTRACT_PATH]);
    $writeZip($newZip,$modified);
    $nextManifest=SourceArchive::manifestJson($newZip);
    $reject(static fn()=>SignedCandidateDbContract::compare(
        $oldZip,$oldHistory,$oldHistorySignature,
        $newZip,$nextManifest,$sign($nextManifest),$pub),
        'Missing signed candidate schema contract fails closed');

    $modified=$newFiles;
    $modified[SignedCandidateDbContract::CONTRACT_PATH]='{"format":"untrusted"}';
    $writeZip($newZip,$modified);
    $nextManifest=SourceArchive::manifestJson($newZip);
    $reject(static fn()=>SignedCandidateDbContract::compare(
        $oldZip,$oldHistory,$oldHistorySignature,
        $newZip,$nextManifest,$sign($nextManifest),$pub),
        'Unknown schema contract format refused');

    // Restore signed baseline and candidate and then alter one ZIP entry
    // WITHOUT updating the detached signed manifest.
    $writeZip($oldZip,$baseFiles);
    $writeZip($newZip,$newFiles);
    $original=SourceArchive::manifestJson($newZip);
    $sigOriginal=$sign($original);
    $tampered=$newFiles;
    $tampered[SignedCandidateDbContract::CONTRACT_PATH]=$contract(99,['evil_sql']);
    $writeZip($newZip,$tampered);
    $reject(static fn()=>SignedCandidateDbContract::compare(
        $oldZip,SourceArchive::manifestJson($oldZip),$sign(SourceArchive::manifestJson($oldZip)),
        $newZip,$original,$sigOriginal,$pub),
        'Modified candidate ZIP contract rejected even with valid original detached signature');
    $writeZip($newZip,$newFiles);
    $signed=SourceArchive::manifestJson($newZip);
    $reject(static fn()=>SignedCandidateDbContract::compare(
        $oldZip,SourceArchive::manifestJson($oldZip),$sign(SourceArchive::manifestJson($oldZip)),
        $newZip,$signed,base64_encode(random_bytes(256)),$pub),
        'Forged candidate publisher signature refused');

    $check(json_decode((string)file_get_contents($repo.'/src/Database/release-schema-contract.json'),true)
        ===json_decode($contract(1),true),'Tests did not modify real source schema contract');
    echo "Passed {$passed} signed database migration contract review tests.\n";
}finally{
    $cleanup($tmp);
}
