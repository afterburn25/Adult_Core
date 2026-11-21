<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Media\ImageMetadataPolicy;

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException('Private image metadata policy test: ' . $message);
    $checks++;
};
$path = tempnam(sys_get_temp_dir(), 'adult-image-meta-');
if ($path === false) throw new RuntimeException('Cannot create private image policy fixture.');
$accept = static function (string $bytes, string $mime) use ($path, $check): void {
    file_put_contents($path, $bytes);
    ImageMetadataPolicy::assertAllowed($path, $mime);
    $check(true, 'Clean image accepted: '.$mime);
};
$reject = static function (string $bytes, string $mime, string $reason) use ($path, $check): void {
    file_put_contents($path, $bytes);
    try {
        ImageMetadataPolicy::assertAllowed($path, $mime);
        $passed = false;
    } catch (RuntimeException) {
        $passed = true;
    }
    $check($passed, $reason);
};
$chunk = static function (string $type, string $data): string {
    return pack('N', strlen($data)) . $type . $data
        . hex2bin(hash('crc32b', $type . $data));
};
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGO4p2gOAAMXATf6OqxSAAAAAElFTkSuQmCC', true);
$webp = base64_decode('UklGRjoAAABXRUJQVlA4IC4AAACQAQCdASoBAAEAAUAmJaACdLoAA5gA/u/9w+tQjDmc3/37S/8Cd/8Cd/0MAAAA', true);
$jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDm6KKK8w/Uz//Z', true);
if (!is_string($png) || !is_string($webp) || !is_string($jpeg)) {
    throw new RuntimeException('Image policy test fixtures invalid.');
}
try {
    $accept($png, 'image/png');
    $accept($webp, 'image/webp');
    $accept($jpeg, 'image/jpeg');
    // PNG ancillary metadata is denied before private storage, not silently
    // kept in the uploaded binary. Also deny unknown chunks.
    $reject(substr($png, 0, 33) . $chunk('tEXt', 'GPSComment=private')
        . substr($png, 33), 'image/png', 'PNG text metadata denied');
    $reject(substr($png, 0, 33) . $chunk('eXIf', 'GPS')
        . substr($png, 33), 'image/png', 'PNG EXIF denied');
    $reject(substr($png, 0, 33) . $chunk('iTXt', 'creator')
        . substr($png, 33), 'image/png', 'PNG international text denied');
    $reject(substr($png, 0, 33) . $chunk('zzZZ', 'unknown')
        . substr($png, 33), 'image/png', 'Unknown PNG chunk denied');
    $reject(substr($png, 0, -5), 'image/png', 'Truncated PNG denied');
    $reject($png . "\x00" . 'owner-secret', 'image/png', 'PNG trailing data denied');

    $app1 = "\xff\xe1" . pack('n', 8) . "Exif\x00\x00";
    $reject(substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2),
        'image/jpeg', 'JPEG EXIF segment denied');
    $comment = "\xff\xfe" . pack('n', 10) . 'private!';
    $reject(substr($jpeg, 0, 2) . $comment . substr($jpeg, 2),
        'image/jpeg', 'JPEG comments denied');
    $reject($jpeg . 'private-trailer', 'image/jpeg', 'JPEG trailing bytes denied');
    $reject(substr($jpeg, 0, -2), 'image/jpeg', 'Truncated JPEG denied');

    $exif = 'EXIF' . pack('V', 6) . "Exif\x00\x00";
    $metadataWebp = substr($webp, 0, 4)
        . pack('V', strlen($webp) - 8 + strlen($exif))
        . substr($webp, 8) . $exif;
    $reject($metadataWebp, 'image/webp', 'WebP EXIF chunk denied');
    $reject(substr($webp, 0, -1), 'image/webp', 'Truncated WebP denied');
    $reject($png, 'application/octet-stream', 'Unsupported MIME denied');
    echo "Passed {$checks} private image metadata screening checks.\n";
} finally {
    @unlink($path);
}
