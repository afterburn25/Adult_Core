<?php

declare(strict_types=1);

namespace AdultCore\Member;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Member-controlled device/session revocation. Never changes passwords,
 * local account status, membership, provider verification or media access.
 */
final class MemberSessionRevocation
{
    public function __construct(private readonly PDO $pdo) {}

    public static function validateCurrentPassword(mixed $password): string
    {
        if (!is_string($password) || $password==='' || strlen($password)>72) {
            throw new RuntimeException('Current member password is required.');
        }
        return $password;
    }

    /**
     * Require a live member session generation and CURRENT password. Lock the
     * member first and reset-generation row second, matching password-change
     * and suspension transaction order. An old in-flight session cannot
     * revoke newer sessions after the first successful generation change.
     */
    public function revokeAll(int $memberId,int $expectedGeneration,mixed $password): void
    {
        $current=self::validateCurrentPassword($password);
        if ($memberId<1 || $expectedGeneration<0) {
            throw new RuntimeException('Member session revocation denied.');
        }
        $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->prepare('SELECT password_hash,role,status FROM ac_users
                WHERE id=? FOR UPDATE');
            $q->execute([$memberId]);
            $record=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($record) || $record['role']!=='member'
                || !in_array($record['status'],['pending','active'],true)
                || !is_string($record['password_hash'])
                || !password_verify($current,$record['password_hash'])) {
                throw new RuntimeException('Current member password is incorrect.');
            }
            $q=$this->pdo->prepare('INSERT IGNORE INTO ac_member_password_resets
                (user_id,created_at) VALUES (?,UTC_TIMESTAMP())');
            $q->execute([$memberId]);
            $q=$this->pdo->prepare('SELECT session_generation
                FROM ac_member_password_resets WHERE user_id=? FOR UPDATE');
            $q->execute([$memberId]);
            $generation=$q->fetchColumn();
            if ($generation===false || (int)$generation!==$expectedGeneration) {
                throw new RuntimeException('Member session changed; please sign in again.');
            }
            $q=$this->pdo->prepare('UPDATE ac_member_password_resets SET
                session_generation=session_generation+1,token_sha256=NULL,
                issued_at=NULL,expires_at=NULL WHERE user_id=?');
            $q->execute([$memberId]);
            if ($q->rowCount()!==1) {
                throw new RuntimeException('Member session revocation unavailable.');
            }
            $q=$this->pdo->prepare('UPDATE ac_member_email_confirmations
                SET token_sha256=NULL,issued_at=NULL,expires_at=NULL
                WHERE user_id=? AND confirmed_at IS NULL');
            $q->execute([$memberId]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }
}
