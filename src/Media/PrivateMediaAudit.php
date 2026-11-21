<?php

declare(strict_types=1);

namespace AdultCore\Media;

use DirectoryIterator;
use PDO;
use RuntimeException;

/**
 * Read-only private-media integrity report. Never returns storage keys,
 * filenames, identity evidence, paths, or bytes. No deletion or mutation.
 */
final class PrivateMediaAudit
{
    public function __construct(private readonly string $root, private readonly PDO $pdo) {}

    /** @return array{ok:bool,db_rows:int,stored_files:int,missing_files:int,orphan_files:int,unsafe_paths:int,invalid_records:int,invalid_sizes:int,truncated:bool} */
    public function inspect(): array
    {
        // Repeated operator checks must reflect files changed since the
        // prior inspection, not PHP's process-local cached filesystem stats.
        clearstatcache(true);
        $result=[
            'ok'=>false,
            'db_rows'=>0,
            'stored_files'=>0,
            'missing_files'=>0,
            'orphan_files'=>0,
            'unsafe_paths'=>0,
            'invalid_records'=>0,
            'invalid_sizes'=>0,
            'truncated'=>false,
        ];
        $base=$this->root.'/storage/private-media';
        if (!is_dir($this->root.'/storage') || is_link($this->root.'/storage')
            || is_link($base) || (file_exists($base) && !is_dir($base))) {
            $result['unsafe_paths']++;
            return $result;
        }
        $stored=[];
        $q=$this->pdo->query('SELECT private_storage_key FROM ac_media LIMIT 50001');
        while (($row=$q->fetch(PDO::FETCH_ASSOC))!==false) {
            if (++$result['db_rows']>50000) {
                $result['truncated']=true;
                break;
            }
            $key=$row['private_storage_key']??null;
            if (!is_string($key) || !PrivateMedia::validKey($key)) {
                $result['invalid_records']++;
                continue;
            }
            $stored[$key]=true;
            $path=$base.'/'.$key.'.bin';
            if (is_link($path)) {
                $result['unsafe_paths']++;
                $result['missing_files']++;
            } elseif (!is_file($path)) {
                $result['missing_files']++;
            } else {
                $size=@filesize($path);
                if (!is_int($size) || $size<1 || $size>8*1024*1024) {
                    $result['invalid_sizes']++;
                }
            }
        }

        if (is_dir($base)) {
            $seen=0;
            foreach (new DirectoryIterator($base) as $item) {
                if ($item->isDot()) continue;
                if (++$seen>50000) {
                    $result['truncated']=true;
                    break;
                }
                $name=$item->getFilename();
                if ($item->isLink() || !$item->isFile()
                    || preg_match('/^([a-f0-9]{40})\.bin$/D',$name,$m)!==1) {
                    $result['unsafe_paths']++;
                    continue;
                }
                $result['stored_files']++;
                if (!isset($stored[$m[1]])) {
                    $result['orphan_files']++;
                    $size=$item->getSize();
                    if (!is_int($size) || $size<1 || $size>8*1024*1024) {
                        $result['invalid_sizes']++;
                    }
                }
            }
        }
        $result['ok']=!$result['truncated'];
        foreach (['missing_files','orphan_files','unsafe_paths','invalid_records','invalid_sizes'] as $name) {
            if ($result[$name]!==0) $result['ok']=false;
        }
        return $result;
    }
}
