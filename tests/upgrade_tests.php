<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Edition\EditionSelector;
use AdultCore\Installer\InstallationGate;
use AdultCore\Installer\InstallationState;
use AdultCore\Licensing\EditionUpgrade;

$tests=0;
$check = static function (bool $passed, string $name) use (&$tests):void {
    if (!$passed) throw new RuntimeException('Edition upgrade test failed: ' . $name);
    $tests++;
};
$root = sys_get_temp_dir().'/adultcore-upgrade-unit-'.bin2hex(random_bytes(6));
mkdir($root); mkdir($root.'/storage'); mkdir($root.'/config'); mkdir($root.'/config/editions');mkdir($root.'/public');
foreach (['lite','pro'] as $ed) copy(dirname(__DIR__).'/config/editions/'.$ed.'.json',$root.'/config/editions/'.$ed.'.json');
$secret=str_repeat('e',64);
file_put_contents($root.'/config/app-key.php','<?php return '.var_export($secret,true).';');
file_put_contents($root.'/config/database.php','<?php return '.var_export([
    'driver'=>'mysql','database'=>'example','username'=>'tester','host'=>'127.0.0.1','password'=>'private','port'=>3306
],true).';');
file_put_contents($root.'/storage/install-state.json',InstallationState::encode('lite',$secret));
try {
    $selector = new EditionSelector($root.'/config/editions');
    $lite = $selector->manifest('lite')['required_capabilities'];
    $pro = $selector->manifest('pro')['required_capabilities'];
    $check(!in_array('pro_agent',$lite,true),'Lite requirements do not claim Pro agent');
    $check(in_array('pro_agent',$pro,true),'Pro requires authenticated background agent');
    $upgrade = new EditionUpgrade($root,null,static fn():array=>$lite);
    $check(in_array('pro_agent',$upgrade->prerequisites(),true),'Missing verified Pro agent prevents upgrade');
    $ready = new EditionUpgrade($root,null,static fn():array=>$pro);
    $check($ready->prerequisites()===[],'Verified Pro capabilities satisfy the manifest');
    $check((new InstallationGate($root))->status()===InstallationGate::READY,'Valid signed Lite state remains ready');
    file_put_contents($root.'/storage/.edition-upgrade-pending','test interruption');
    $check((new InstallationGate($root))->status()===InstallationGate::EDITION_UPGRADE_PENDING,
        'Interrupted transition blocks entire site');
    unlink($root.'/storage/.edition-upgrade-pending');
    $check((new InstallationGate($root))->status()===InstallationGate::READY,
        'Removing test-only sentinel restores existing signed gate');
    try {$upgrade->rollback();$ok=false;}catch(RuntimeException){$ok=true;}
    $check($ok,'CLI recovery refuses missing pending transition');
    $check(is_file($root.'/storage/install-state.json'),'Refused recovery preserves original signed state');
    $script = file_get_contents(dirname(__DIR__).'/scripts/recover-edition-upgrade.php');
    $check(str_contains($script,"PHP_SAPI !== 'cli'"),'Emergency recovery refuses web requests');
    $check(str_contains($script,"'--rollback'"),'Emergency recovery requires explicit rollback command');
} finally {
    foreach (glob($root.'/storage/{*,.*}',GLOB_BRACE)?:[] as $p) if(is_file($p))unlink($p);
    foreach (glob($root.'/config/*')?:[] as $p) if(is_file($p))unlink($p);
    foreach (glob($root.'/config/editions/*')?:[] as $p)unlink($p);
    rmdir($root.'/config/editions');rmdir($root.'/storage');rmdir($root.'/config');rmdir($root.'/public');rmdir($root);
}
echo "Passed {$tests} Lite-to-Pro upgrade safety checks.\n";
