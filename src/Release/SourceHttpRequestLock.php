<?php

declare(strict_types=1);

namespace AdultCore\Release;

use RuntimeException;

/**
 * Advisory, LOCAL-host front-controller request lifetime lock.
 *
 * Every READY dynamic HTTP request and every signed Pro JOB RECEIPT writer
 * holds a shared flock for its lifetime. Source maintenance obtains an
 * exclusive, NONBLOCKING flock before publishing its HTTP marker. It cannot
 * cut across an active local request or a cooperating receipt writer.
 *
 * This does NOT quiesce existing Pro jobs, running FFmpeg workers, independent
 * PHP/CLI scripts, databases, static assets, remote nodes, or filesystems
 * with unreliable flock semantics.
 */
final class SourceHttpRequestLock
{
    private const FILE = '.source-http-requests.lock';

    /**
     * @return resource|null A request must retain this handle until shutdown.
     * Returning null is restricted to preinstall roots with no storage yet;
     * InstallationGate remains responsible for denying invalid installations.
     */
    public static function enter(string $installedRoot)
    {
        $storage = $installedRoot . '/storage';
        if (!is_link($storage) && !file_exists($storage)) {
            return null;
        }
        return self::acquire($installedRoot, LOCK_SH);
    }

    /**
     * @return resource Exclusive operator guard. Caller must close it after
     * completing marker creation/removal. Never wait for active site requests.
     */
    public static function exclusive(string $installedRoot)
    {
        return self::acquire($installedRoot, LOCK_EX);
    }

    /** @return resource */
    private static function acquire(string $installedRoot, int $kind)
    {
        $root = realpath($installedRoot);
        if ($root === false || $root === '/' || !is_dir($root) || is_link($installedRoot)) {
            throw new RuntimeException('Source HTTP request lock has an unsafe application root.');
        }
        $storage = $root . '/storage';
        if (is_link($storage) || !is_dir($storage) || !is_writable($storage)) {
            throw new RuntimeException('Source HTTP request lock requires real writable site storage.');
        }
        $directoryMode = fileperms($storage);
        if (!is_int($directoryMode) || ($directoryMode & 0022) !== 0) {
            throw new RuntimeException('Site storage is writable by an untrusted user.');
        }
        $lockFile = $storage . '/' . self::FILE;
        if (is_link($lockFile) || (file_exists($lockFile) && !is_file($lockFile))) {
            throw new RuntimeException('Source HTTP request lock file is unsafe.');
        }
        // A lock contains no secret or payload, but must not be world-writable.
        // Secure creation applies to the existing installed runtime as well.
        $previousUmask = umask(0077);
        try {
            $handle = @fopen($lockFile, 'c+b');
        } finally {
            umask($previousUmask);
        }
        if (!is_resource($handle)) {
            throw new RuntimeException('Cannot open the source HTTP request lock.');
        }
        try {
            clearstatcache(true, $lockFile);
            $metadata = fstat($handle);
            $pathMetadata = @lstat($lockFile);
            if (is_link($lockFile) || !is_array($metadata) || !is_array($pathMetadata)
                || ($metadata['mode'] & 0170000) !== 0100000
                || ($pathMetadata['mode'] & 0170000) !== 0100000
                || $metadata['ino'] !== $pathMetadata['ino']
                || $metadata['dev'] !== $pathMetadata['dev']
                || ($metadata['mode'] & 0077) !== 0
                || $metadata['size'] !== 0) {
                throw new RuntimeException('The source HTTP request lock changed or is not private.');
            }
            if (!flock($handle, $kind | LOCK_NB)) {
                throw new RuntimeException(
                    'Active application requests or source maintenance prevent obtaining the requested lock.'
                );
            }
            // Ensure no one swapped the named lock after acquiring it. The
            // private owner-only site storage is still a trust requirement.
            clearstatcache(true, $lockFile);
            $current = @lstat($lockFile);
            if (!is_array($current) || is_link($lockFile)
                || $metadata['ino'] !== $current['ino']
                || $metadata['dev'] !== $current['dev']) {
                throw new RuntimeException('Source HTTP request lock was replaced during acquisition.');
            }
            return $handle;
        } catch (\Throwable $error) {
            fclose($handle);
            throw $error;
        }
    }
}
