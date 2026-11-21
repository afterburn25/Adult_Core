<?php

declare(strict_types=1);

namespace AdultCore\Backup;

use RuntimeException;

/** Versioned, streaming AES-256-GCM records. The final authenticated record detects truncation. */
final class StreamArchive
{
    private const LABEL = 'Adult_Core/portable-recovery/v1';
    private const MAX_LINE = 3 * 1024 * 1024;
    private const MAX_RECORD = 1536 * 1024;

    /** @param callable(callable(array):void):void $produce */
    public static function write(string $destination, string $hexKey, callable $produce): int
    {
        $secret = self::key($hexKey);
        $fp = @fopen($destination, 'x+b');
        if (!is_resource($fp)) throw new RuntimeException('Destination must be a new, writable private file.');
        @chmod($destination, 0600);
        $success = false;
        try {
            $header = json_encode(['format'=>'adult-core-portable-recovery','version'=>1,
                'salt'=>base64_encode(random_bytes(16)), 'nonce_prefix'=>base64_encode(random_bytes(4))], JSON_THROW_ON_ERROR);
            $data=json_decode($header,true,8,JSON_THROW_ON_ERROR);
            $key=hash_hkdf('sha256',$secret,32,self::LABEL,base64_decode($data['salt'],true));
            $prefix=base64_decode($data['nonce_prefix'],true);
            self::writeAll($fp,$header."\n");
            $seq=0; $finished=false;
            $emit = static function (array $record) use ($fp,$header,$key,$prefix,&$seq,&$finished): void {
                if ($finished || $seq>=10000000 || !is_string($record['type']??null)) throw new RuntimeException('Invalid archive record sequence.');
                $raw=json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
                if(strlen($raw)>self::MAX_RECORD)throw new RuntimeException('Archive record exceeds the safe per-record limit.');
                $seq++;
                $nonce=$prefix.pack('N2',intdiv($seq,4294967296),$seq%4294967296);
                $tag='';
                $cipher=openssl_encrypt($raw,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,self::LABEL."\0".$header."\0".$seq,16);
                if(!is_string($cipher)||strlen($tag)!==16)throw new RuntimeException('Encryption failed.');
                $line=json_encode(['seq'=>$seq,'ciphertext'=>base64_encode($cipher),'tag'=>base64_encode($tag)],JSON_THROW_ON_ERROR);
                self::writeAll($fp,$line."\n");
                if($record['type']==='end')$finished=true;
            };
            $produce($emit);
            if(!$finished||!fflush($fp))throw new RuntimeException('Archive did not finish safely.');
            $success=true;
            return $seq;
        } finally {
            fclose($fp);
            if(!$success) @unlink($destination);
        }
    }

    /** @param callable(array):void $consume */
    public static function read(string $source, string $hexKey, callable $consume): int
    {
        $secret=self::key($hexKey);
        if(is_link($source)||!is_file($source))throw new RuntimeException('Archive must be a regular private file.');
        $fp=@fopen($source,'rb');
        if(!is_resource($fp))throw new RuntimeException('Cannot open private archive.');
        try {
            $header=fgets($fp,4096);
            if(!is_string($header)||!str_ends_with($header,"\n"))throw new RuntimeException('Invalid archive header.');
            $header=rtrim($header,"\r\n");
            $data=json_decode($header,true,8,JSON_THROW_ON_ERROR);
            if(!is_array($data)||count($data)!==4||($data['format']??null)!=='adult-core-portable-recovery'||($data['version']??null)!==1
                ||!is_string($data['salt']??null)||!is_string($data['nonce_prefix']??null))throw new RuntimeException('Invalid archive header.');
            $salt=base64_decode($data['salt'],true);$prefix=base64_decode($data['nonce_prefix'],true);
            if(!is_string($salt)||strlen($salt)!==16||!is_string($prefix)||strlen($prefix)!==4)throw new RuntimeException('Invalid archive header.');
            $key=hash_hkdf('sha256',$secret,32,self::LABEL,$salt);$seq=0;$done=false;
            while(($line=fgets($fp,self::MAX_LINE+1))!==false){
                if(strlen($line)>self::MAX_LINE||!str_ends_with($line,"\n")||$done)throw new RuntimeException('Invalid archive record/trailing bytes.');
                $row=json_decode($line,true,8,JSON_THROW_ON_ERROR);
                if(!is_array($row)||count($row)!==3||!is_int($row['seq']??null)||$row['seq']!==$seq+1
                    ||!is_string($row['ciphertext']??null)||!is_string($row['tag']??null)||++$seq>10000000){
                    throw new RuntimeException('Invalid archive record sequence.');
                }
                $cipher=base64_decode($row['ciphertext'],true);$tag=base64_decode($row['tag'],true);
                if(!is_string($cipher)||strlen($cipher)>self::MAX_RECORD+256||!is_string($tag)||strlen($tag)!==16)throw new RuntimeException('Invalid encrypted archive record.');
                $nonce=$prefix.pack('N2',intdiv($seq,4294967296),$seq%4294967296);
                $raw=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,self::LABEL."\0".$header."\0".$seq);
                if(!is_string($raw))throw new RuntimeException('Archive authentication failed.');
                $record=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
                if(!is_array($record)||!is_string($record['type']??null))throw new RuntimeException('Invalid archive contents.');
                $consume($record);
                if($record['type']==='end')$done=true;
            }
            if(!feof($fp)||!$done)throw new RuntimeException('Archive is truncated or cannot be read.');
            return $seq;
        }catch(\JsonException $e){throw new RuntimeException('Invalid archive JSON.',0,$e);}finally{fclose($fp);}
    }

    private static function key(string $hexKey): string
    {
        if(preg_match('/^[a-f0-9]{64}$/D',$hexKey)!==1)throw new RuntimeException('Use a separate offline 256-bit backup key.');
        return hex2bin($hexKey);
    }

    private static function writeAll($fp,string $bytes):void
    {
        $offset=0;$length=strlen($bytes);
        while($offset<$length){$n=fwrite($fp,substr($bytes,$offset));if(!is_int($n)||$n===0)throw new RuntimeException('Archive write failed.');$offset+=$n;}
    }
}
