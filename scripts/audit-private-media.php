<?php

declare(strict_types=1);

require dirname(__DIR__).'/src/Support/Autoload.php';

use AdultCore\Database\Connection;
use AdultCore\Installer\InstallationGate;
use AdultCore\Media\PrivateMediaAudit;

$flags=array_slice($argv,1);
if (PHP_SAPI!=='cli' || count($flags)!==count(array_unique($flags))
    || count($flags)>2 || array_diff($flags,['--json','--metadata'])!==[]) {
    fwrite(STDERR, "Usage: php scripts/audit-private-media.php [--json] [--metadata]\n");
    exit(64);
}
$root=dirname(__DIR__);
try {
    if ((new InstallationGate($root))->status()!==InstallationGate::READY) {
        throw new RuntimeException('Private media audit requires a completed installation.');
    }
    $result=(new PrivateMediaAudit($root, Connection::open($root)))->inspect(in_array('--metadata',$flags,true));
    if (in_array('--json',$flags,true)) {
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    } else {
        echo 'Private media integrity: '.($result['ok']?'PASS':'REVIEW REQUIRED')."\n";
        foreach ($result as $name=>$value) {
            if ($name==='ok') continue;
            echo str_replace('_',' ',$name).': '.(is_bool($value)?($value?'yes':'no'):$value)."\n";
        }
    }
    exit($result['ok']?0:1);
} catch (Throwable) {
    // No DB credentials or private path details reach stdout/stderr.
    fwrite(STDERR,"Private media audit unavailable: installation or database requires attention.\n");
    exit(2);
}
