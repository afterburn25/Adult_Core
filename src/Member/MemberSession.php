<?php

declare(strict_types=1);

namespace AdultCore\Member;

use RuntimeException;

/** Separate member cookie and session from privileged administrator sessions. */
final class MemberSession
{
    public static function start(string $root, bool $secure): void
    {
        if (session_status() !== PHP_SESSION_NONE) throw new RuntimeException('Unexpected active session.');
        $dir = $root . '/storage/member-sessions';
        if (is_link($dir) || (!is_dir($dir) && !mkdir($dir, 0700, true)) || !is_writable($dir)) {
            throw new RuntimeException('Member session storage unavailable.');
        }
        @chmod($dir, 0700);
        ini_set('session.use_strict_mode','1');
        ini_set('session.use_only_cookies','1');
        session_save_path($dir);
        session_name('adultcore_member');
        session_set_cookie_params(['path'=>'/','httponly'=>true,'secure'=>$secure,'samesite'=>'Lax']);
        if (!session_start()) throw new RuntimeException('Cannot start member session.');
    }

    public static function csrf(): string
    {
        if (!is_string($_SESSION['member_csrf'] ?? null)) $_SESSION['member_csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['member_csrf'];
    }

    public static function csrfValid(mixed $candidate): bool
    {
        return is_string($candidate) && strlen($candidate) === 64 && hash_equals(self::csrf(), $candidate);
    }

    public static function login(int $id, int $generation = 0): void
    {
        if ($id < 1 || $generation < 0 || !session_regenerate_id(true)) throw new RuntimeException('Member login failed.');
        $_SESSION = ['member_auth'=>['id'=>$id,'issued'=>time(),'last'=>time(),'generation'=>$generation], 'member_csrf'=>bin2hex(random_bytes(32))];
    }

    public static function id(): ?int
    {
        $auth = $_SESSION['member_auth'] ?? null; $now = time();
        if (!is_array($auth) || !is_int($auth['id'] ?? null) || ($auth['id']??0)<1
            || !is_int($auth['issued'] ?? null) || !is_int($auth['last'] ?? null)
            || !is_int($auth['generation'] ?? null) || $auth['generation'] < 0
            || $auth['issued']>$now || $auth['last']>$now
            || $now-$auth['issued']>=43200 || $now-$auth['last']>=1800) {
            unset($_SESSION['member_auth']); return null;
        }
        $_SESSION['member_auth']['last'] = $now;
        return $auth['id'];
    }

    public static function generation(): ?int
    {
        return self::id() === null ? null : $_SESSION['member_auth']['generation'];
    }

    public static function revoke(): void
    {
        unset($_SESSION['member_auth']);
        if (!session_regenerate_id(true)) throw new RuntimeException('Could not revoke session.');
        $_SESSION['member_csrf'] = bin2hex(random_bytes(32));
    }

    public static function logout(): void
    {
        $_SESSION=[];
        if (ini_get('session.use_cookies')) {
            $c=session_get_cookie_params();
            setcookie(session_name(), '', ['expires'=>time()-3600,'path'=>'/','httponly'=>true,
                'secure'=>$c['secure'],'samesite'=>'Lax']);
        }
        session_destroy();
    }
}
