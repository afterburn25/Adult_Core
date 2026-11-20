<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';
use AdultCore\Support\ReleaseReadiness;
$root=dirname(__DIR__);
$missing=[];
foreach (['public/index.php','install/index.php','config/editions/lite.json','config/editions/pro.json','docs/ROADMAP.md','AGENTS.md'] as $f) {
    if (!is_file($root.'/'.$f)) $missing[]=$f;
}
if ($missing!==[]) {fwrite(STDERR,'Missing release sources: '.implode(', ',$missing)."\n");exit(2);}
echo 'Source layout: OK. '.count(ReleaseReadiness::blockers())." production release gates remain OPEN.\n";
foreach (ReleaseReadiness::blockers() as $item) echo 'BLOCKED: '.$item."\n";
exit(1); // Deliberately refuses to certify a production 1.0 release.
