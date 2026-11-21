<?php

declare(strict_types=1);

namespace AdultCore\Member;

use PDO;
use RuntimeException;
use Throwable;

/** Self-service credential rotation; does not grant age/paid/access rights. */
final class MemberPasswordChange
{
    public function __construct(private readonly PDO $pdo) {}

    public static function validateInput(mixed $current,mixed $new,mixed $confirm): void
    {
        if (!is_string($current) || $current==='' || strlen($current)>72
            || !is_string($new) || !is_string($confirm)) {
            throw new RuntimeException('Invalid member password fields.');
        }
        PasswordRecovery::validatePassword($new);
        if (!hash_equals($new,$confirm)) {
            throw new RuntimeException('New member passwords must match.');
        }
    }

    /**
     * Locks member credential and rotates the server-side session generation
     * in one transaction. Even if the browser loses the redirect/revoke,
     * every old member cookie fails the next generation check.
     */
    public function change(int $memberId,mixed $current,mixed $new,mixed $confirm): void
    {
        self::validateInput($current,$new,$confirm);
        if ($memberId<1) throw new RuntimeException('Member credential update denied.');
        $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->prepare('SELECT password_hash,role,status FROM ac_users
                WHERE id=? FOR UPDATE');
            $q->execute([$memberId]);
            $record=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($record)
                || $record['role']!=='member'
                || !in_array($record['status'],['pending','active'],true)
                || !password_verify($current,$record['password_hash'])) {
                throw new RuntimeException('Current member password is incorrect.');
            }
            if (password_verify($new,$record['password_hash'])) {
                throw new RuntimeException('Choose a different member password.');
            }
            $hash=password_hash($new,PASSWORD_DEFAULT);
            if (!is_string($hash)) throw new RuntimeException('Member password update unavailable.');
            $q=$this->pdo->prepare("UPDATE ac_users SET password_hash=?
                WHERE id=? AND role='member' AND status IN ('pending','active')");
            $q->execute([$hash,$memberId]);
            if ($q->rowCount()!==1) throw new RuntimeException('Member password update unavailable.');
            $q=$this->pdo->prepare('INSERT IGNORE INTO ac_member_password_resets
                (user_id,created_at) VALUES (?,UTC_TIMESTAMP())');
            $q->execute([$memberId]);
            $q=$this->pdo->prepare('UPDATE ac_member_password_resets SET
                token_sha256=NULL,issued_at=NULL,expires_at=NULL,
                session_generation=session_generation+1 WHERE user_id=?');
            $q->execute([$memberId]);
            if ($q->rowCount()!==1) throw new RuntimeException('Member session invalidation unavailable.');
            $q=$this->pdo->prepare('UPDATE ac_member_email_confirmations SET
                token_sha256=NULL,issued_at=NULL,expires_at=NULL
                WHERE user_id=? AND confirmed_at IS NULL');
            $q->execute([$memberId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }
}
