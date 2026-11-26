<?php

declare(strict_types=1);

namespace AdultCore\Pro;

use RuntimeException;

/**
 * Bounded local FFmpeg thumbnail primitive. A trusted caller must independently
 * authorize the input and retain the lifecycle guard through all side effects.
 * No uploads, media registration, publication, HTTP delivery or entitlement.
 */
final class PrivateVideoThumbnail
{
    private const MAX_INPUT=268435456;
    private const MAX_OUTPUT=2097152;
    private const TIMEOUT=30;

    public function __construct(private readonly string $root) {}

    /** Process an opaque, private, already-authorized MP4 into a new JPEG. */
    public function render(string $inputKey,string $outputKey): array
    {
        if(PHP_SAPI!=='cli'||preg_match('/^[a-f0-9]{40}$/D',$inputKey)!==1
            ||preg_match('/^[a-f0-9]{40}$/D',$outputKey)!==1)
            throw new RuntimeException('Invalid private thumbnail request.');
        $guard=ProWorkerExecutionGuard::enter($this->root);
        try{return $this->renderGuarded($inputKey,$outputKey);}
        finally{fclose($guard);}
    }

    private function renderGuarded(string $inputKey,string $outputKey):array
    {
        $root=realpath($this->root);
        if($root===false)throw new RuntimeException('Private video root is unavailable.');
        $storage=$root.'/storage';
        $dir=$storage.'/pro-video-work';
        foreach([$storage,$dir] as $directory){
            clearstatcache(true,$directory);
            $mode=@fileperms($directory);
            if(is_link($directory)||!is_dir($directory)||!is_int($mode)||($mode&0077)!==0)
                throw new RuntimeException('Video workspace must be owner-only and outside the public root.');
        }
        $input=$dir.'/'.$inputKey.'.mp4';
        $output=$dir.'/'.$outputKey.'.jpg';
        $source=$this->privateStat($input);
        if($source['size']<1||$source['size']>self::MAX_INPUT
            ||(new \finfo(FILEINFO_MIME_TYPE))->file($input)!=='video/mp4')
            throw new RuntimeException('Private MP4 input is invalid or exceeds the tested limit.');
        if(file_exists($output)||is_link($output))throw new RuntimeException('Thumbnail already exists.');
        $binary='/usr/bin/ffmpeg';
        $binaryMode=@fileperms($binary);
        if(!is_file($binary)||!is_executable($binary)||!is_int($binaryMode)||($binaryMode&0022)!==0
            ||!function_exists('proc_open'))
            throw new RuntimeException('Trusted system FFmpeg is unavailable.');
        $temp=$dir.'/.thumbnail-'.bin2hex(random_bytes(16)).'.jpg';
        $mask=umask(0077);
        try{$fp=@fopen($temp,'x+b');}finally{umask($mask);}
        if(!is_resource($fp))throw new RuntimeException('Cannot reserve private thumbnail output.');
        $opened=fstat($fp);
        try{
            if(!is_array($opened)||!$this->sameIdentity($temp,$opened))
                throw new RuntimeException('Unsafe thumbnail staging file.');
            // No shell, user-supplied options, network protocols, playlists or
            // external track references. The format, filters and paths are fixed.
            $args=[$binary,'-hide_banner','-nostdin','-loglevel','error','-y',
                '-max_alloc','67108864','-protocol_whitelist','file','-f','mov',
                '-enable_drefs','0','-use_absolute_path','0','-threads','1','-i',$input,
                '-map','0:v:0','-frames:v','1','-an','-sn','-dn','-filter_threads','1',
                '-vf','scale=640:640:force_original_aspect_ratio=decrease:force_divisible_by=2',
                '-c:v','mjpeg','-threads','1','-q:v','4','-f','image2','-update','1',
                '-fs',(string)self::MAX_OUTPUT,$temp];
            $this->run($args);
            if(!$this->sameIdentity($temp,$opened)||!$this->sameIdentity($input,$source))
                throw new RuntimeException('Private video input or output changed during processing.');
            $after=$this->privateStat($input);
            foreach(['size','mtime','ctime'] as $field)
                if($after[$field]!==$source[$field])throw new RuntimeException('Private video input changed.');
            $result=$this->privateStat($temp);
            $dimensions=@getimagesize($temp);
            if($result['size']<1||$result['size']>self::MAX_OUTPUT
                ||!is_array($dimensions)||($dimensions[2]??null)!==IMAGETYPE_JPEG
                ||$dimensions[0]<1||$dimensions[1]<1||$dimensions[0]>640||$dimensions[1]>640
                ||(new \finfo(FILEINFO_MIME_TYPE))->file($temp)!=='image/jpeg'
                ||!fflush($fp)||(function_exists('fsync')&&!fsync($fp)))
                throw new RuntimeException('Generated private thumbnail failed validation.');
            ProJobAdmission::assertMayAccept($root);
            // link() atomically refuses an existing target; never overwrite it.
            if(!$this->sameIdentity($temp,$opened)||!@link($temp,$output))
                throw new RuntimeException('Cannot publish new private thumbnail without overwrite.');
            return ['bytes'=>$result['size'],'width'=>$dimensions[0],'height'=>$dimensions[1]];
        }finally{
            if(is_array($opened)&&$this->sameIdentity($temp,$opened))@unlink($temp);
            fclose($fp);
        }
    }

    private function privateStat(string $path):array
    {
        clearstatcache(true,$path);
        $stat=@lstat($path);
        if(!is_array($stat)||($stat['mode']&0170000)!==0100000||($stat['mode']&0077)!==0)
            throw new RuntimeException('Unsafe private video file.');
        return $stat;
    }

    private function sameIdentity(string $path,array $opened):bool
    {
        clearstatcache(true,$path);
        $stat=@lstat($path);
        return is_array($stat)&&($stat['mode']&0170000)===0100000
            &&($stat['mode']&0077)===0&&$stat['ino']===$opened['ino']&&$stat['dev']===$opened['dev'];
    }

    private function run(array $args):void
    {
        $proc=@proc_open($args,[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],
            2=>['file','/dev/null','w']],$pipes,null,['PATH'=>'/usr/bin:/bin','LANG'=>'C']);
        if(!is_resource($proc))throw new RuntimeException('Cannot start local FFmpeg.');
        $deadline=hrtime(true)+self::TIMEOUT*1000000000;
        $status=null;
        try{
            do{
                $status=proc_get_status($proc);
                if(!$status['running'])break;
                if(hrtime(true)>=$deadline)throw new RuntimeException('Private thumbnail exceeded its time limit.');
                usleep(20000);
            }while(true);
            if($status['exitcode']!==0)throw new RuntimeException('FFmpeg rejected private input.');
        }finally{
            if($status===null||$status['running']){
                proc_terminate($proc,9);
            }
            // Reap the direct child before returning to the lifecycle. This
            // is not an OS sandbox, and does not certify descendant shutdown.
            proc_close($proc);
        }
    }
}
