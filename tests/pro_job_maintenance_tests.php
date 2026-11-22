<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Pro\JobEnvelope;
use AdultCore\Pro\JobInbox;
use AdultCore\Pro\ProJobAdmission;

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Pro maintenance admission: ' . $label);
    ++$checks;
};
$reject = static function (callable $task, string $label) use ($check): void {
    try { $task(); } catch (RuntimeException) { $check(true, $label); return; }
    $check(false, $label);
};
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') $remove($path . '/' . $name);
    }
    rmdir($path);
};
$tmp = sys_get_temp_dir() . '/ac-pro-maintenance-' . bin2hex(random_bytes(8));
mkdir($tmp,0700);
try {
    $root=$tmp.'/site';
    mkdir($root,0700);
    mkdir($root.'/storage',0700);
    $storage=$root.'/storage';
    $secret=str_repeat('k',32);
    $inbox=new JobInbox($root);
    $status=ProJobAdmission::status($root);
    $check($status['job_receipt_admission_open']===true
        && $status['existing_jobs_drained']===false
        && $status['pro_worker_quiesced']===false
        && $status['production_ready']===false,
        'Open receipt inbox does not claim production worker readiness');
    $baseline=JobEnvelope::create('baseline-pro-job-00001','video.thumbnail',['asset_id'=>3],$secret);
    $check($inbox->accept($baseline,$secret),'Signed Pro job admitted before maintenance');
    $oldReceipt=$storage.'/pro-jobs/'.hash('sha256','baseline-pro-job-00001').'.json';
    $beforeHash=hash_file('sha256',$oldReceipt);
    $count=0;
    foreach ([
        '.source-update-maintenance',
        '.backup-maintenance',
        '.backup-restore-pending',
        '.edition-upgrade-pending',
        '.license-update-pending',
    ] as $marker) {
        $path=$storage.'/'.$marker;
        file_put_contents($path,"private-marker\n");
        chmod($path,0600);
        $newId='blocked-pro-job-'.(++$count).'-test';
        $envelope=JobEnvelope::create($newId,'video.transcode',['asset_id'=>4],$secret);
        $expectedReceipt=$storage.'/pro-jobs/'.hash('sha256',$newId).'.json';
        $reject(static fn()=> $inbox->accept($envelope,$secret),
            'New signed Pro job refused during '.$marker);
        $reject(static fn()=> $inbox->accept($baseline,$secret),
            'Even duplicate job receipt cannot bypass '.$marker);
        $check(!file_exists($expectedReceipt),
            'Maintenance refusal never creates a Pro job receipt');
        $check(ProJobAdmission::status($root)['job_receipt_admission_open']===false,
            'Admission status fail-closed for '.$marker);
        unlink($path);
        $check(ProJobAdmission::status($root)['job_receipt_admission_open']===true,
            'Clearing maintenance marker restores normal intake');
    }
    $check(hash_file('sha256',$oldReceipt)===$beforeHash,
        'Maintenance never modifies an existing signed Pro job receipt');

    $sourceMarker=$storage.'/.source-update-maintenance';
    if (function_exists('symlink') && symlink($oldReceipt,$sourceMarker)) {
        $env=JobEnvelope::create('symlink-blocked-pro-job','video.thumbnail',['asset_id'=>9],$secret);
        $reject(static fn()=> $inbox->accept($env,$secret),
            'Symlink maintenance marker blocks Pro job intake even without regular file');
        $check(ProJobAdmission::status($root)['job_receipt_admission_open']===false,
            'Symlink marker remains an active denial condition');
        unlink($sourceMarker);
    }
    mkdir($sourceMarker,0700);
    $dirBlocked=JobEnvelope::create('directory-blocked-job','video.thumbnail',['asset_id'=>10],$secret);
    $reject(static fn()=> $inbox->accept($dirBlocked,$secret),
        'Directory at maintenance marker path blocks Pro job intake');
    rmdir($sourceMarker);

    $invalid=$root.'/storage';
    $saved=$root.'/storage-original';
    rename($invalid,$saved);
    if (function_exists('symlink') && symlink($saved,$invalid)) {
        $reject(static fn()=>ProJobAdmission::assertMayAccept($root),
            'Symlink storage root cannot be used for job intake');
        $check(ProJobAdmission::status($root)['job_receipt_admission_open']===false,
            'Unsafe private storage is reported as closed');
        unlink($invalid);
    }
    rename($saved,$invalid);
    $check($inbox->accept(JobEnvelope::create(
        'after-maintenance-job','video.transcode',['asset_id'=>11],$secret
    ),$secret),'Clean signed intake resumes after all maintenance markers are removed');
    $check(ProJobAdmission::status($root)['job_receipt_admission_open']===true
        && ProJobAdmission::status($root)['pro_worker_quiesced']===false,
        'Accepting signed receipts still does not imply worker drain or execution');
    echo "Passed {$checks} Pro job maintenance admission checks.\n";
} finally {
    $remove($tmp);
}
