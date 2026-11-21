<?php

declare(strict_types=1);

namespace AdultCore\Media;

use RuntimeException;

/**
 * Conservative metadata/privacy screening of uploaded images.
 *
 * No binary is rewritten and this is NOT malware scanning or a substitute for
 * a reviewed media-processing pipeline. Unknown/metadata-bearing structures
 * are rejected before private storage, instead of preserving EXIF/GPS/XMP.
 * The upload still needs server-side MIME and dimension validation.
 */
final class ImageMetadataPolicy
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    public static function assertAllowed(string $path, string $mime): void
    {
        clearstatcache(true, $path);
        $bytes = @filesize($path);
        if (!is_file($path) || is_link($path) || !is_int($bytes)
            || $bytes < 1 || $bytes > self::MAX_BYTES) {
            throw new RuntimeException('Private image could not be safely inspected.');
        }
        $data = @file_get_contents($path);
        if (!is_string($data) || strlen($data) !== $bytes) {
            throw new RuntimeException('Private image could not be safely inspected.');
        }
        $accepted = match ($mime) {
            'image/png' => self::png($data),
            'image/jpeg' => self::jpeg($data),
            'image/webp' => self::webp($data),
            default => false,
        };
        if (!$accepted) {
            throw new RuntimeException('Image contains metadata or an unsupported image structure.');
        }
    }

    private static function png(string $data): bool
    {
        if (!str_starts_with($data, "\x89PNG\r\n\x1a\n")) return false;
        $len = strlen($data);
        $offset = 8;
        $hasHeader = false;
        $hasImage = false;
        while ($offset + 12 <= $len) {
            $size = unpack('N', substr($data, $offset, 4))[1];
            if (!is_int($size) || $size > $len - $offset - 12) return false;
            $chunk = substr($data, $offset + 4, 4);
            if (!preg_match('/^[A-Za-z]{4}$/D', $chunk)) return false;
            // Text, EXIF, embedded profiles, animation and unknown chunks are
            // denied. Safe standard color/density fields are not private text.
            if (!in_array($chunk, [
                'IHDR','PLTE','IDAT','IEND','gAMA','cHRM','sRGB',
                'pHYs','tRNS','sBIT','bKGD',
            ], true)) return false;
            if (!$hasHeader && ($chunk !== 'IHDR' || $size !== 13)) return false;
            if ($chunk === 'IHDR') {
                if ($hasHeader || $size !== 13) return false;
                $hasHeader = true;
            }
            if ($chunk === 'IDAT') $hasImage = true;
            $offset += 12 + $size;
            if ($chunk === 'IEND') {
                return $hasHeader && $hasImage && $size === 0 && $offset === $len;
            }
        }
        return false;
    }

    private static function webp(string $data): bool
    {
        $len = strlen($data);
        if ($len < 20 || substr($data, 0, 4) !== 'RIFF'
            || substr($data, 8, 4) !== 'WEBP') return false;
        $riffLength = unpack('V', substr($data, 4, 4))[1];
        if (!is_int($riffLength) || $riffLength !== $len - 8) return false;
        $offset = 12;
        $hasImage = false;
        while ($offset + 8 <= $len) {
            $chunk = substr($data, $offset, 4);
            $size = unpack('V', substr($data, $offset + 4, 4))[1];
            $padded = $size + ($size % 2);
            if (!is_int($size) || $padded > $len - $offset - 8) return false;
            if (!in_array($chunk, ['VP8 ', 'VP8L', 'VP8X', 'ALPH'], true)) return false;
            if ($chunk === 'VP8X') {
                if ($size !== 10) return false;
                $flags = ord($data[$offset + 8]);
                // ICC, EXIF, XMP and animation bits require reviewed processing.
                if (($flags & 0x2e) !== 0) return false;
            }
            if ($chunk === 'VP8 ' || $chunk === 'VP8L') $hasImage = true;
            $offset += 8 + $padded;
        }
        return $hasImage && $offset === $len;
    }

    private static function jpeg(string $data): bool
    {
        $len = strlen($data);
        if ($len < 6 || substr($data, 0, 2) !== "\xff\xd8") return false;
        $offset = 2;
        $hasScan = false;
        while ($offset < $len) {
            if (ord($data[$offset]) !== 0xff) return false;
            while ($offset < $len && ord($data[$offset]) === 0xff) $offset++;
            if ($offset >= $len) return false;
            $marker = ord($data[$offset++]);
            if ($marker === 0xd9) return $hasScan && $offset === $len;
            if ($marker === 0xd8 || $marker === 0x00 || $marker === 0x01
                || ($marker >= 0xd0 && $marker <= 0xd7)) return false;
            // APP1/EXIF, APP2/ICC, APP13/IPTC, APP14 etc and comments all
            // remain untrusted; APP0/JFIF contains no personal image notes.
            if (($marker >= 0xe1 && $marker <= 0xef) || $marker === 0xfe) return false;
            if ($offset + 2 > $len) return false;
            $size = unpack('n', substr($data, $offset, 2))[1];
            if (!is_int($size) || $size < 2 || $size > $len - $offset) return false;
            $offset += $size;
            if ($marker !== 0xda) continue;
            $hasScan = true;
            // Traverse entropy data until a genuine marker, skipping byte
            // stuffing and restart markers. This also catches late APP data.
            while ($offset < $len) {
                if (ord($data[$offset]) !== 0xff) { $offset++; continue; }
                $start = $offset;
                while ($offset < $len && ord($data[$offset]) === 0xff) $offset++;
                if ($offset >= $len) return false;
                $next = ord($data[$offset]);
                if ($next === 0x00 || ($next >= 0xd0 && $next <= 0xd7)) {
                    $offset++;
                    continue;
                }
                $offset = $start;
                break;
            }
        }
        return false;
    }
}
