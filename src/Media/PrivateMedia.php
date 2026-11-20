<?php

declare(strict_types=1);

namespace AdultCore\Media;

use RuntimeException;

/** Private local image storage. Not a CDN and never exposes full filesystem paths. */
final class PrivateMedia
{
    public function __construct(private readonly string $root) {}

    public static function validKey(string $key): bool
    {
        return preg_match('/^[a-f0-9]{40}$/D', $key) === 1;
    }

    public static function validMime(string $mime): bool
    {
        return in_array($mime, ['image/jpeg','image/png','image/webp'], true);
    }

    /** @return array{path:string,bytes:int}|null */
    public function locate(string $key): ?array
    {
        if (!self::validKey($key)) return null;
        $base = $this->root . '/storage/private-media';
        if (!is_dir($base) || is_link($base)) return null;
        $filename = $base . '/' . $key . '.bin';
        if (!is_file($filename) || is_link($filename)) return null;
        $bytes = filesize($filename);
        if (!is_int($bytes) || $bytes < 1 || $bytes > 8 * 1024 * 1024) return null;
        return ['path'=>$filename,'bytes'=>$bytes];
    }

    /** @return string 40-character opaque storage key */
    public function storeVerifiedImage(string $temporaryPath, bool $verifiedUploadedFile): string
    {
        if (!$verifiedUploadedFile || !is_uploaded_file($temporaryPath) || is_link($temporaryPath)
            || !is_file($temporaryPath) || filesize($temporaryPath) > 8*1024*1024) {
            throw new RuntimeException('Invalid or oversized image upload.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
        if (!is_string($mime) || !self::validMime($mime) || @getimagesize($temporaryPath) === false) {
            throw new RuntimeException('Image type not allowed.');
        }
        $base = $this->root . '/storage/private-media';
        if (is_link($base) || (!is_dir($base) && !mkdir($base, 0700, true))) {
            throw new RuntimeException('Private storage unavailable.');
        }
        @chmod($base,0700);
        for ($i=0;$i<3;$i++) {
            $key=bin2hex(random_bytes(20));
            $dest=$base.'/'.$key.'.bin';
            if (file_exists($dest) || is_link($dest)) continue;
            if (!move_uploaded_file($temporaryPath,$dest)) throw new RuntimeException('Could not save private image.');
            @chmod($dest,0600);
            return $key;
        }
        throw new RuntimeException('Private media key generation failed.');
    }
}
