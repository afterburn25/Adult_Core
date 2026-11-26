<?php

declare(strict_types=1);

namespace AdultCore\Pro;

use RuntimeException;

/**
 * Local execution boundary for a trusted, separately authorized handler.
 * This class is not a video processor and does not authorize asset access.
 * One local worker at a time; a crash leaves a signed processing receipt.
 */
final class ProJobLifecycle
{
    public function __construct(private readonly string $root) {}

    /**
     * Only trusted application code may provide a handler. It must verify
     * asset authorization and finish all work and cleanup before returning.
     * True means completed; false means a known, fully settled failure.
     * A thrown error or non-boolean result leaves processing for review.
     * Never automatically retry a processing, done or failed receipt.
     *
     * @param callable(array):bool $handler
     */
    public function execute(string $jobId, string $secret, callable $handler, bool $requireOldestPending=false): bool
    {
        if (PHP_SAPI !== 'cli' || preg_match('/^[a-zA-Z0-9-]{8,100}$/D',$jobId)!==1
            || strlen($secret)<32 || strlen($secret)>4096) {
            throw new RuntimeException('Invalid local Pro execution request.');
        }
        $guard=ProWorkerExecutionGuard::enter($this->root);
        $lock=null;
        try {
            $lock=$this->workerLock();
            ProJobAdmission::assertMayAccept($this->root);
            if($requireOldestPending&&ProJobReceiptAudit::oldestPending($this->root,$secret)!==$jobId)
                throw new RuntimeException('Pro queue selection changed before exclusive claim.');
            $dir=$this->root.'/storage/pro-jobs';
            $mode=@fileperms($dir);
            if(is_link($dir)||!is_dir($dir)||!is_int($mode)||($mode&0077)!==0)
                throw new RuntimeException('Private Pro inbox is unavailable.');
            $path=$dir.'/'.hash('sha256',$jobId).'.json';
            $record=$this->read($path,$jobId,$secret);
            if($record['status']!=='pending')
                throw new RuntimeException('Only a pending job can be executed.');
            $processing=$this->transition($path,$record,'processing',$secret);
            // The signed processing receipt is durable before invoking any
            // handler. Uncertain execution is deliberately not retryable.
            $result=$handler($processing['job']);
            if(!is_bool($result))
                throw new RuntimeException('Pro handler returned an uncertain outcome.');
            ProJobAdmission::assertMayAccept($this->root);
            $this->transition($path,$processing,$result?'done':'failed',$secret);
            return $result;
        } finally {
            if(is_resource($lock))fclose($lock);
            fclose($guard);
        }
    }

    /** @return resource Stable local mutex; never unlink or replace. */
    private function workerLock()
    {
        $storage=$this->root.'/storage';
        $mode=@fileperms($storage);
        if(is_link($storage)||!is_int($mode)||($mode&0077)!==0)
            throw new RuntimeException('Execution requires owner-only private storage.');
        $path=$storage.'/.pro-worker-execution.lock';
        if(is_link($path)||(file_exists($path)&&!is_file($path)))
            throw new RuntimeException('Unsafe Pro worker mutex.');
        $mask=umask(0077);
        try{$fp=@fopen($path,'c+b');}finally{umask($mask);}
        if(!is_resource($fp))throw new RuntimeException('Cannot open Pro worker mutex.');
        try{
            $opened=fstat($fp);
            if(!is_array($opened)||$opened['size']!==0||!$this->sameFile($path,$opened)
                ||!flock($fp,LOCK_EX|LOCK_NB)||!$this->sameFile($path,$opened))
                throw new RuntimeException('Pro worker is active or mutex is unsafe.');
            return $fp;
        }catch(\Throwable $error){fclose($fp);throw $error;}
    }

    private function sameFile(string $path,array $opened):bool
    {
        clearstatcache(true,$path);
        $named=@lstat($path);
        return is_array($named)&&($named['mode']&0170000)===0100000
            &&($opened['mode']&0170000)===0100000&&($named['mode']&0077)===0
            &&$named['ino']===$opened['ino']&&$named['dev']===$opened['dev'];
    }

    private function read(string $path,string $jobId,string $secret):array
    {
        if(is_link($path)||!is_file($path))throw new RuntimeException('Pro receipt unavailable.');
        $fp=@fopen($path,'rb');
        if(!is_resource($fp))throw new RuntimeException('Pro receipt cannot be opened.');
        try{
            $opened=fstat($fp);
            if(!is_array($opened)||!$this->sameFile($path,$opened)
                ||$opened['size']<2||$opened['size']>ProJobReceiptAudit::MAX_RECORD_BYTES)
                throw new RuntimeException('Unsafe Pro receipt.');
            $raw=stream_get_contents($fp,ProJobReceiptAudit::MAX_RECORD_BYTES+1);
            if(!is_string($raw)||strlen($raw)!==$opened['size']||!$this->sameFile($path,$opened))
                throw new RuntimeException('Pro receipt changed while reading.');
        }finally{fclose($fp);}
        try{$record=json_decode($raw,true,12,JSON_THROW_ON_ERROR);}
        catch(\JsonException){throw new RuntimeException('Invalid Pro receipt JSON.');}
        if(!is_array($record)||array_keys($record)!==['received_at','job','signature','status','mac']
            ||!is_int($record['received_at'])||$record['received_at']<=0||$record['received_at']>time()+300
            ||!is_array($record['job'])||($record['job']['id']??null)!==$jobId
            ||!is_string($record['signature'])||!is_string($record['mac'])
            ||!in_array($record['status'],['pending','processing','done','failed'],true)
            ||!JobInbox::validPayload($record['job'])
            ||!JobEnvelope::verify(['data'=>$record['job'],'signature'=>$record['signature']],$secret,$record['received_at'])
            ||!hash_equals($this->mac($record,$secret),$record['mac']))
            throw new RuntimeException('Pro receipt failed signed identity verification.');
        return $record;
    }

    private function mac(array $record,string $secret):string
    {
        return hash_hmac('sha256',"adult-core-pro-receipt-v1\n".
            json_encode(array_slice($record,0,4,true),JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$secret);
    }

    private function transition(string $path,array $prior,string $status,string $secret):array
    {
        // Temp is outside the audited inbox but inside private storage and
        // the same filesystem. A failed publication keeps the old receipt.
        $temp=dirname(dirname($path)).'/.pro-receipt-'.bin2hex(random_bytes(16));
        $next=$prior;
        $next['status']=$status;
        $next['mac']=$this->mac($next,$secret);
        $bytes=json_encode($next,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
        $mask=umask(0077);
        try{$fp=@fopen($temp,'x+b');}finally{umask($mask);}
        if(!is_resource($fp))throw new RuntimeException('Cannot stage signed Pro receipt.');
        $opened=fstat($fp);
        try{
            if(!is_array($opened)||!$this->sameFile($temp,$opened)
                ||fwrite($fp,$bytes)!==strlen($bytes)||!fflush($fp)
                ||(function_exists('fsync')&&!fsync($fp)))
                throw new RuntimeException('Cannot persist signed Pro receipt transition.');
            if($this->read($path,$prior['job']['id'],$secret)!==$prior
                ||!$this->sameFile($temp,$opened)||!rename($temp,$path))
                throw new RuntimeException('Pro receipt transition refused.');
            return $next;
        }finally{
            if(is_array($opened)&&$this->sameFile($temp,$opened))@unlink($temp);
            fclose($fp);
        }
    }
}
