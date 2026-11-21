<?php

declare(strict_types=1);

namespace AdultCore\Auth;

use InvalidArgumentException;

/** RFC 6238 SHA-1, 30-second TOTP; codes cannot be replayed within the drift window. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(): string
    {
        return self::base32(random_bytes(20));
    }

    public static function base32(string $binary): string
    {
        $bits = '';
        for ($i = 0; $i < strlen($binary); $i++) {
            $bits .= str_pad(decbin(ord($binary[$i])), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        for ($i = 0; $i < strlen($bits); $i += 5) {
            $chunk = substr($bits, $i, 5);
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $out;
    }

    public static function decode(string $secret): string
    {
        if (strlen($secret) !== 32 || preg_match('/^[A-Z2-7]{32}$/D', $secret) !== 1) {
            throw new InvalidArgumentException('Invalid authenticator secret.');
        }
        $bits = '';
        foreach (str_split($secret) as $ch) {
            $index = strpos(self::ALPHABET, $ch);
            if ($index === false) {
                throw new InvalidArgumentException('Invalid authenticator secret.');
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }
        $decoded = '';
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $decoded .= chr(bindec(substr($bits, $i, 8)));
        }
        return $decoded;
    }

    public static function code(string $secret, int $counter): string
    {
        if ($counter < 0 || $counter > 4294967295) {
            throw new InvalidArgumentException('Invalid authenticator counter.');
        }
        $mac = hash_hmac('sha1', pack('N2', 0, $counter), self::decode($secret), true);
        $offset = ord($mac[19]) & 15;
        $n = (unpack('N', substr($mac, $offset, 4))[1] & 0x7fffffff) % 1000000;
        return str_pad((string)$n, 6, '0', STR_PAD_LEFT);
    }

    /** @return int|null The matched moving counter, strictly newer than last used. */
    public static function verify(string $secret, string $code, int $lastCounter = -1, ?int $at = null): ?int
    {
        if (preg_match('/^[0-9]{6}$/D', $code) !== 1) {
            return null;
        }
        $step = intdiv($at ?? time(), 30);
        foreach ([$step - 1, $step, $step + 1] as $counter) {
            if ($counter > $lastCounter && $counter >= 0 && hash_equals(self::code($secret, $counter), $code)) {
                return $counter;
            }
        }
        return null;
    }
}
