<?php

declare(strict_types=1);

namespace AdultCore\Auth;

use RuntimeException;

/** Private PHP file sessions suitable for compatible shared hosting. */
final class AdminSession
{
    // A persistent, opaque session-ID cookie; NEVER a stored password/token.
    // Session data lives only in private server storage and has hard limits.
    public const IDLE_SECONDS = 86400; // 24 hours of no activity
    public const MAX_SECONDS = 604800; // 7 days since login
    public const STAFF_STEPUP_SECONDS = 1800; // reverify sensitive staff work every 30 minutes

    public static function start(string $root, bool $secure): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            throw new RuntimeException('Unexpected active session.');
        }
        $directory = $root . '/storage/sessions';
        if (is_link($directory)) {
            throw new RuntimeException('Session directory must not be a symbolic link.');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Private session storage unavailable.');
        }
        @chmod($directory, 0700);
        if (!is_writable($directory)) {
            throw new RuntimeException('Private session storage unavailable.');
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string) self::MAX_SECONDS);
        session_save_path($directory);
        session_name('adultcore_admin');
        session_set_cookie_params([
            'lifetime' => self::MAX_SECONDS,
            'path' => '/', 'httponly' => true, 'secure' => $secure, 'samesite' => 'Strict',
        ]);
        if (!session_start()) {
            throw new RuntimeException('Cannot initialize administrator session.');
        }
    }

    public static function csrf(): string
    {
        if (!is_string($_SESSION['admin_csrf'] ?? null)) {
            $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['admin_csrf'];
    }

    public static function csrfValid(mixed $candidate): bool
    {
        return is_string($candidate) && strlen($candidate) === 64
            && hash_equals(self::csrf(), $candidate);
    }

    public static function authenticate(int $id, ?int $now = null, bool $mfaVerified = false): void
    {
        if ($id < 1) {
            throw new RuntimeException('Invalid administrator ID.');
        }
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Cannot rotate login session.');
        }
        $now ??= time();
        $_SESSION = [
            'admin_csrf' => bin2hex(random_bytes(32)),
            'admin_auth' => [
                'id' => $id, 'issued_at' => $now, 'last_seen_at' => $now,
                'mfa_verified' => $mfaVerified,
                'manager_verified_at' => $now,
            ],
        ];
    }

    /**
     * The initial password-based login authorizes a brief staff-change window.
     * This is server-side session metadata, never a second browser token.
     * Older sessions with no timestamp require an explicit re-verification.
     */
    public static function managerVerifiedRecently(?int $now = null): bool
    {
        $now ??= time();
        $auth = $_SESSION['admin_auth'] ?? null;
        $verified = is_array($auth) ? ($auth['manager_verified_at'] ?? null) : null;
        return self::authenticatedId($now) !== null
            && is_int($verified) && $verified <= $now
            && $now - $verified < self::STAFF_STEPUP_SECONDS;
    }

    /** Call only after current manager credentials were verified on the server. */
    public static function markManagerVerified(?int $now = null): void
    {
        $now ??= time();
        if (self::authenticatedId($now) === null) {
            throw new RuntimeException('Cannot verify an expired administrator session.');
        }
        $_SESSION['admin_auth']['manager_verified_at'] = $now;
    }

    /**
     * Bind the authenticated moderator session to the DB revocation epoch.
     * Session regeneration discards this field; callers must rebind on login.
     */
    public static function bindStaffEpoch(int $epoch): void
    {
        if ($epoch<0 || !is_array($_SESSION['admin_auth']??null)) {
            throw new RuntimeException('Cannot bind staff session.');
        }
        $_SESSION['admin_auth']['staff_epoch']=$epoch;
    }

    public static function staffEpoch(): ?int
    {
        $epoch=$_SESSION['admin_auth']['staff_epoch']??null;
        return is_int($epoch) && $epoch>=0 ? $epoch : null;
    }

    /** A password-only result never creates an authenticated session when MFA is enabled. */
    public static function beginMfa(int $id, ?int $staffEpoch=null): void
    {
        if ($id < 1 || ($staffEpoch!==null && $staffEpoch<0)
            || !session_regenerate_id(true)) {
            throw new RuntimeException('Cannot initialize MFA challenge.');
        }
        $_SESSION = ['admin_csrf'=>bin2hex(random_bytes(32)),
            'admin_mfa_pending'=>['id'=>$id,'issued_at'=>time(),
                'staff_epoch'=>$staffEpoch]];
    }

    /** Epoch captured at PASSWORD verification, not at factor completion. */
    public static function pendingStaffEpoch(): ?int
    {
        $epoch=$_SESSION['admin_mfa_pending']['staff_epoch']??null;
        return is_int($epoch) && $epoch>=0 ? $epoch : null;
    }

    public static function pendingMfaId(?int $now = null): ?int
    {
        $now ??= time();
        $entry=$_SESSION['admin_mfa_pending'] ?? null;
        if (!is_array($entry) || !is_int($entry['id']??null) || ($entry['id']??0)<1
            || !is_int($entry['issued_at']??null) || $entry['issued_at']>$now
            || $now-$entry['issued_at']>300) {
            unset($_SESSION['admin_mfa_pending']);
            return null;
        }
        return $entry['id'];
    }

    public static function mfaVerified(): bool
    {
        return ($_SESSION['admin_auth']['mfa_verified'] ?? false) === true;
    }

    public static function setEnrollmentSecret(int $id, string $secret): void
    {
        $_SESSION['admin_mfa_enrollment']=['id'=>$id,'secret'=>$secret,'issued_at'=>time()];
    }

    public static function enrollmentSecret(int $id, ?int $now=null): ?string
    {
        $now??=time();$entry=$_SESSION['admin_mfa_enrollment']??null;
        if (!is_array($entry) || ($entry['id']??null)!==$id
            || !is_string($entry['secret']??null)|| !is_int($entry['issued_at']??null)
            || $entry['issued_at']>$now || $now-$entry['issued_at']>600) {
            unset($_SESSION['admin_mfa_enrollment']);return null;
        }
        return $entry['secret'];
    }

    public static function authenticatedId(?int $now = null): ?int
    {
        $now ??= time();
        $auth = $_SESSION['admin_auth'] ?? null;
        if (!is_array($auth) || !is_int($auth['id'] ?? null) || ($auth['id'] ?? 0) < 1
            || !is_int($auth['issued_at'] ?? null) || !is_int($auth['last_seen_at'] ?? null)
            || $auth['issued_at'] > $now || $auth['last_seen_at'] > $now
            || $now - $auth['issued_at'] >= self::MAX_SECONDS
            || $now - $auth['last_seen_at'] >= self::IDLE_SECONDS) {
            unset($_SESSION['admin_auth']);
            return null;
        }
        $_SESSION['admin_auth']['last_seen_at'] = $now;
        return $auth['id'];
    }

    public static function revoke(): void
    {
        unset($_SESSION['admin_auth'], $_SESSION['admin_mfa_pending'], $_SESSION['admin_mfa_enrollment']);
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Cannot revoke administrator session.');
        }
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000, 'path' => $params['path'], 'domain' => $params['domain'],
                'secure' => $params['secure'], 'httponly' => $params['httponly'], 'samesite' => $params['samesite'],
            ]);
        }
        session_destroy();
    }
}
