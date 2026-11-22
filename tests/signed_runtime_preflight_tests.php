<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Release\SourceArchive;
use AdultCore\Release\SourceStage;
use AdultCore\Release\SourceUpdatePreflight;
use AdultCore\Release\SourceRollbackReadiness;
use AdultCore\Release\RuntimeSourceStage;
use AdultCore\Release\RuntimeSourceStageVerifier;

if (!class_exists(ZipArchive::class)) {
    throw new RuntimeException('Signed transition preflight tests require PHP ext-zip.');
}
$checks=0;
$check=static function(bool $condition,string $label) use (&$checks):void{
    if (!$condition) throw new RuntimeException('Signed transition preflight: '.$label);
    ++$checks;
};
$reject=static function(callable $fn,string $label) use ($check):void{
    try { $fn(); } catch(RuntimeException) { $check(true,$label); return; }
    $check(false,$label);
};
$remove=static function(string $path) use (&$remove):void {
    if (is_file($path)||is_link($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach(scandir($path)?:[] as $entry) {
        if ($entry!=='.' && $entry!=='..') $remove($path.'/'.$entry);
    }
    rmdir($path);
};
$root=dirname(__DIR__);
$tmp=sys_get_temp_dir().'/ac-transition-'.bin2hex(random_bytes(8));
if (!mkdir($tmp,0700)) throw new RuntimeException('Could not create disposable test directory.');
try {
    $candidate=$tmp.'/next.zip';
    exec(escapeshellarg(PHP_BINARY).' '
        .escapeshellarg($root.'/scripts/build-release.php').' '
        .escapeshellarg($candidate).' 2>&1',$buildLog,$built);
    $check($built===0 && is_file($candidate),'Build a real unified Lite/Pro ZIP');

    // Fixture adds one safe new source asset to simulate an actual change.
    $zip=new ZipArchive();
    if ($zip->open($candidate)!==true) throw new RuntimeException('Candidate ZIP unreadable.');
    $zip->addFromString('public/assets/preflight-new.css','/* disposable new source */');
    $zip->setExternalAttributesName('public/assets/preflight-new.css',ZipArchive::OPSYS_UNIX,0100644<<16);
    $zip->close();

    $baseline=$tmp.'/previous.zip';
    if (!copy($candidate,$baseline)) throw new RuntimeException('Baseline fixture copy failed.');
    $zip=new ZipArchive();
    if ($zip->open($baseline)!==true) throw new RuntimeException('Baseline ZIP unreadable.');
    $zip->deleteName('public/assets/preflight-new.css');
    $zip->addFromString('VERSION',"0.8.11-old-dev\n");
    $zip->setExternalAttributesName('VERSION',ZipArchive::OPSYS_UNIX,0100644<<16);
    $auto=$zip->getFromName('src/Support/Autoload.php');
    if (!is_string($auto)) throw new RuntimeException('Baseline autoload source missing.');
    $zip->addFromString('src/Support/Autoload.php',$auto."\n// disposable older source\n");
    $zip->setExternalAttributesName('src/Support/Autoload.php',ZipArchive::OPSYS_UNIX,0100644<<16);
    $zip->close();

    $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
    if ($key===false) throw new RuntimeException('Cannot create disposable signing key.');
    $trusted=openssl_pkey_get_details($key)['key'];
    $oldJson=SourceArchive::manifestJson($baseline);
    $newJson=SourceArchive::manifestJson($candidate);
    openssl_sign($oldJson,$oldSigBytes,$key,OPENSSL_ALGO_SHA256);
    openssl_sign($newJson,$newSigBytes,$key,OPENSSL_ALGO_SHA256);
    $oldSig=base64_encode($oldSigBytes);
    $newSig=base64_encode($newSigBytes);
    $previous=SourceArchive::verifiedInventory($baseline,$oldJson,$oldSig,$trusted);
    $check($previous['version']==='0.8.11-old-dev','Old package is separately signed');
    $check(!SourceUpdatePreflight::runtimePath('install/index.php')
        && !SourceUpdatePreflight::runtimePath('themes/default/theme.json')
        && !SourceUpdatePreflight::runtimePath('config/license.php')
        && !SourceUpdatePreflight::runtimePath('storage/license-cache.json')
        && SourceUpdatePreflight::runtimePath('src/Auth/AdminSession.php')
        && SourceUpdatePreflight::runtimePath('public/index.php'),
        'Installer, themes/modules and private customer files excluded from runtime plan');

    // Reconstruct a complete installed OLD runtime solely from the signed old ZIP.
    // Do not copy installer/, test files, themes, module data or customer settings.
    $installed=$tmp.'/installed';
    mkdir($installed,0700);
    $zip=new ZipArchive();
    if ($zip->open($baseline,ZipArchive::RDONLY)!==true) throw new RuntimeException('Old ZIP missing.');
    foreach ($previous['files'] as $entry) {
        if (!SourceUpdatePreflight::runtimePath($entry['path'])) continue;
        $path=$installed.'/'.$entry['path'];
        $folder=dirname($path);
        if (!is_dir($folder)) mkdir($folder,0700,true);
        $bytes=$zip->getFromName($entry['path']);
        if (!is_string($bytes) || file_put_contents($path,$bytes)===false) {
            throw new RuntimeException('Cannot reconstruct signed prior source.');
        }
    }
    $zip->close();
    $check(!file_exists($installed.'/install/index.php'),
        'Production-style installed fixture has removed the web installer');
    mkdir($installed.'/storage',0700);
    file_put_contents($installed.'/storage/private-customer-record','do not touch');
    file_put_contents($installed.'/config/license.php','customer-license-should-remain');
    $customerBefore=file_get_contents($installed.'/config/license.php');
    $oldVersion=file_get_contents($installed.'/VERSION');

    $stage=$tmp.'/private-stage';
    SourceStage::prepare($installed,$candidate,$newJson,$newSig,$trusted,$stage);
    $inspect=static fn()=>SourceUpdatePreflight::inspect(
        $installed,$stage,$baseline,$oldJson,$oldSig,
        $candidate,$newJson,$newSig,$trusted
    );
    $previousStage=$tmp.'/private-previous-source';
    SourceStage::prepare($installed,$baseline,$oldJson,$oldSig,$trusted,$previousStage);
    $rollbackCheck=static fn()=>SourceRollbackReadiness::inspect(
        $installed,$previousStage,$stage,$baseline,$oldJson,$oldSig,
        $candidate,$newJson,$newSig,$trusted
    );
    $report=$inspect();
    $check($report['source_integrity_verified']===true
        && $report['candidate_stage_verified']===true
        && $report['read_only']===true
        && $report['ready_for_activation']===false
        && $report['rollback_implemented']===false,
        'Complete source verification still refuses live activation claims');
    $check($report['installed_version']==='0.8.11-old-dev'
        && $report['candidate_version']===trim((string)file_get_contents($root.'/VERSION')),
        'Preflight binds old and candidate signed versions');
    $check(in_array('public/assets/preflight-new.css',$report['would_add'],true)
        && in_array('src/Support/Autoload.php',$report['would_replace'],true)
        && in_array('VERSION',$report['would_replace'],true)
        && $report['unchanged_count']>50,
        'Signed diff correctly finds new, changed and unchanged runtime files');
    $check($report['installer_excluded']===true
        && $report['archive_entries_excluded']>0
        && !in_array('install/index.php',$report['would_add'],true)
        && !in_array('config/license.php',$report['would_add'],true),
        'Neither web installer nor private user config may be resurrected');
    $check($report['licensing_checked']===false
        && $report['migration_compatibility_checked']===false
        && $report['installed_database_migrations_checked']===false,
        'Missing external license, live SQL ledger and migration acceptance remain explicit');
    $check($report['signed_database_contract_checked']===true
        && $report['declared_database_contract_changed']===false
        && $report['new_declared_migration_ids'] === []
        && $report['database_sensitive_source_changes'] === []
        && $report['manual_database_review_required']===true,
        'Signed DB declaration is unchanged but changed autoloader PHP still requires DB review');

    // A separately signed older baseline that predates the DB contract
    // must FAIL CLOSED in the integrated transition preflight, not quietly
    // return an incomplete migration risk report.
    $noDbBaseline=$tmp.'/old-missing-db-contract.zip';
    if(!copy($baseline,$noDbBaseline))
        throw new RuntimeException('Cannot create missing-contract source fixture.');
    $noDbZip=new ZipArchive();
    if($noDbZip->open($noDbBaseline)!==true)
        throw new RuntimeException('Cannot open missing-contract source fixture.');
    if(!$noDbZip->deleteName('src/Database/release-schema-contract.json'))
        throw new RuntimeException('Cannot remove DB contract from negative fixture.');
    $noDbZip->close();
    $noDbJson=SourceArchive::manifestJson($noDbBaseline);
    if(!openssl_sign($noDbJson,$noDbSigBytes,$key,OPENSSL_ALGO_SHA256))
        throw new RuntimeException('Cannot sign source without database contract.');
    $reject(static fn()=>SourceUpdatePreflight::inspect(
        $installed,$stage,$noDbBaseline,$noDbJson,base64_encode($noDbSigBytes),
        $candidate,$newJson,$newSig,$trusted
    ),'Signed baseline with missing database contract blocks integrated source preflight');

    $recovery=$rollbackCheck();
    $check($recovery['previous_source_stage_verified']===true
        && $recovery['candidate_source_stage_verified']===true
        && $recovery['previous_source_files_preserved']===count($previous['files'])
        && $recovery['previous_version']==='0.8.11-old-dev',
        'Previous signed source is independently present and reverified');
    $check($recovery['read_only']===true
        && $recovery['rollback_implemented']===false
        && $recovery['ready_for_activation']===false
        && $recovery['database_rollback_verified']===false
        && $recovery['license_authorized']===false,
        'Verified rollback source alone never authorizes activation or database rollback');
    $oldStagedFile=$previousStage.'/src/Support/Autoload.php';
    $oldStagedBytes=file_get_contents($oldStagedFile);
    file_put_contents($oldStagedFile,"<?php echo 'tampered rollback source';");
    $reject($rollbackCheck,'Tampered previously staged source cannot be trusted as rollback');
    file_put_contents($oldStagedFile,$oldStagedBytes);
    $check($rollbackCheck()['previous_source_stage_verified']===true,
        'Restored signed previous-stage bytes pass re-verification');
    $pendingMarker=$previousStage.'/.ac-stage-pending';
    file_put_contents($pendingMarker,"incomplete\n");chmod($pendingMarker,0600);
    $reject($rollbackCheck,'Incomplete previous source stage must fail rollback material check');
    unlink($pendingMarker);
    $reject(static fn()=>SourceRollbackReadiness::inspect(
        $installed,$stage,$stage,$baseline,$oldJson,$oldSig,
        $candidate,$newJson,$newSig,$trusted
    ), 'Candidate may not substitute for separately signed previous source');
    $check($rollbackCheck()['previous_source_stage_verified']===true,
        'Distinct previous and new staged releases remain independently verified');

    $reject(static fn()=>SourceUpdatePreflight::inspect(
        $installed,$stage,$baseline,$oldJson,base64_encode(random_bytes(256)),
        $candidate,$newJson,$newSig,$trusted
    ), 'Forged OLD release signature refused');
    $reject(static fn()=>SourceUpdatePreflight::inspect(
        $installed,$stage,$baseline,$oldJson,$oldSig,
        $candidate,$newJson,base64_encode(random_bytes(256)),$trusted
    ), 'Forged NEW release signature refused');
    $reject(static fn()=>SourceUpdatePreflight::inspect(
        $installed,$stage,$candidate,$newJson,$newSig,
        $candidate,$newJson,$newSig,$trusted
    ), 'Same-version or downgrade transition refused');

    $local=$installed.'/src/Support/Autoload.php';
    $original=file_get_contents($local);
    file_put_contents($local,$original."\n/* local untracked edit */\n");
    $reject($inspect,'Modified locally patched runtime source refused');
    file_put_contents($local,$original);
    $check($inspect()['source_integrity_verified']===true,
        'Restored clean source passes signed baseline check');

    $unsigned=$installed.'/public/unsigned-hook.php';
    file_put_contents($unsigned,"<?php echo 'unsigned';");
    $reject($inspect,'Unmanaged executable PHP in public tree refused');
    unlink($unsigned);

    $target=$stage.'/src/Support/Autoload.php';
    $stageBytes=file_get_contents($target);
    file_put_contents($target,"<?php echo 'tamper';");
    $reject($inspect,'Tampered staged PHP refused on second audit');
    file_put_contents($target,$stageBytes);

    // A link in a local executable path must never be followed.
    $originalPath=$installed.'/public/index.php';
    $saved=$tmp.'/saved-index.php';
    rename($originalPath,$saved);
    if (function_exists('symlink') && symlink($saved,$originalPath)) {
        $reject($inspect,'Installed runtime symlink refused');
        unlink($originalPath);
    }
    rename($saved,$originalPath);

    $check($inspect()['source_integrity_verified']===true,
        'Checks pass after restoring all test modifications');
    $check(file_get_contents($installed.'/VERSION')===$oldVersion
        && file_get_contents($installed.'/config/license.php')===$customerBefore
        && file_get_contents($installed.'/storage/private-customer-record')==='do not touch',
        'Signed transition check never modifies installed code or private customer data');

    // Exercise CLI with all independently trusted detached inputs.
    $oldManifestPath=$tmp.'/old-manifest.json'; $oldSigPath=$tmp.'/old.sig';
    $newManifestPath=$tmp.'/new-manifest.json'; $newSigPath=$tmp.'/new.sig';
    $pubPath=$tmp.'/trusted.pem';
    foreach ([
        $oldManifestPath=>$oldJson,$oldSigPath=>$oldSig,$newManifestPath=>$newJson,
        $newSigPath=>$newSig,$pubPath=>$trusted
    ] as $path=>$value) file_put_contents($path,$value);
    $command=escapeshellarg(PHP_BINARY).' '
        .escapeshellarg($root.'/scripts/preflight-source-transition.php').' '
        .implode(' ',array_map('escapeshellarg',[
            $installed,$stage,$baseline,$oldManifestPath,$oldSigPath,
            $candidate,$newManifestPath,$newSigPath,$pubPath
        ])).' 2>&1';
    $lines=[];exec($command,$lines,$code);
    $check($code===0 && str_contains(implode("\n",$lines),'"ready_for_activation": false')
        && str_contains(implode("\n",$lines),'"installer_excluded": true'),
        'Offline CLI emits explicit no-activation signed transition JSON');

    $rollbackCli=escapeshellarg(PHP_BINARY).' '
        .escapeshellarg($root.'/scripts/check-source-rollback-material.php').' '
        .implode(' ',array_map('escapeshellarg',[
            $installed,$previousStage,$stage,$baseline,$oldManifestPath,$oldSigPath,
            $candidate,$newManifestPath,$newSigPath,$pubPath
        ])).' 2>&1';
    $rollbackLines=[];exec($rollbackCli,$rollbackLines,$rollbackExit);
    $check($rollbackExit===0
        && str_contains(implode("\n",$rollbackLines),'"previous_source_stage_verified": true')
        && str_contains(implode("\n",$rollbackLines),'"database_rollback_verified": false'),
        'CLI refuses to confuse signed source preservation with database recovery');
    $check(file_get_contents($installed.'/config/license.php')===$customerBefore,
        'Both source rollback verification and CLI preserve private license config');

    // Create an additional PRIVATE staging representation containing only
    // immutable runtime paths, excluding the post-install browser installer.
    $runtimeStage=$tmp.'/private-runtime-only';
    $runtime=RuntimeSourceStage::prepare(
        $installed,$stage,$candidate,$newJson,$newSig,$trusted,$runtimeStage
    );
    $check($runtime['status']==='runtime_stage_only'
        && $runtime['install_authorized']===false
        && $runtime['ready_for_activation']===false
        && $runtime['installer_excluded']===true,
        'Runtime stage does not authorize installer re-entry or active deployment');
    $check($runtime['runtime_files']===$report['candidate_runtime_files']
        && $runtime['excluded_archive_entries']===$report['archive_entries_excluded'],
        'Private runtime staging matches signed transition allowlist exactly');
    $check(is_file($runtimeStage.'/public/index.php')
        && is_file($runtimeStage.'/src/Support/Autoload.php')
        && is_file($runtimeStage.'/VERSION')
        && is_file($runtimeStage.'/public/assets/preflight-new.css'),
        'Signed runtime PHP, metadata and new asset are staged');
    $check(!file_exists($runtimeStage.'/install/index.php')
        && !is_dir($runtimeStage.'/install')
        && !is_dir($runtimeStage.'/tests')
        && !is_dir($runtimeStage.'/scripts')
        && !is_dir($runtimeStage.'/themes')
        && !is_dir($runtimeStage.'/modules')
        && !file_exists($runtimeStage.'/config/license.php')
        && !is_dir($runtimeStage.'/storage'),
        'Installer, developer files, themes/modules and all customer secrets excluded');
    $check(hash_file('sha256',$runtimeStage.'/src/Support/Autoload.php')
        ===hash_file('sha256',$stage.'/src/Support/Autoload.php')
        && hash_file('sha256',$runtimeStage.'/VERSION')
        ===hash_file('sha256',$stage.'/VERSION'),
        'Runtime-only staged files match independently signed source bytes');
    $check((fileperms($runtimeStage)&0077)===0
        && (fileperms($runtimeStage.'/public')&0077)===0
        && (fileperms($runtimeStage.'/public/index.php')&0077)===0,
        'Runtime source files and directories remain private');
    $runtimeReceipt=json_decode(
        (string)file_get_contents($runtimeStage.'/.ac-runtime-stage-complete.json'),true
    );
    $check(is_array($runtimeReceipt)
        && $runtimeReceipt['schema']==='adult-core-runtime-source-stage-v1'
        && $runtimeReceipt['installer_excluded']===true
        && $runtimeReceipt['signed_manifest_sha256']===hash('sha256',$newJson)
        && !file_exists($runtimeStage.'/.ac-runtime-stage-pending'),
        'Complete private runtime stage carries signed receipt and no incomplete marker');
    $verifyRuntime=static fn()=>RuntimeSourceStageVerifier::verify(
        $runtimeStage,$candidate,$newJson,$newSig,$trusted
    );
    $verifiedRuntime=$verifyRuntime();
    $check($verifiedRuntime['runtime_stage_verified']===true
        && $verifiedRuntime['ready_for_activation']===false
        && $verifiedRuntime['install_authorized']===false
        && $verifiedRuntime['runtime_files_verified']===$report['candidate_runtime_files'],
        'Independent runtime stage verification matches signed immutable inventory');
    $reject(static fn()=>RuntimeSourceStageVerifier::verify(
        $runtimeStage,$candidate,$newJson,base64_encode(random_bytes(256)),$trusted
    ),'Independent runtime verifier rejects forged publisher signature');
    $runtimePhp=$runtimeStage.'/public/index.php';
    $runtimePhpBytes=file_get_contents($runtimePhp);
    file_put_contents($runtimePhp,"<?php echo 'injected';");
    $reject($verifyRuntime,'Modified staged runtime PHP rejected');
    file_put_contents($runtimePhp,$runtimePhpBytes);
    $check($verifyRuntime()['runtime_stage_verified']===true,
        'Restoring signed runtime PHP permits re-verification');

    $injectedInstall=$runtimeStage.'/install';
    mkdir($injectedInstall,0700);
    file_put_contents($injectedInstall.'/index.php',"<?php echo 'installer';");
    chmod($injectedInstall.'/index.php',0600);
    $reject($verifyRuntime,'Reintroduced browser installer refused even when private');
    unlink($injectedInstall.'/index.php');rmdir($injectedInstall);

    $extraPhp=$runtimeStage.'/public/unsigned.php';
    file_put_contents($extraPhp,"<?php echo 'extra';");chmod($extraPhp,0600);
    $reject($verifyRuntime,'Additional unsanctioned runtime PHP refused');
    unlink($extraPhp);

    $movedRuntime=$tmp.'/temporarily-missing-runtime.php';
    rename($runtimePhp,$movedRuntime);
    $reject($verifyRuntime,'Missing signed runtime file refused');
    rename($movedRuntime,$runtimePhp);

    chmod($runtimePhp,0644);
    $reject($verifyRuntime,'Public-readable runtime source permissions refused');
    chmod($runtimePhp,0600);

    if(function_exists('symlink') && symlink($runtimePhp,$runtimeStage.'/public/evil-link.php')){
        $reject($verifyRuntime,'Runtime stage symlink entry refused');
        unlink($runtimeStage.'/public/evil-link.php');
    }

    $runtimePending=$runtimeStage.'/.ac-runtime-stage-pending';
    file_put_contents($runtimePending,"incomplete\n");chmod($runtimePending,0600);
    $reject($verifyRuntime,'Incomplete runtime stage marker refuses verification');
    unlink($runtimePending);

    $runtimeReceiptPath=$runtimeStage.'/.ac-runtime-stage-complete.json';
    $receiptOriginal=file_get_contents($runtimeReceiptPath);
    $fake=json_decode($receiptOriginal,true);$fake['installer_excluded']=false;
    file_put_contents($runtimeReceiptPath,json_encode($fake));
    $reject($verifyRuntime,'Runtime receipt cannot falsely authorize installer restoration');
    file_put_contents($runtimeReceiptPath,$receiptOriginal);
    $check($verifyRuntime()['runtime_stage_verified']===true,
        'Restored complete private runtime stage passes all checks');

    $reject(static fn()=>RuntimeSourceStage::prepare(
        $installed,$stage,$candidate,$newJson,$newSig,$trusted,$runtimeStage
    ),'Runtime staging never overwrites an existing complete directory');
    $forgedRuntime=$tmp.'/unsigned-runtime-stage';
    $reject(static fn()=>RuntimeSourceStage::prepare(
        $installed,$stage,$candidate,$newJson,base64_encode(random_bytes(256)),
        $trusted,$forgedRuntime
    ),'Forged runtime source signature refused before writes');
    $check(!file_exists($forgedRuntime),'Forgery creates no private runtime source');
    $insideRuntime=$installed.'/unapproved-runtime-stage';
    $reject(static fn()=>RuntimeSourceStage::prepare(
        $installed,$stage,$candidate,$newJson,$newSig,$trusted,$insideRuntime
    ),'Runtime staging cannot occur inside installed live source');
    $check(!file_exists($insideRuntime),'Installed application is not modified by unsafe destination');
    $publicParent=$tmp.'/nonprivate-runtime-parent';
    mkdir($publicParent,0755);
    $reject(static fn()=>RuntimeSourceStage::prepare(
        $installed,$stage,$candidate,$newJson,$newSig,$trusted,$publicParent.'/next'
    ),'Publicly traversable runtime staging parent rejected');
    $check(!file_exists($publicParent.'/next'),'Unsafe staging parent creates no output');

    $tamperSource=$stage.'/src/Support/Autoload.php';
    $signedStageBytes=file_get_contents($tamperSource);
    file_put_contents($tamperSource,"<?php echo 'modified after signature';");
    $wouldBeTampered=$tmp.'/tampered-runtime';
    $reject(static fn()=>RuntimeSourceStage::prepare(
        $installed,$stage,$candidate,$newJson,$newSig,$trusted,$wouldBeTampered
    ),'Changed full staged source cannot be copied into runtime snapshot');
    file_put_contents($tamperSource,$signedStageBytes);
    $check(!file_exists($wouldBeTampered),'Tampered source was refused before creating destination');

    $runtimeCliPath=$tmp.'/runtime-from-cli';
    $runtimeCli=escapeshellarg(PHP_BINARY).' '
        .escapeshellarg($root.'/scripts/stage-runtime-source.php').' '
        .implode(' ',array_map('escapeshellarg',[
            $installed,$stage,$candidate,$newManifestPath,$newSigPath,$pubPath,$runtimeCliPath
        ])).' 2>&1';
    $runtimeCliLines=[];exec($runtimeCli,$runtimeCliLines,$runtimeCliExit);
    $check($runtimeCliExit===0
        && is_file($runtimeCliPath.'/.ac-runtime-stage-complete.json')
        && str_contains(implode("\n",$runtimeCliLines),'"ready_for_activation": false'),
        'Offline CLI stages only approved runtime source with explicit non-deployable receipt');
    $verificationCli=escapeshellarg(PHP_BINARY).' '
        .escapeshellarg($root.'/scripts/verify-runtime-stage.php').' '
        .implode(' ',array_map('escapeshellarg',[
            $runtimeCliPath,$candidate,$newManifestPath,$newSigPath,$pubPath
        ])).' 2>&1';
    $verificationLines=[];exec($verificationCli,$verificationLines,$verificationExit);
    $check($verificationExit===0
        && str_contains(implode("\n",$verificationLines),'"runtime_stage_verified": true')
        && str_contains(implode("\n",$verificationLines),'"ready_for_activation": false'),
        'Offline runtime stage verifier CLI authenticates private-only source without deploy permission');

    $check(file_get_contents($installed.'/config/license.php')===$customerBefore
        && file_get_contents($installed.'/VERSION')===$oldVersion,
        'Runtime source preparation, re-verification and CLI preserve installed version and license');

    echo "Passed {$checks} signed runtime source, rollback material and private staging verification checks.\n";
} finally {
    $remove($tmp);
}
