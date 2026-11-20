<?php

declare(strict_types=1);

/** Developer/administrator CLI-only encrypted small-database snapshot; NOT a full site backup or restore. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require dirname(__DIR__) . '/src/Support/Autoload.php';
use AdultCore\Backup\EncryptedSnapshot;
use AdultCore\Database\Connection;

$root=dirname(__DIR__);
$output=$argv[1]??'';
$key=getenv('AC_BACKUP_KEY');
if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D',$key)
    || !is_string($output) || !str_ends_with($output,'.acbak')
    || is_file($output) || is_link($output) || !is_dir(dirname($output))) {
    fwrite(STDERR,"Usage: AC_BACKUP_KEY=<64-hex-chars> php scripts/backup-db.php /private/output.acbak\nRequires new file path and separate securely stored backup key.\n");exit(2);
}
try {
    $pdo=Connection::open($root);
    $tables=$pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
    $rows=[];
    foreach ($tables as $table) {
        if (!is_string($table) || preg_match('/^ac_[a-z0-9_]{1,50}$/D',$table)!==1) continue;
        $rows[$table]=[];
        $query=$pdo->query('SELECT * FROM `'.$table.'`');
        while (($row=$query->fetch(\PDO::FETCH_ASSOC))!==false) {
            $rows[$table][]=$row;
            // Early failure rather than risking excessive memory use on shared hosting.
            if (memory_get_usage(true)>24*1024*1024) throw new RuntimeException('Backup too large for small-DB snapshot tool.');
        }
    }
    $document=json_encode(['version'=>1,'created_at'=>gmdate('c'),'tables'=>$rows],JSON_THROW_ON_ERROR);
    $sealed=EncryptedSnapshot::seal($document,$key);
    $outputDir=realpath(dirname($output));
    $publicRoot=realpath($root.'/public');
    if ($outputDir===false || is_link(dirname($output)) || $outputDir===$publicRoot
        || ($publicRoot!==false && str_starts_with($outputDir,$publicRoot.DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('Backups must never be written under the public document root.');
    }
    $fp=@fopen($output,'x');
    if ($fp===false) throw new RuntimeException('Backup destination could not be created.');
    try {
        @chmod($output,0600);
        $written=0;
        while ($written<strlen($sealed)) {
            $n=fwrite($fp,substr($sealed,$written));
            if ($n===false || $n===0) throw new RuntimeException('Snapshot write failed.');
            $written+=$n;
        }
        if (!fflush($fp)) throw new RuntimeException('Snapshot write failed.');
    } catch (Throwable $e) { fclose($fp);@unlink($output);throw $e; }
    fclose($fp);
    echo "Encrypted private database snapshot written. This does NOT include media or allow restore.\n";
} catch (Throwable $e) { fwrite(STDERR,"Backup failed: ".$e->getMessage()."\n");exit(1); }
