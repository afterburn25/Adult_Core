<?php

declare(strict_types=1);

namespace AdultCore\Member;

use PDO;
use RuntimeException;
use Throwable;

/** Password reset tokens live only as SHA-256 hashes; responses must not disclose account existence. */
final class PasswordRecovery
{
    public function __construct(private readonly PDO $pdo) {}

    public static function validatePassword(string $password): void
    {
        if (strlen($password) < 12 || strlen($password) > 72) {
            throw new RuntimeException('Password must be between 12 and 72 bytes.');
        }
    }

    /** @return array{email:string,token:string}|null */
    public function issueForEmail(string $candidate): ?array
    {
        $email = strtolower(trim($candidate));
        if (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
        $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->prepare("SELECT u.id,u.email FROM ac_users u
                JOIN ac_member_email_confirmations e ON e.user_id=u.id AND e.confirmed_at IS NOT NULL
                WHERE u.email=? AND u.role='member' AND u.status IN ('pending','active') FOR UPDATE");
            $q->execute([$email]);
            $user = $q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($user)) { $this->pdo->commit(); return null; }
            $id = (int) $user['id'];
            $q = $this->pdo->prepare('INSERT IGNORE INTO ac_member_password_resets (user_id,created_at) VALUES (?,UTC_TIMESTAMP())');
            $q->execute([$id]);
            $q = $this->pdo->prepare('SELECT issued_at FROM ac_member_password_resets WHERE user_id=? FOR UPDATE');
            $q->execute([$id]);
            $issued = $q->fetchColumn();
            if (is_string($issued) && strtotime($issued.' UTC') > time()-300) {
                $this->pdo->commit();
                return null;
            }
            $token = bin2hex(random_bytes(32));
            $q = $this->pdo->prepare('UPDATE ac_member_password_resets
                SET token_sha256=?, issued_at=UTC_TIMESTAMP(), expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE)
                WHERE user_id=?');
            $q->execute([hash('sha256', $token), $id]);
            $this->pdo->commit();
            return ['email'=>(string)$user['email'], 'token'=>$token];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Consumes a valid token exactly once, changes only the password, and revokes previous sessions. */
    public function reset(string $token, string $password): bool
    {
        self::validatePassword($password);
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return false;
        $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->prepare("SELECT r.user_id FROM ac_member_password_resets r
                JOIN ac_users u ON u.id=r.user_id AND u.role='member' AND u.status IN ('pending','active')
                WHERE r.token_sha256=? AND r.expires_at>UTC_TIMESTAMP() FOR UPDATE");
            $q->execute([hash('sha256',$token)]);
            $id = $q->fetchColumn();
            if ($id === false) { $this->pdo->commit(); return false; }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $q = $this->pdo->prepare("UPDATE ac_users SET password_hash=? WHERE id=? AND role='member' AND status IN ('pending','active')");
            $q->execute([$hash,(int)$id]);
            if ($q->rowCount() !== 1) throw new RuntimeException('Password reset failed.');
            $q = $this->pdo->prepare('UPDATE ac_member_password_resets
                SET token_sha256=NULL,issued_at=NULL,expires_at=NULL,session_generation=session_generation+1
                WHERE user_id=? AND token_sha256=?');
            $q->execute([(int)$id,hash('sha256',$token)]);
            if ($q->rowCount() !== 1) throw new RuntimeException('Password reset failed.');
            $this->pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Member sessions are rejected whenever this counter changes. */
    public function generation(int $userId): int
    {
        if ($userId < 1) return -1;
        $q = $this->pdo->prepare('SELECT session_generation FROM ac_member_password_resets WHERE user_id=?');
        $q->execute([$userId]);
        $v = $q->fetchColumn();
        return $v === false ? 0 : (int)$v;
    }
}
