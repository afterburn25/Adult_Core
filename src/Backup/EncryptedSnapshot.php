<?php

declare(strict_types=1);

namespace AdultCore\Backup;

use RuntimeException;

/** Small, authenticated JSON snapshot envelope. No plaintext is written to disk. */
final class EncryptedSnapshot
{
    private const AAD = 'Adult_Core/encrypted-backup/v1';
    private const MAX_BYTES = 16 * 1024 * 1024;

    public static function seal(string $plaintext, string $hexKey): string
    {
        $key = self::key($hexKey);
        if (strlen($plaintext) < 2 || strlen($plaintext) > self::MAX_BYTES) {
            throw new RuntimeException('Snapshot size is unsupported.');
        }
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, self::AAD, 16);
        if (!is_string($cipher) || strlen($tag) !== 16) {
            throw new RuntimeException('Could not encrypt private snapshot.');
        }
        return json_encode([
            'format'=>'adult-core-encrypted-snapshot', 'version'=>1,
            'nonce'=>base64_encode($nonce), 'tag'=>base64_encode($tag),
            'ciphertext'=>base64_encode($cipher),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public static function open(string $sealed, string $hexKey): string
    {
        $key=self::key($hexKey);
        if (strlen($sealed) > 23 * 1024 * 1024) throw new RuntimeException('Snapshot too large.');
        try { $document=json_decode($sealed, true, 6, JSON_THROW_ON_ERROR); }
        catch (\JsonException) {throw new RuntimeException('Invalid snapshot format.');}
        if (!is_array($document) || count($document)!==5 || ($document['format'] ?? null)!=='adult-core-encrypted-snapshot'
            || ($document['version']??null)!==1) throw new RuntimeException('Invalid snapshot format.');
        foreach (['nonce','tag','ciphertext'] as $name) if (!is_string($document[$name]??null)) throw new RuntimeException('Invalid snapshot format.');
        $nonce=base64_decode($document['nonce'], true);
        $tag=base64_decode($document['tag'], true);
        $cipher=base64_decode($document['ciphertext'], true);
        if (!is_string($nonce) || strlen($nonce)!==12 || !is_string($tag) || strlen($tag)!==16
            || !is_string($cipher) || strlen($cipher)>self::MAX_BYTES) throw new RuntimeException('Invalid snapshot format.');
        $raw=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,self::AAD);
        if (!is_string($raw)) throw new RuntimeException('Snapshot authentication failed.');
        return $raw;
    }

    private static function key(string $hexKey): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $hexKey)!==1) throw new RuntimeException('Use a separate 256-bit backup key.');
        return hex2bin($hexKey);
    }
}
