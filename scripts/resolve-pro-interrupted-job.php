<?php

declare(strict_types=1);

/** Console-only terminal failure resolution for a reconciled interrupted receipt. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__).'/src/Support/Autoload.php';
use AdultCore\Pro\ProJobLifecycle;

if(count($argv)!==5||$argv[4]!=='--work-stopped-and-reconciled'){
    fwrite(STDERR,"Usage: php scripts/resolve-pro-interrupted-job.php installed-root private-coordination-root job-id --work-stopped-and-reconciled < private-two-line-credentials\n");
    exit(64);
}
try{
    $raw=stream_get_contents(STDIN,8193);
    if(!is_string($raw)||strlen($raw)>8192||!str_contains($raw,"\n"))
        throw new RuntimeException('Private lease token and receipt signing secret required.');
    [$token,$secret]=explode("\n",$raw,2);
    $token=rtrim($token,"\r");
    $secret=rtrim($secret,"\r\n");
    if(preg_match('/^[a-f0-9]{64}$/D',$token)!==1||strlen($secret)<32||strlen($secret)>4096
        ||str_contains($secret,"\n")||str_contains($secret,"\r"))
        throw new RuntimeException('Private credentials invalid.');
    $report=(new ProJobLifecycle($argv[1]))->resolveInterrupted($argv[3],$secret,$argv[2],$token,true);
    echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
    fwrite(STDERR,"Receipt resolved as failed; no retry or external-worker/SQL shutdown verified. Source maintenance remains active.\n");
}catch(Throwable $error){
    // Do not disclose credentials, private paths, or arbitrary exception text.
    fwrite(STDERR,"Interrupted Pro job resolution refused. Verify owned source maintenance, private credentials, signed Pro identity and processing receipt.\n");
    exit(1);
}
