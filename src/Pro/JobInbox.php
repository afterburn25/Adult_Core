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
            // A job ID is idempotent only if the stored signed-job data is
            // identical. A conflicting reissue, truncated receipt or unknown
            // status must never be misclassified as a successful duplicate.
            if (!is_file($path) || is_link($path) || !is_readable($path)
                || (filesize($path) ?: 0) > 20000) {
                throw new RuntimeException('Cannot accept Pro job.');
            }
            $raw = @file_get_contents($path);
            if (!is_string($raw) || strlen($raw) < 2) {
                throw new RuntimeException('Pro job receipt unavailable.');
            }
            try { $prior = json_decode($raw, true, 12, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new RuntimeException('Pro job receipt corrupted.'); }
            if (!is_array($prior) || !is_int($prior['received_at'] ?? null)
                || !in_array($prior['status'] ?? null, ['pending','processing','done','failed'], true)
                || !is_array($prior['job'] ?? null)
                || $prior['job'] !== $envelope['data']) {
                throw new RuntimeException('Conflicting or corrupted Pro job replay.');
            }
            return false;
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
