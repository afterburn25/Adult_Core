<?php

declare(strict_types=1);

/** Destructive ONLY to one new disposable MySQL table and isolated temp files. */
require dirname(__DIR__).'/src/Support/Autoload.php';

use AdultCore\Database\Schema;
use AdultCore\Media\PrivateMediaAudit;

if (getenv('AC_TEST_DB_CONFIRM') !== 'YES_THIS_IS_A_DISPOSABLE_DATABASE') {
    fwrite(STDERR,"Refusing media audit test without disposable database confirmation.\n");
    exit(2);
}
$host=getenv('AC_TEST_DB_HOST') ?: '127.0.0.1';
$port=(int)(getenv('AC_TEST_DB_PORT') ?: 3306);
$name=getenv('AC_TEST_DB_NAME');
$user=getenv('AC_TEST_DB_USER');
$pass=getenv('AC_TEST_DB_PASSWORD');
if (!is_string($name) || !is_string($user) || !is_string($pass)
    || $name==='' || $user==='' || $port<1 || $port>65535) {
    throw new RuntimeException('Disposable MySQL test configuration incomplete.');
}
$pdo=new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
    $user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
Schema::assertFresh($pdo);
$root=sys_get_temp_dir().'/ac-media-audit-'.bin2hex(random_bytes(8));
mkdir($root,0700);
mkdir($root.'/storage',0700);
$base=$root.'/storage/private-media';
mkdir($base,0700);
$ok=0;
$check=static function(bool $truth,string $label)use(&$ok):void {
    if (!$truth) throw new RuntimeException('Private media audit test failed: '.$label);
    ++$ok;
};
$first=str_repeat('a',40);
$second=str_repeat('b',40);
$orphan=str_repeat('c',40);
$path=static fn(string $key): string => $base.'/'.$key.'.bin';
try {
    $pdo->exec('CREATE TABLE ac_media (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        private_storage_key VARCHAR(190) NOT NULL UNIQUE,
        mime_type VARCHAR(70) NOT NULL DEFAULT 'image/png'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $insert=$pdo->prepare('INSERT INTO ac_media (private_storage_key) VALUES (?)');
    $insert->execute([$first]);
    $insert->execute([$second]);
    file_put_contents($path($first),'private-image-placeholder');
    $audit=new PrivateMediaAudit($root,$pdo);
    $result=$audit->inspect();
    $check(!$result['ok'] && $result['db_rows']===2
        && $result['stored_files']===1 && $result['missing_files']===1,
        'Missing private image is detected without exposing an identifier');
    $check(!str_contains(json_encode($result,JSON_THROW_ON_ERROR),$first)
        && !str_contains(json_encode($result,JSON_THROW_ON_ERROR),$root),
        'Audit output never exposes private storage keys or filesystem paths');

    file_put_contents($path($second),'second-image-placeholder');
    $check($audit->inspect()['ok']===true,'Matching files and rows pass');
    file_put_contents($path($orphan),'orphan-image-placeholder');
    $result=$audit->inspect();
    $check(!$result['ok'] && $result['orphan_files']===1,'Unreferenced private image flagged');
    unlink($path($orphan));
    file_put_contents($base.'/unexpected.txt','unexpected');
    $check($audit->inspect()['unsafe_paths']===1,'Unexpected private storage file flagged');
    unlink($base.'/unexpected.txt');
    file_put_contents($path($orphan),'');
    $result=$audit->inspect();
    $check(!$result['ok'] && $result['orphan_files']===1 && $result['invalid_sizes']>=1,
        'Empty orphan private image flagged');
    unlink($path($orphan));
    $pointer=$base.'/'.str_repeat('d',40).'.bin';
    if (!symlink($path($first),$pointer)) throw new RuntimeException('Symlink fixture unavailable.');
    $result=$audit->inspect();
    $check(!$result['ok'] && $result['unsafe_paths']>=1,'Symlinked private image flagged');
    unlink($pointer);
    $check($audit->inspect()['ok']===true,'Removing unsafe and orphan files restores clean audit');
    $backup=$base.'.previous';
    if (!rename($base,$backup) || !symlink($backup,$base)) {
        throw new RuntimeException('Directory symlink fixture unavailable.');
    }
    $check(!$audit->inspect()['ok'] && $audit->inspect()['unsafe_paths']>=1,
        'Symlinked private media directory rejected');
    unlink($base);
    rename($backup,$base);
    $check($audit->inspect()['ok']===true,'Restored private directory passes read-only inspection');

    // Explicit metadata mode is read-only and aggregate-only. Regular audit
    // remains compatible with old installations and does not decode images.
    $png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGO4p2gOAAMXATf6OqxSAAAAAElFTkSuQmCC',true);
    if (!is_string($png)) throw new RuntimeException('Audit PNG fixture missing.');
    file_put_contents($path($first),$png);
    file_put_contents($path($second),$png);
    $report=$audit->inspect(true);
    $check($report['ok'] && $report['metadata_checked']===2
        && $report['metadata_rejected']===0 && !$report['metadata_truncated'],
        'Bounded metadata scan accepts two clean registered private images');
    $data='GPSComment=private';$type='tEXt';
    $chunk=pack('N',strlen($data)).$type.$data.hex2bin(hash('crc32b',$type.$data));
    file_put_contents($path($first),substr($png,0,33).$chunk.substr($png,33));
    $report=$audit->inspect(true);
    $check(!$report['ok'] && $report['metadata_rejected']===1
        && $report['metadata_checked']===2,'Private metadata anomaly counted without mutation');
    $check(!str_contains(json_encode($report,JSON_THROW_ON_ERROR),$first)
        && !str_contains(json_encode($report,JSON_THROW_ON_ERROR),$root),
        'Metadata audit never exposes blob keys or paths');
    $check(is_file($path($first)),'Metadata audit never deletes a retained image');
    file_put_contents($path($first),$png);
    $check($audit->inspect(true)['ok'],'Restored clean images pass metadata mode');
    echo "Passed {$ok} disposable MySQL private media integrity audit checks.\n";
} finally {
    $pdo->exec('DROP TABLE IF EXISTS ac_media');
    foreach (glob($base.'/*')?:[] as $entry) {
        if (is_file($entry) || is_link($entry)) @unlink($entry);
    }
    @rmdir($base);
    @rmdir($root.'/storage');
    @rmdir($root);
}
