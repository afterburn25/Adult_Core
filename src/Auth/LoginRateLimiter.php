<?php

declare(strict_types=1);

namespace AdultCore\Auth;

use RuntimeException;

/** File-lock counter, keyed by hashed IP and email; bounds brute-force attempts. */
final class LoginRateLimiter
{
    public function __construct(private readonly string $storage)
    {
    }

    public function consume(string $remoteIp, string $email, ?int $now = null): void
    {
        $now ??= time();
        $this->consumeKey('ip', $remoteIp, 15, $now);
        $this->consumeKey('email', strtolower(trim($email)), 8, $now);
    }

    private function consumeKey(string $kind, string $value, int $limit, int $now): void
    {
        $file = $this->storage . '/.admin-login-' . $kind . '-' . hash('sha256', $value);
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Login protection unavailable.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Login protection unavailable.');
            }
            $contents = stream_get_contents($handle);
            $decoded = is_string($contents) ? json_decode($contents, true) : null;
            $attempts = is_array($decoded) ? array_values(array_filter($decoded, static fn($t): bool => is_int($t) && $t > $now - 900)) : [];
            if (count($attempts) >= $limit) {
                throw new RuntimeException('Too many login attempts. Please try again later.');
            }
            $attempts[] = $now;
            rewind($handle);
            ftruncate($handle, 0);
            if (fwrite($handle, json_encode($attempts, JSON_THROW_ON_ERROR)) === false || !fflush($handle)) {
                throw new RuntimeException('Login protection unavailable.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            @chmod($file, 0600);
        }
    }
}
