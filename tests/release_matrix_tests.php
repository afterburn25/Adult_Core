<?php

declare(strict_types=1);

require dirname(__DIR__).'/src/Support/Autoload.php';

use AdultCore\Support\ReleaseMilestones;
use AdultCore\Support\ReleaseReadiness;

$report=ReleaseMilestones::report();
if ($report['schema']!=='adult-core-release-workstreams-v1'
    || $report['production_ready']!==false
    || $report['open_count']!==10
    || count($report['workstreams'])!==10
    || ReleaseReadiness::productionReady()) {
    throw new RuntimeException('Release matrix incorrectly certifies development as production.');
}
$ids=[];
foreach($report['workstreams'] as $item){
    if (!is_int($item['id']) || $item['id']<1 || $item['id']>10
        || !is_string($item['name']) || trim($item['name'])===''
        || !in_array($item['status'],['external_acceptance_pending','incomplete','blocked'],true)
        || !is_bool($item['needs_external'])
        || !is_string($item['implemented']) || trim($item['implemented'])===''
        || !is_string($item['remaining']) || trim($item['remaining'])==='') {
        throw new RuntimeException('Release matrix has incomplete or misleading workstream metadata.');
    }
    $ids[]=$item['id'];
}
if ($ids!==range(1,10)) {
    throw new RuntimeException('Release matrix must enumerate exactly the ten ordered workstreams.');
}
$blockers=ReleaseReadiness::blockers();
if (count($blockers)!==10 || !str_contains($blockers[0], 'Cajun Web Pro')
    || !str_contains($blockers[9], 'Independent security')) {
    throw new RuntimeException('The production release checker must block all ten workstreams.');
}
$script=dirname(__DIR__).'/scripts/release-matrix.php';
$cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' --json';
$lines=[];$exit=0;
exec($cmd.' 2>&1',$lines,$exit);
$parsed=json_decode(implode("\n",$lines),true,16,JSON_THROW_ON_ERROR);
if($exit!==1 || $parsed!==$report){
    throw new RuntimeException('Release-matrix JSON CLI failed to preserve a blocked launch.');
}
$lines=[];exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>&1',$lines,$exit);
if($exit!==1 || !str_contains(implode("\n",$lines),'PRODUCTION BLOCKED')){
    throw new RuntimeException('Human-readable launch status must remain blocked.');
}
echo "Passed 10 workstream readiness checks and fail-closed JSON/CLI regression.\n";
