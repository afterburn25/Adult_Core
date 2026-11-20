<?php

declare(strict_types=1);

namespace AdultCore\Release;

use RuntimeException;

/** Offline detached release manifest verifier. No remote fetching or automatic writes. */
final class SignedManifest
{
    /** @return array<string,mixed> */
    public static function verify(string $json, string $signatureBase64, string $publicKeyPem): array
    {
        if (strlen($json)>262144 || strlen($signatureBase64)>8192 || strlen($publicKeyPem)>8192) {
            throw new RuntimeException('Invalid release manifest.');
        }
        $signature=base64_decode($signatureBase64,true);
        $key=openssl_pkey_get_public($publicKeyPem);
        if (!is_string($signature) || $key===false || openssl_verify($json,$signature,$key,OPENSSL_ALGO_SHA256)!==1) {
            throw new RuntimeException('Untrusted release manifest.');
        }
        try { $manifest=json_decode($json,true,12,JSON_THROW_ON_ERROR); }
        catch (\JsonException) {throw new RuntimeException('Invalid release manifest.');}
        if (!is_array($manifest) || ($manifest['format']??null)!=='adult-core-release-v1'
            || !is_string($manifest['version']??null) || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[a-z0-9.-]+)?$/D',$manifest['version'])
            || !is_array($manifest['files']??null) || count($manifest['files'])<1 || count($manifest['files'])>2000) {
            throw new RuntimeException('Invalid release manifest.');
        }
        $seen=[];
        foreach ($manifest['files'] as $record) {
            if (!is_array($record) || count($record)!==2
                || !is_string($record['path']??null) || !preg_match('#^[A-Za-z0-9_./-]{1,200}$#D',$record['path'])
                || str_contains($record['path'],'..') || str_starts_with($record['path'],'/')
                || !is_string($record['sha256']??null) || !preg_match('/^[a-f0-9]{64}$/D',$record['sha256'])
                || isset($seen[$record['path']])) {
                throw new RuntimeException('Invalid release manifest file entry.');
            }
            $seen[$record['path']]=true;
        }
        return $manifest;
    }
}
