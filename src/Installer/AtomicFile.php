<?php

declare(strict_types=1);

namespace AdultCore\Installer;

use RuntimeException;

/** Write secrets and completed state without exposing half-written files. */
final class AtomicFile
{
    public static function write(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) || !is_writable($directory) || is_link($path) || is_file($path)) {
            throw new RuntimeException('Secure configuration target unavailable.');
        }
        $temporary = tempnam($directory, '.ac-');
        if ($temporary === false) {
            throw new RuntimeException('Could not create private temporary file.');
        }
        try {
            if (!chmod($temporary, 0600)) {
                throw new RuntimeException('Could not secure temporary file.');
            }
            $handle = fopen($temporary, 'wb');
            if ($handle === false) {
                throw new RuntimeException('Could not open temporary file.');
            }
            try {
                $written = 0;
                while ($written < strlen($contents)) {
                    $n = fwrite($handle, substr($contents, $written));
                    if ($n === false || $n === 0) {
                        throw new RuntimeException('Could not write configuration.');
                    }
                    $written += $n;
                }
                if (!fflush($handle)) {
                    throw new RuntimeException('Could not flush configuration.');
                }
                if (function_exists('fsync')) {
                    fsync($handle);
                }
            } finally {
                fclose($handle);
            }
            // PHP rename overwrites on POSIX, but exclusive installer lock +
            // no-existing-file checks prevent normal concurrent installations.
            if (file_exists($path) || is_link($path) || !rename($temporary, $path)) {
                throw new RuntimeException('Could not publish private configuration.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
