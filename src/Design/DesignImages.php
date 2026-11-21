<?php

declare(strict_types=1);

namespace AdultCore\Design;

use AdultCore\Media\ImageMetadataPolicy;
use PDO;
use RuntimeException;

/** Only public-safe decorative photographs. Explicit adult content belongs in the protected media workflow. */
final class DesignImages
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(private readonly string $root, private readonly PDO $pdo) {}

    /** @return array{mime:string,extension:string,size:int} */
    public static function inspect(string $path): array
    {
        if (!is_file($path) || is_link($path)) throw new RuntimeException('Invalid image upload.');
        $bytes = filesize($path);
        if ($bytes === false || $bytes < 32 || $bytes > self::MAX_BYTES) throw new RuntimeException('Image must be below 5 MB.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $types = ['image/png'=>'png', 'image/webp'=>'webp', 'image/jpeg'=>'jpg'];
        if (!is_string($mime) || !isset($types[$mime])) throw new RuntimeException('Only PNG, WebP and JPEG images are supported.');
        $dimensions = @getimagesize($path);
        if (!is_array($dimensions) || ($dimensions['mime'] ?? '') !== $mime
            || ($dimensions[0] ?? 0) < 1 || ($dimensions[1] ?? 0) < 1
            || $dimensions[0] > 4096 || $dimensions[1] > 4096) {
            throw new RuntimeException('Invalid image or oversized dimensions (maximum 4096px).');
        }
        // Decorative images may later be published to the homepage. Refuse
        // EXIF/GPS, XMP and unsupported chunks BEFORE any private/public flag.
        ImageMetadataPolicy::assertAllowed($path, $mime);
        return ['mime'=>$mime,'extension'=>$types[$mime], 'size'=>(int)$bytes];
    }

    /** Validates upload with PHP's native provenance checks before writing outside public/. */
    public function upload(array $upload, bool $publicUseConfirmed): int
    {
        if (($upload['error'] ?? null) !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null)
            || !is_uploaded_file($upload['tmp_name'])) throw new RuntimeException('Select a valid image to upload.');
        $info = self::inspect($upload['tmp_name']);
        $filename = bin2hex(random_bytes(20)) . '.' . $info['extension'];
        $dir = $this->root . '/storage/design-assets';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('Private image storage unavailable.');
        if (is_link($dir) || !is_dir($dir)) throw new RuntimeException('Unsafe image storage path.');
        $destination = $dir . '/' . $filename;
        if (!move_uploaded_file($upload['tmp_name'], $destination)) throw new RuntimeException('Unable to save image privately.');
        chmod($destination, 0600);
        try {
            $s = $this->pdo->prepare('INSERT INTO ac_design_assets (filename,mime,byte_size,public_ok,created_at) VALUES (?,?,?,?,UTC_TIMESTAMP())');
            $s->execute([$filename,$info['mime'],$info['size'],$publicUseConfirmed ? 1 : 0]);
            return (int)$this->pdo->lastInsertId();
        } catch (\Throwable $e) {
            @unlink($destination);
            throw $e;
        }
    }

    /** Files served from private disk must match DB metadata, without filesystem traversal. */
    public function serve(array $asset): void
    {
        $filename = (string) ($asset['filename'] ?? '');
        $mime = (string) ($asset['mime'] ?? '');
        if (!preg_match('/^[a-f0-9]{40}\.(png|webp|jpg)$/D', $filename)
            || !in_array($mime, ['image/png','image/webp','image/jpeg'], true)) {
            http_response_code(404); return;
        }
        $path = $this->root . '/storage/design-assets/' . $filename;
        if (!is_file($path) || is_link($path) || filesize($path) !== (int)($asset['byte_size'] ?? 0)) {
            http_response_code(404); return;
        }
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: inline; filename="design-image.' . pathinfo($filename, PATHINFO_EXTENSION) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path);
    }
}
