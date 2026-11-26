<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/Support/Autoload.php';
use AdultCore\Installer\InstallationState;
use AdultCore\Pro\PrivateVideoThumbnail;
use AdultCore\Pro\JobInbox;
use AdultCore\Pro\JobEnvelope;
use AdultCore\Pro\ProQueueRunner;
use AdultCore\Pro\ProJobReceiptAudit;
use AdultCore\Release\BackupMaintenanceAdmission;

$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{
    if(!$ok)throw new RuntimeException($label);
    ++$checks;
};
$reject=static function(callable $fn,string $label)use($check):void{
    try{$fn();}catch(RuntimeException){$check(true,$label);return;}
    $check(false,$label);
};
$remove=static function(string $path)use(&$remove):void{
    if(is_link($path)||is_file($path)){unlink($path);return;}
    if(!is_dir($path))return;
    foreach(new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS) as $e)$remove($e->getPathname());
    rmdir($path);
};
$root=sys_get_temp_dir().'/ac-private-video-'.bin2hex(random_bytes(8));
mkdir($root,0700);
try{
    mkdir($root.'/storage',0700);mkdir($root.'/config',0700);
    $dir=$root.'/storage/pro-video-work';mkdir($dir,0700);
    $key=bin2hex(random_bytes(32));$secret=str_repeat('v',40);
    file_put_contents($root.'/config/app-key.php','<?php return '.var_export($key,true).';');
    file_put_contents($root.'/config/database.php',"<?php return ['driver'=>'mysql','database'=>'fixture','username'=>'fixture'];");
    $state=$root.'/storage/install-state.json';
    $input=str_repeat('a',40);$output=str_repeat('b',40);$other=str_repeat('c',40);
    $source=$dir.'/'.$input.'.mp4';
    // Actual generated color bars, no user data, no external network or media.
    $mask=umask(0077);
    try{
        $proc=proc_open(['/usr/bin/ffmpeg','-hide_banner','-loglevel','error','-nostdin','-f','lavfi',
            '-i','testsrc2=size=320x240:rate=5','-t','1','-c:v','mpeg4','-threads','1',$source],
            [0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']],$pipes);
        if(!is_resource($proc)||proc_close($proc)!==0)throw new RuntimeException('Cannot generate real synthetic MP4 fixture.');
    }finally{umask($mask);}
    $before=hash_file('sha256',$source);
    $renderer=new PrivateVideoThumbnail($root);
    file_put_contents($state,InstallationState::encode('lite',$key));
    $reject(static fn()=>$renderer->render($input,$output),'Lite thumbnail refused');
    file_put_contents($state,InstallationState::encode('pro',$key));
    $reject(static fn()=>$renderer->render('../outside',$output),'Input traversal refused');
    $reject(static fn()=>$renderer->render($input,'../outside'),'Output traversal refused');
    chmod($source,0644);
    $reject(static fn()=>$renderer->render($input,$output),'Insecure input refused');
    chmod($source,0600);
    $guard=BackupMaintenanceAdmission::begin($root);
    try{$reject(static fn()=>$renderer->render($input,$output),'Active maintenance refuses processing');}
    finally{BackupMaintenanceAdmission::finish($root,$guard['finish_token']);}
    $check(!file_exists($dir.'/'.$output.'.jpg'),'Refused processing leaves no output');
    // A trusted fixture handler authorizes exactly one numeric asset and
    // maps it to the private opaque input/output. No real asset resolver.
    $inbox=new JobInbox($root);
    $inbox->accept(JobEnvelope::create('thumbnail-test-01','video.thumbnail',['asset_id'=>42],$secret),$secret);
    $runner=new ProQueueRunner($root);$result=null;
    $check($runner->runNext($secret,static function(array $job)use($renderer,$input,$output,&$result):bool{
        if($job['type']!=='video.thumbnail'||$job['payload']!==['asset_id'=>42])return false;
        $result=$renderer->render($input,$output);
        return true;
    })==='done','Actual FFmpeg thumbnail job reaches signed done');
    $dest=$dir.'/'.$output.'.jpg';
    $check(is_array($result)&&$result['width']===640&&$result['height']===480
        &&$result['bytes']===filesize($dest)&&$result['bytes']<=2097152
        &&(fileperms($dest)&0077)===0,'Private validated JPEG output with bounded dimensions');
    $check(ProJobReceiptAudit::inspect($root,$secret)['done_receipts']===1
        &&hash_file('sha256',$source)===$before,'Receipt authenticated and original video unchanged');
    $digest=hash_file('sha256',$dest);
    $reject(static fn()=>$renderer->render($input,$output),'Existing output cannot be overwritten');
    $check(hash_file('sha256',$dest)===$digest,'Existing output retained');
    $bad=$dir.'/'.$other.'.mp4';
    file_put_contents($bad,'not a video');chmod($bad,0600);
    $reject(static fn()=>$renderer->render($other,$other),'Nonvideo input refused');
    unlink($bad);
    if(!symlink($source,$bad))throw new RuntimeException('Cannot prepare symlink fixture.');
    $reject(static fn()=>$renderer->render($other,$other),'Symlinked private input refused');
    $check(!file_exists($dir.'/'.$other.'.jpg')&&glob($dir.'/.thumbnail-*')===[],
        'Failure leaves no thumbnail output or staging files');
    echo "Passed {$checks} real private thumbnail checks.\n";
}finally{$remove($root);}
