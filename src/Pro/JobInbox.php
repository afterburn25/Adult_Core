<?php

declare(strict_types=1);

namespace AdultCore\Pro;

use AdultCore\Release\SourceHttpRequestLock;
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

    /** HMAC the complete receipt, domain-separated from the signed job envelope. */
    private static function receiptMac(array $record, string $secret): string
    {
        return hash_hmac('sha256',
            "adult-core-pro-receipt-v1\n".json_encode($record,JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $secret);
    }

    /** Return false for a duplicate accepted job. Stores NO privileged filesystem paths. */
    public function accept(array $envelope, string $secret): bool
    {
        if (!JobEnvelope::verify($envelope,$secret) || !self::validPayload($envelope['data'])) {
            throw new RuntimeException('Invalid or expired signed Pro job.');
        }
        // Participate in the SAME shared lifetime lock as dynamic HTTP.
        // The operator needs an exclusive lock to publish the source
        // maintenance marker. Hold this reader across *all* receipt writes,
        // including duplicate detection, to close the admission/check race.
        // This is a local advisory lock, NOT external worker quiescence.
        $receiptGuard = SourceHttpRequestLock::enter($this->root);
        if (!is_resource($receiptGuard)) {
            throw new RuntimeException('Pro job receipt lock requires private installed storage.');
        }
        try {
            return $this->acceptUnderSourceGuard($envelope, $secret);
        } finally {
            fclose($receiptGuard);
        }
    }

    private function acceptUnderSourceGuard(array $envelope, string $secret): bool
    {
        // No signed receipts during source/backup/recovery maintenance.
        // Operator marker publication cannot race this locked section.
        ProJobAdmission::assertMayAccept($this->root);
        $storage=$this->root.'/storage';
        $dir=$storage.'/pro-jobs';
        // Signed job receipts are private. An attacker-controlled or mistaken
        // symlink at the storage parent cannot redirect writes elsewhere.
        if (!is_dir($storage) || is_link($storage)
            || is_link($dir) || (!is_dir($dir) && !mkdir($dir,0700))
            || !is_writable($dir)) {
            throw new RuntimeException('Pro job storage unavailable.');
        }
        @chmod($dir,0700);
        $id=$envelope['data']['id'];
        $path=$dir.'/'.hash('sha256',$id).'.json';
        // Recheck after storage setup, as another operator may have entered
        // maintenance between initial validation and directory creation.
        ProJobAdmission::assertMayAccept($this->root);
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
            if (!is_array($prior) || array_keys($prior)!==['received_at','job','signature','status','mac']
                || !is_int($prior['received_at'] ?? null)
                || !in_array($prior['status'] ?? null, ['pending','processing','done','failed'], true)
                || !is_array($prior['job'] ?? null)
                || !is_string($prior['signature'] ?? null)
                || !is_string($prior['mac'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D',$prior['mac'])!==1
                || !hash_equals(self::receiptMac(array_slice($prior,0,4,true),$secret),$prior['mac'])
                || !hash_equals($envelope['signature'], $prior['signature'])
                || $prior['job'] !== $envelope['data']) {
                throw new RuntimeException('Conflicting or corrupted Pro job replay.');
            }
            return false;
        }
        try {
            // Preserve the signed original envelope for later integrity
            // verification. Receipt metadata is not an execution instruction.
            $record=[
                'received_at'=>time(),
                'job'=>$envelope['data'],
                'signature'=>$envelope['signature'],
                'status'=>'pending',
            ];
            $record['mac']=self::receiptMac($record,$secret);
            $data=json_encode($record,JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (fwrite($fp,$data)!==strlen($data) || !fflush($fp)) throw new RuntimeException('Cannot persist Pro job.');
        } catch (\Throwable $e) {
            fclose($fp);@unlink($path);throw $e;
        }
        fclose($fp);@chmod($path,0600);
        return true;
    }
}
