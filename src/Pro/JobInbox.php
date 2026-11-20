<?php

declare(strict_types=1);

namespace AdultCore\Pro;

use RuntimeException;

/** Signed, replay-safe job RECEIPT only. Does not run FFmpeg or shell commands. */
final class JobInbox
{
    public function __construct(private readonly string $root) {}

    public static function validPayload(array $data): bool
    {
        if (!in_array($data['type'] ?? null, ['video.transcode','video.thumbnail'],true)) return false;
        $payload=$data['payload']??null;
        if (!is_array($payload) || array_keys($payload)!==['asset_id']) return false;
        return is_int($payload['asset_id']) && $payload['asset_id']>0;
    }

    /** Return false for a duplicate accepted job. Stores NO privileged filesystem paths. */
    public function accept(array $envelope, string $secret): bool
    {
        if (!JobEnvelope::verify($envelope,$secret) || !self::validPayload($envelope['data'])) {
            throw new RuntimeException('Invalid or expired signed Pro job.');
        }
        $dir=$this->root.'/storage/pro-jobs';
        if (is_link($dir) || (!is_dir($dir) && !mkdir($dir,0700,true)) || !is_writable($dir)) {
            throw new RuntimeException('Pro job storage unavailable.');
        }
        @chmod($dir,0700);
        $id=$envelope['data']['id'];
        $path=$dir.'/'.hash('sha256',$id).'.json';
        $fp=@fopen($path,'x');
        if ($fp===false) {
            if (is_file($path) && !is_link($path)) return false;
            throw new RuntimeException('Cannot accept Pro job.');
        }
        try {
            $data=json_encode(['received_at'=>time(),'job'=>$envelope['data'],'status'=>'pending'],JSON_THROW_ON_ERROR);
            if (fwrite($fp,$data)!==strlen($data) || !fflush($fp)) throw new RuntimeException('Cannot persist Pro job.');
        } catch (\Throwable $e) {
            fclose($fp);@unlink($path);throw $e;
        }
        fclose($fp);@chmod($path,0600);
        return true;
    }
}
