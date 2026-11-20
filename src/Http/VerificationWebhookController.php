<?php

declare(strict_types=1);

namespace AdultCore\Http;

use AdultCore\Database\Connection;
use AdultCore\Verification\SignedAssertion;
use AdultCore\Verification\VerificationRegistry;
use RuntimeException;
use Throwable;

/** Disabled unless an explicit, private, independently validated provider adapter is configured. */
final class VerificationWebhookController
{
    public function __construct(private readonly string $root) {}

    public function handle(string $method): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if ($method !== 'POST') { header('Allow: POST'); http_response_code(405); echo '{"error":"method"}'; return; }
        $secure = in_array(strtolower((string)($_SERVER['HTTPS'] ?? '')),['on','1'],true);
        $local = in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''),['127.0.0.1','::1'],true);
        if (!$secure && !$local) {http_response_code(403);echo '{"error":"secure transport required"}';return;}
        $configFile=$this->root.'/config/verification.php';
        if (!is_file($configFile) || is_link($configFile)) { http_response_code(404); echo '{"error":"not configured"}'; return; }
        try {
            $cfg=require $configFile;
            if (!is_array($cfg) || ($cfg['enabled'] ?? false) !== true
                || !is_string($cfg['provider'] ?? null) || !is_string($cfg['hmac_secret_hex'] ?? null)) {
                throw new RuntimeException('Verification integration unavailable.');
            }
            $contentType=strtolower(trim(explode(';',(string)($_SERVER['CONTENT_TYPE']??''))[0]));
            if ($contentType !== 'application/json' || (int)($_SERVER['CONTENT_LENGTH'] ?? 0)>8192) {
                http_response_code(415); echo '{"error":"unsupported payload"}'; return;
            }
            $body=file_get_contents('php://input',false,null,0,8193);
            if (!is_string($body) || strlen($body)>8192) throw new RuntimeException('Invalid payload.');
            $claim=SignedAssertion::verify($body,(string)($_SERVER['HTTP_X_AC_TIMESTAMP']??''),
                (string)($_SERVER['HTTP_X_AC_SIGNATURE']??''),$cfg['hmac_secret_hex']);
            $created=(new VerificationRegistry(Connection::open($this->root)))->apply($cfg['provider'],$claim);
            http_response_code($created?202:200);
            echo json_encode(['accepted'=>true,'duplicate'=>!$created],JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            // Do not reveal secret checks, database identity or internal errors.
            http_response_code(401);echo '{"error":"verification rejected"}';
        }
    }
}
