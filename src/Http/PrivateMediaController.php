<?php

declare(strict_types=1);

namespace AdultCore\Http;

use AdultCore\Database\Connection;
use AdultCore\Member\MemberSession;
use AdultCore\Member\MemberAccounts;
use AdultCore\Member\PasswordRecovery;
use AdultCore\Membership\Entitlements;
use AdultCore\Media\PrivateMedia;
use Throwable;

/** Guarded binary delivery: user + age + entitlement + moderation + consent + private path. */
final class PrivateMediaController
{
    public function __construct(private readonly string $root) {}

    public function handle(string $path, string $method): void
    {
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        if ($method !== 'GET') { header('Allow: GET'); http_response_code(405); return; }
        if (preg_match('#^/media/([1-9][0-9]{0,17})$#D', $path, $m) !== 1) { http_response_code(404);return; }
        $https=in_array(strtolower((string)($_SERVER['HTTPS'] ?? '')),['on','1'],true);
        $loopback=in_array((string)($_SERVER['REMOTE_ADDR']??''),['127.0.0.1','::1'],true);
        if (!$https && !$loopback) {http_response_code(403);return;}
        try {
            MemberSession::start($this->root,$https);
            $id=MemberSession::id();
            if ($id===null) {http_response_code(403);return;}
            $pdo=Connection::open($this->root);
            $accounts=new MemberAccounts($pdo);
            $member=$accounts->byId($id);
            // A password change revokes all existing member sessions, including media URLs.
            if ($member===null || $member['status']!=='active'
                || MemberSession::generation() !== (new PasswordRecovery($pdo))->generation($id)) {
                MemberSession::revoke();
                http_response_code(403); return;
            }
            $mediaId=(int)$m[1];
            $q=$pdo->prepare('SELECT id,content_id,private_storage_key,mime_type,review_status FROM ac_media WHERE id=? LIMIT 1');
            $q->execute([$mediaId]); $media=$q->fetch(\PDO::FETCH_ASSOC);
            if (!is_array($media) || $media['review_status']!=='approved'
                || !PrivateMedia::validMime((string)$media['mime_type'])
                || !(new Entitlements($pdo))->canView($id,(int)$media['content_id'])) {
                http_response_code(404);return;
            }
            $file=(new PrivateMedia($this->root))->locate((string)$media['private_storage_key']);
            if ($file===null) {http_response_code(404);return;}
            // Response headers are fixed allowlisted MIME; never use untrusted filenames.
            header('Content-Type: '.$media['mime_type']);
            header('Content-Length: '.$file['bytes']);
            header('Content-Disposition: inline; filename="protected-image"');
            readfile($file['path']);
        } catch (Throwable) {http_response_code(503);}
    }
}
