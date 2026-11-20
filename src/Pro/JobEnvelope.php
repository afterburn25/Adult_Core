<?php

declare(strict_types=1);

namespace AdultCore\Pro;

use RuntimeException;

/** Message contract only: transport, worker isolation, streaming not yet implemented. */
final class JobEnvelope
{
    public static function create(string $jobId, string $type, array $payload, string $secret): array
    {
        if (strlen($secret)<32 || !preg_match('/^[a-zA-Z0-9-]{8,100}$/D', $jobId)
            || !in_array($type, ['video.transcode', 'video.thumbnail'], true)) {
            throw new RuntimeException('Unsupported job envelope.');
        }
        $data=['version'=>1,'id'=>$jobId,'type'=>$type,'payload'=>$payload,'created_at'=>time()];
        $raw=json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($raw)>16384) throw new RuntimeException('Job too large.');
        return ['data'=>$data,'signature'=>hash_hmac('sha256',$raw,$secret)];
    }

    public static function verify(array $envelope, string $secret, ?int $now=null): bool
    {
        $data=$envelope['data']??null;$signature=$envelope['signature']??null;
        if (!is_array($data) || !is_string($signature) || strlen($secret)<32) return false;
        if (array_keys($data)!==['version','id','type','payload','created_at'] || $data['version']!==1
            || !is_int($data['created_at']) || !is_array($data['payload'])
            || !is_string($data['id']) || !preg_match('/^[a-zA-Z0-9-]{8,100}$/D',$data['id'])
            || !in_array($data['type'],['video.transcode','video.thumbnail'],true)) return false;
        $now ??= time();
        if (abs($now-$data['created_at'])>300) return false;
        $raw=json_encode($data,JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return strlen($raw)<=16384 && preg_match('/^[a-f0-9]{64}$/D',$signature)===1
            && hash_equals(hash_hmac('sha256',$raw,$secret),$signature);
    }
}
