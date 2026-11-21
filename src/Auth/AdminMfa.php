<?php

declare(strict_types=1);

namespace AdultCore\Auth;

use PDO;
use RuntimeException;
use Throwable;

/** One enrollment per admin, encrypted TOTP secret, transactional one-time proof consumption. */
final class AdminMfa
{
    public function __construct(private readonly PDO $pdo, private readonly string $root)
    {
    }

    public function ready(): bool
    {
        $q = $this->pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='ac_admin_mfa'");
        return (int)$q->fetchColumn() === 1;
    }

    public function enabledFor(int $userId): bool
    {
        if (!$this->ready()) {
            // Prevent table-removal bypass after enrollment. For older installations,
            // allow password login solely so the owner can apply the MFA migration.
            $marker = $this->pdo->query("SELECT setting_value FROM ac_settings WHERE setting_key='admin_mfa_enforced'")->fetchColumn();
            if ($marker === '1') {
                throw new RuntimeException('Administrator MFA storage is missing. Restore the database.');
            }
            return false;
        }
        $q = $this->pdo->prepare('SELECT user_id FROM ac_admin_mfa WHERE user_id=?');
        $q->execute([$userId]);
        $found = (bool)$q->fetchColumn();
        // A missing factor record cannot silently turn off a previously enrolled
        // administrator. Only the authenticated disable operation clears the marker.
        if (!$found) {
            $marker = $this->pdo->query("SELECT setting_value FROM ac_settings WHERE setting_key='admin_mfa_enforced'")->fetchColumn();
            if ($marker === '1') {
                throw new RuntimeException('Administrator MFA enrollment state is missing. Restore the database.');
            }
        }
        return $found;
    }

    public function newSecret(): string
    {
        if (!$this->ready()) {
            throw new RuntimeException('Apply /admin/setup migrations before enrolling MFA.');
        }
        return Totp::generateSecret();
    }

    /** @return list<string> One-time recovery codes; caller shows once and NEVER stores plaintext. */
    public function enable(int $userId, string $secret, string $code, ?int $at = null): array
    {
        $matched = Totp::verify($secret, $code, -1, $at);
        if ($matched === null || $userId < 1 || !$this->ready()) {
            throw new RuntimeException('Authenticator confirmation failed.');
        }
        $codes = [];
        $hashes = [];
        for ($i = 0; $i < 10; $i++) {
            $token = strtoupper(bin2hex(random_bytes(8)));
            $codes[] = $token;
            $hashes[] = $this->recoveryDigest($token);
        }
        $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->prepare('SELECT user_id FROM ac_admin_mfa WHERE user_id=? FOR UPDATE');
            $q->execute([$userId]);
            if ($q->fetchColumn() !== false) {
                throw new RuntimeException('Authenticator MFA is already enabled.');
            }
            $stmt = $this->pdo->prepare('INSERT INTO ac_admin_mfa (user_id,secret_box,recovery_hashes,last_counter,enabled_at) VALUES (?,?,?,?,UTC_TIMESTAMP())');
            $stmt->execute([$userId, $this->encrypt($secret), json_encode($hashes, JSON_THROW_ON_ERROR), $matched]);
            $this->pdo->exec("INSERT INTO ac_settings (setting_key, setting_value) VALUES ('admin_mfa_enforced','1') ON DUPLICATE KEY UPDATE setting_value='1'");
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        return $codes;
    }

    /** Consume TOTP/recovery code exactly once under an InnoDB row lock. */
    public function consume(int $userId, string $factor, ?int $at = null): bool
    {
        if (!$this->ready() || $userId < 1 || strlen($factor) > 64) {
            return false;
        }
        $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->prepare('SELECT secret_box,recovery_hashes,last_counter FROM ac_admin_mfa WHERE user_id=? FOR UPDATE');
            $q->execute([$userId]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->pdo->rollBack();
                return false;
            }
            $changes = false;
            $lastCounter = (int)$row['last_counter'];
            $hashes = json_decode((string)$row['recovery_hashes'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($hashes) || !array_is_list($hashes)) {
                throw new RuntimeException('MFA recovery state is damaged.');
            }
            if (preg_match('/^[0-9]{6}$/D', $factor) === 1) {
                $matched = Totp::verify($this->decrypt((string)$row['secret_box']), $factor, $lastCounter, $at);
                if ($matched !== null) {
                    $lastCounter = $matched;
                    $changes = true;
                }
            } elseif (preg_match('/^[a-fA-F0-9]{16}$/D', $factor) === 1) {
                $digest = $this->recoveryDigest(strtoupper($factor));
                foreach ($hashes as $i => $hash) {
                    if (is_string($hash) && hash_equals($hash, $digest)) {
                        array_splice($hashes, $i, 1);
                        $changes = true;
                        break;
                    }
                }
            }
            if ($changes) {
                $update = $this->pdo->prepare('UPDATE ac_admin_mfa SET last_counter=?,recovery_hashes=? WHERE user_id=?');
                $update->execute([$lastCounter,json_encode($hashes, JSON_THROW_ON_ERROR),$userId]);
            }
            $this->pdo->commit();
            return $changes;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function disable(int $userId): void
    {
        if (!$this->ready() || $userId < 1) throw new RuntimeException('MFA setup unavailable.');
        $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->prepare('DELETE FROM ac_admin_mfa WHERE user_id=?');
            $q->execute([$userId]);
            // One installer admin today, but do not disable future accounts' marker.
            $count = (int)$this->pdo->query('SELECT COUNT(*) FROM ac_admin_mfa')->fetchColumn();
            if ($count === 0) {
                $this->pdo->exec("UPDATE ac_settings SET setting_value='0' WHERE setting_key='admin_mfa_enforced'");
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function remainingRecoveryCodes(int $userId): int
    {
        if (!$this->ready()) return 0;
        $q=$this->pdo->prepare('SELECT recovery_hashes FROM ac_admin_mfa WHERE user_id=?');
        $q->execute([$userId]);
        $encoded = $q->fetchColumn();
        if (!is_string($encoded)) return 0;
        $list = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        return is_array($list) ? count($list) : 0;
    }

    private function key(): string
    {
        $file = $this->root.'/config/app-key.php';
        if (!is_file($file) || is_link($file)) throw new RuntimeException('Private application key unavailable.');
        $hex = require $file;
        if (!is_string($hex) || preg_match('/^[a-f0-9]{64}$/D', $hex) !== 1) {
            throw new RuntimeException('Invalid application key.');
        }
        return hash_hkdf('sha256', hex2bin($hex), 32, 'adultcore-admin-mfa-aes-v1');
    }

    private function recoveryDigest(string $token): string
    {
        return hash_hmac('sha256', 'adultcore-admin-mfa-recovery-v1:'.$token, $this->key());
    }

    private function encrypt(string $secret): string
    {
        Totp::decode($secret);
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($secret, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $nonce, $tag, 'adultcore-admin-mfa-v1', 16);
        if (!is_string($cipher) || strlen($tag) !== 16) throw new RuntimeException('Could not encrypt authenticator state.');
        return base64_encode($nonce.$tag.$cipher);
    }

    private function decrypt(string $encoded): string
    {
        $binary = base64_decode($encoded, true);
        if (!is_string($binary) || strlen($binary) < 29) throw new RuntimeException('Authenticator state damaged.');
        $secret = openssl_decrypt(substr($binary,28), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA,
            substr($binary,0,12), substr($binary,12,16), 'adultcore-admin-mfa-v1');
        if (!is_string($secret)) throw new RuntimeException('Authenticator state could not be decrypted.');
        Totp::decode($secret);
        return $secret;
    }
}
