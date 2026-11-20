<?php

declare(strict_types=1);

namespace AdultCore\Installer;

use RuntimeException;

/** Filesystem-backed, IP-based attempt counter surviving browser cookie resets. */
final class InstallRateLimiter
{
    public function __construct(private readonly string $storage)
    {
    }

    public function consume(string $remoteIp): void
    {
        $file = $this->storage . '/.install-attempts-' . hash('sha256', $remoteIp);
        $handle = fopen($file, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Installer security lock unavailable.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Installer security lock unavailable.');
            }
            $data = stream_get_contents($handle);
            $attempts = $data !== false ? json_decode($data, true) : null;
            $times = is_array($attempts) ? array_filter($attempts, static fn($t) => is_int($t) && $t > time() - 900) : [];
            if (count($times) >= 5) {
                throw new RuntimeException('Too many installation attempts. Try again in 15 minutes.');
            }
            $times[] = time();
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode(array_values($times), JSON_THROW_ON_ERROR));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            @chmod($file, 0600);
        }
    }
}
