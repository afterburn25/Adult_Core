<?php

declare(strict_types=1);

namespace AdultCore\Pro;

use RuntimeException;

/**
 * Dispatch at most one authenticated local job to trusted application code.
 * Not a daemon, media authorization layer, FFmpeg processor or remote queue.
 */
final class ProQueueRunner
{
    public function __construct(private readonly string $root) {}

    /**
     * Returns idle, done or failed. Selection uses receipt time then job ID.
     * Another worker may win between selection and claim; the lifecycle
     * reauthenticates and refuses a stale/nonpending claim without execution.
     * An interrupted processing receipt blocks this automatic dispatch path.
     *
     * @param callable(array):bool $handler
     */
    public function runNext(string $secret,callable $handler): string
    {
        if(PHP_SAPI!=='cli'||strlen($secret)<32||strlen($secret)>4096)
            throw new RuntimeException('Invalid local Pro queue request.');
        // Retain a cooperating maintenance reader across selection and
        // execution; ProJobLifecycle separately owns its worker mutex.
        $guard=ProWorkerExecutionGuard::enter($this->root);
        try{
            $jobId=ProJobReceiptAudit::oldestPending($this->root,$secret);
            if($jobId===null)return 'idle';
            $success=(new ProJobLifecycle($this->root))->execute($jobId,$secret,$handler,true);
            return $success?'done':'failed';
        }finally{fclose($guard);}
    }
}
