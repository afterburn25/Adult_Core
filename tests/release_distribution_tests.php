<?php

declare(strict_types=1);

/** Developer source ZIP acceptance, using only a disposable /tmp destination. */
if (!class_exists(ZipArchive::class)) {
    throw new RuntimeException('Distribution test requires PHP ext-zip.');
}
$root=dirname(__DIR__);
$dir=sys_get_temp_dir().'/adultcore-release-'.bin2hex(random_bytes(8));
if (!mkdir($dir,0700)) throw new RuntimeException('Could not create disposable release directory.');
$dest=$dir.'/adult-core-test.zip';
$checks=0;
$check=static function(bool $value,string $name)use(&$checks):void {
    if (!$value) throw new RuntimeException('Distribution test: '.$name);
    ++$checks;
};
try {
    $command=escapeshellarg(PHP_BINARY).' '
        .escapeshellarg($root.'/scripts/build-release.php').' '.escapeshellarg($dest).' 2>&1';
    $lines=[];$code=0;
    exec($command,$lines,$code);
    $check($code===0 && is_file($dest) && is_file($dest.'.sha256'),
        'Safe external source ZIP and digest generated');
    $hash=hash_file('sha256',$dest);
    $check(file_get_contents($dest.'.sha256')===$hash.'  '.basename($dest)."\n",
        'Digest matches completed release ZIP');
    $zip=new ZipArchive();
    $check($zip->open($dest)===true,'Complete source ZIP can be reopened');
    foreach ([
        'public/index.php','src/Membership/MemberLibrary.php',
        'src/Membership/TierCatalog.php','src/Http/TierController.php',
        'src/Auth/StaffRepository.php','src/Auth/StaffSchema.php','src/Http/StaffController.php',
        'public/assets/page-editor.js',
        'src/Member/MemberDirectory.php',
        'src/Member/MemberSafetySchema.php','src/Member/LocalMemberSafety.php',
        'src/Support/SiteHealth.php','src/Http/HealthController.php',
        'src/Website/PageRepository.php','src/Website/PageRevisionSchema.php',
        'src/Website/PageNavigationSchema.php','src/Website/PageHistorySchema.php',
        'src/Website/SiteIdentity.php','src/Website/SiteIdentitySchema.php',
        'src/Design/HighlightSchema.php',
        'src/Creator/CreatorSchema.php','src/Creator/LocalCreators.php',
        'src/Creator/CreatorReviewSchema.php','src/Creator/CreatorDraftReview.php',
        'src/Creator/CreatorFeedbackSchema.php','src/Creator/CreatorFeedbackHistory.php',
        'src/Http/CreatorReviewController.php',
        'src/Http/CreatorAdminController.php',
        'src/Membership/MemberSubscriptionSummary.php',
        'src/Moderation/ModerationRepository.php',
        'src/Support/ReleaseMilestones.php','src/Support/ReleaseReadiness.php',
        'src/Billing/WebhookInbox.php','src/Pro/JobInbox.php',
        'src/Media/PrivateMediaAudit.php','src/Media/ImageMetadataPolicy.php',
        'scripts/audit-private-media.php',
        'src/Verification/VerificationRegistry.php','src/Installer/InstallService.php',
        'src/Capability/PublicRootSafety.php','public/.htaccess',
        'docs/WORKSTREAM_ACCEPTANCE.md','config/editions/lite.json',
        'config/editions/pro.json','VERSION',
    ] as $required) {
        $check($zip->locateName($required)!==false,'Essential source included '.$required);
    }
    foreach ([
        'storage/license-cache.json','storage/.license-update-pending',
        'storage/packages/module-licenses/.lock','config/app-key.php',
        'config/license.php','config/database.php','config/verification.php',
    ] as $secret) {
        $check($zip->locateName($secret)===false,'No private customer file '.$secret);
    }
    $check($zip->numFiles>50,'Source ZIP is not an empty/degenerated archive');
    $zip->close();

    // The builder must never write a downloadable release under public/.
    $unsafe=$root.'/public/should-not-exist-release-test.zip';
    $lines=[];$code=0;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/scripts/build-release.php')
        .' '.escapeshellarg($unsafe).' 2>&1',$lines,$code);
    $check($code===2 && !is_file($unsafe),'Refuse archive destination inside public root');
    echo "Passed {$checks} source ZIP packaging and secret exclusion checks.\n";
} finally {
    @unlink($dest);
    @unlink($dest.'.sha256');
    @unlink($root.'/public/should-not-exist-release-test.zip');
    @unlink($root.'/public/should-not-exist-release-test.zip.sha256');
    @rmdir($dir);
}
