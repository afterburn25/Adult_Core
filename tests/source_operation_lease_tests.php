<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Release\SourceOperationLease;

$passed=0;
$assert=static function(bool $value,string $label) use (&$passed):void {
    if (!$value) throw new RuntimeException('Source operation coordination test: '.$label);
    ++$passed;
};
$reject=static function(callable $call,string $label) use ($assert):void {
    try { $call(); } catch (RuntimeException) { $assert(true,$label); return; }
    $assert(false,$label);
};
$remove=static function(string $path) use (&$remove):void {
    if (is_link($path)||is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') $remove($path.'/'.$name);
    }
    rmdir($path);
};
$root=dirname(__DIR__);
$tmp=sys_get_temp_dir().'/ac-op-lease-'.bin2hex(random_bytes(8));
mkdir($tmp,0700);
try {
    $site=$tmp.'/site';$parent=$tmp.'/coord';
    mkdir($site,0700);mkdir($site.'/public',0700);
    mkdir($site.'/src',0700);mkdir($site.'/src/Support',0700);
    file_put_contents($site.'/VERSION',"0.8.12-dev\n");
    copy($root.'/public/index.php',$site.'/public/index.php');
    copy($root.'/src/Support/Autoload.php',$site.'/src/Support/Autoload.php');
    mkdir($site.'/config',0700);
    file_put_contents($site.'/config/license.php',"KEEP CUSTOMER LICENSE\n");
    mkdir($parent,0700);
    $before=file_get_contents($site.'/config/license.php');

    $initial=SourceOperationLease::status($site,$parent);
    $assert($initial['status']==='not_acquired'
        && $initial['site_maintenance_enforced']===false,
        'Initially free operator lock does not claim site maintenance');
    $claim=SourceOperationLease::acquire($site,$parent);
    $token=$claim['release_token'];
    $assert(preg_match('/^[a-f0-9]{64}$/D',$token)===1
        && $claim['ready_for_activation']===false
        && $claim['site_maintenance_enforced']===false,
        'Exclusive lease returns strong one-time token, not update authorization');
    $lockDir=$parent.'/.adult-core-source-operation';
    $receipt=$lockDir.'/lease.json';
    $assert(is_file($receipt)
        && (fileperms($lockDir)&0077)===0
        && (fileperms($receipt)&0077)===0,
        'Lease directory and its metadata have owner-only permissions');
    $raw=file_get_contents($receipt);
    $assert(!str_contains($raw,$token)
        && str_contains($raw,hash('sha256',$token)),
        'Private receipt stores only hashed release token');
    $assert(SourceOperationLease::matchesTokenDigest(
        $site,$parent,hash('sha256',$token)),
        'Read-only digest comparison binds source marker to intact private lease');
    $assert(!SourceOperationLease::matchesTokenDigest(
        $site,$parent,str_repeat('0',64)),
        'An unrelated marker token hash cannot match the private lease');
    $assert(!SourceOperationLease::matchesTokenDigest(
        $site,$parent,'malformed'),
        'Malformed marker token fingerprint is always refused');
    $status=SourceOperationLease::status($site,$parent);
    $assert($status['status']==='operator_coordination_only'
        && !isset($status['release_token'])
        && !isset($status['token_sha256'])
        && $status['installed_root_sha256']===hash('sha256',realpath($site)),
        'Status identifies correct site but never exposes release credentials');
    $reject(static fn()=>SourceOperationLease::acquire($site,$parent),
        'Another operation cannot overwrite an existing atomic lease');
    $reject(static fn()=>SourceOperationLease::release($site,$parent,str_repeat('0',64)),
        'Incorrect token cannot clear lease');
    $reject(static fn()=>SourceOperationLease::release($site,$parent,'not-a-token'),
        'Invalid token format fails closed');
    $assert(is_file($receipt),'Busy lease remains intact after refused release');

    // Alter receipt to simulate corrupted/incomplete process writing.
    file_put_contents($receipt,'{"schema":"broken"}');
    $invalid=SourceOperationLease::status($site,$parent);
    $assert($invalid['status']==='incomplete_manual_recovery_required'
        && $invalid['ready_for_activation']===false,
        'Corrupt metadata remains blocking and requires manual recovery');
    $reject(static fn()=>SourceOperationLease::release($site,$parent,$token),
        'Corrupted receipt cannot be used to delete lock');
    file_put_contents($receipt,$raw);
    $assert(SourceOperationLease::status($site,$parent)['status']==='operator_coordination_only',
        'Restoring intact receipt restores read-only status without changing lock token');

    $other=$tmp.'/other-site';mkdir($other,0700);
    mkdir($other.'/public',0700);mkdir($other.'/src',0700);
    mkdir($other.'/src/Support',0700);
    file_put_contents($other.'/VERSION',"0.8.12-dev\n");
    copy($root.'/public/index.php',$other.'/public/index.php');
    copy($root.'/src/Support/Autoload.php',$other.'/src/Support/Autoload.php');
    $assert(SourceOperationLease::status($other,$parent)['status']==='incomplete_manual_recovery_required',
        'Lease record bound to installed source identity');
    $reject(static fn()=>SourceOperationLease::release($other,$parent,$token),
        'Token cannot release a different installed site');

    $public=$tmp.'/public-parent';mkdir($public,0755);
    $reject(static fn()=>SourceOperationLease::acquire($site,$public),
        'Publicly traversable lock parent refused');
    $reject(static fn()=>SourceOperationLease::acquire($site,$site),
        'Coordination cannot sit inside installed source root');
    $reject(static fn()=>SourceOperationLease::acquire($site,$tmp),
        'Coordination parent that contains the installed root is refused');
    $assert(!file_exists($public.'/.adult-core-source-operation'),
        'Unsafe coordination parent does not get any lock');
    if (function_exists('symlink') && symlink($parent,$tmp.'/linked-parent')) {
        $reject(static fn()=>SourceOperationLease::acquire($site,$tmp.'/linked-parent'),
            'Symlink coordination parent refused');
    }

    // Unexpected private files prevent cleanup of tampered metadata.
    file_put_contents($lockDir.'/unlisted','unexpected');chmod($lockDir.'/unlisted',0600);
    $reject(static fn()=>SourceOperationLease::release($site,$parent,$token),
        'Extra lease directory entries prevent unsafe deletion');
    unlink($lockDir.'/unlisted');

    // The CLI status and release work across separate PHP processes.
    $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/scripts/source-operation-lease.php')
        .' status '.escapeshellarg($site).' '.escapeshellarg($parent).' 2>/dev/null';
    $lines=[];exec($command,$lines,$exit);
    $parsed=json_decode(implode("\n",$lines),true);
    $assert($exit===0&&is_array($parsed)
        &&$parsed['status']==='operator_coordination_only'
        &&!isset($parsed['release_token']),'Offline CLI status has no token leakage');

    $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/scripts/source-operation-lease.php')
        .' release '.escapeshellarg($site).' '.escapeshellarg($parent);
    $pipes=[];
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Cannot spawn CLI release test.');
    fwrite($pipes[0],$token."\n");fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);fclose($pipes[1]);
    $err=stream_get_contents($pipes[2]);fclose($pipes[2]);
    $result=proc_close($process);
    $decoded=json_decode($out,true);
    $assert($result===0&&is_array($decoded)&&$decoded['status']==='released'
        &&$decoded['ready_for_activation']===false,
        'Release token is accepted from stdin rather than CLI argument');
    $assert(!file_exists($lockDir)
        &&SourceOperationLease::status($site,$parent)['status']==='not_acquired',
        'Released lease directory is gone with no lingering artifacts');

    // Test CLI token receipt: acquisition prints token only for operator.
    $command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/scripts/source-operation-lease.php')
        .' acquire '.escapeshellarg($site).' '.escapeshellarg($parent).' 2>/dev/null';
    $lines=[];exec($command,$lines,$code);
    $returned=json_decode(implode("\n",$lines),true);
    $assert($code===0 && is_array($returned)
        && preg_match('/^[a-f0-9]{64}$/D',$returned['release_token']??'')===1,
        'CLI can acquire a fresh private exclusive coordination lease');
    SourceOperationLease::release($site,$parent,$returned['release_token']);

    // If a process dies after exclusive mkdir but before writing receipt,
    // the incomplete lease must never silently disappear.
    mkdir($lockDir,0700);
    $stale=SourceOperationLease::status($site,$parent);
    $assert($stale['status']==='incomplete_manual_recovery_required',
        'Interrupted acquisition is blocking, never silently expired');
    $reject(static fn()=>SourceOperationLease::acquire($site,$parent),
        'Stale lease cannot be automatically taken over');
    $reject(static fn()=>SourceOperationLease::release($site,$parent,$token),
        'Incomplete claim cannot be deleted with previous token');
    $assert(file_get_contents($site.'/config/license.php')===$before
        &&file_get_contents($site.'/VERSION')==="0.8.12-dev\n",
        'All attempts leave site, local license and existing source untouched');
    echo "Passed {$passed} private source operation lease tests.\n";
} finally {
    $remove($tmp);
}
