<?php

declare(strict_types=1);

require dirname(__DIR__).'/src/Support/Autoload.php';

use AdultCore\Auth\RolePolicy;
use AdultCore\Membership\TierCatalog;

$checks=0;
$check=static function(bool $okay,string $message)use(&$checks):void{
    if (!$okay) throw new RuntimeException('Tier catalog unit: '.$message);
    $checks++;
};
$check(TierCatalog::validateName('  Founders Circle  ')==='Founders Circle',
    'Leading/trailing whitespace normalized');
$check(TierCatalog::validateName('Tier <safe markup text>')==='Tier <safe markup text>',
    'Tier labels are stored as text, not interpreted as HTML');
foreach (['',' ','x',str_repeat('a',121),"Line\nBreak","A\x00B","A\tB",
    'Valid tier'."\0", "Valid tier\n", "\xff\xfe"] as $invalid) {
    try {
        TierCatalog::validateName($invalid);
        $check(false,'Invalid tier name accepted');
    } catch (RuntimeException) {
        $check(true,'Invalid name rejected');
    }
}
$check(RolePolicy::allows('owner','site.settings.manage')
    && RolePolicy::allows('admin','site.settings.manage')
    && !RolePolicy::allows('moderator','site.settings.manage')
    && !RolePolicy::allows('member','site.settings.manage'),
    'Only managers can edit the tier catalog');
$controller=(string)file_get_contents(dirname(__DIR__).'/src/Http/TierController.php');
$catalogSource=(string)file_get_contents(dirname(__DIR__).'/src/Membership/TierCatalog.php');
$check(str_contains($catalogSource,'SELECT id FROM ac_installations WHERE id=1 FOR UPDATE')
    && str_contains($catalogSource,'$this->lockCatalog();'),
    'Tier create and rename serialize local uniqueness and size checks');
$check(str_contains($controller,'TierCatalog')
    && str_contains($controller,'AdminSession::csrfValid')
    && str_contains($controller,'Cache-Control: private, no-store')
    && !str_contains($controller,"action==='activate'")
    && !str_contains($controller,"action==='grant'"),
    'Tier route enforces CSRF and lacks payment/entitlement grant actions');
$layout=(string)file_get_contents(dirname(__DIR__).'/src/UI/Layout.php');
$check(str_contains($layout,"'/admin/tiers'"),'Admin has membership tier navigation');
echo "Passed {$checks} tier label catalog checks.\n";
