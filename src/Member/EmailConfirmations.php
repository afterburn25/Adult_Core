<?php

declare(strict_types=1);

namespace AdultCore\Member;

use PDO;
use RuntimeException;
use Throwable;

/** One-use email ownership tokens. Never store or log the plaintext token. */
final class EmailConfirmations
{
    public function __construct(private readonly PDO $pdo) {}

    /** Issue at most once per five minutes for an eligible member. */
    public function issue(int $userId): string
    {
        if ($userId < 1) throw new RuntimeException('Confirmation unavailable.');
        $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->prepare("SELECT id FROM ac_users WHERE id=? AND role='member' AND status IN ('pending','active') FOR UPDATE");
            $q->execute([$userId]);
            if (!$q->fetchColumn()) throw new RuntimeException('Confirmation unavailable.');
            $q=$this->pdo->prepare('INSERT IGNORE INTO ac_member_email_confirmations (user_id,created_at) VALUES (?,UTC_TIMESTAMP())');
            $q->execute([$userId]);
            $q=$this->pdo->prepare('SELECT confirmed_at,issued_at FROM ac_member_email_confirmations WHERE user_id=? FOR UPDATE');
            $q->execute([$userId]);
            $record=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($record) || $record['confirmed_at'] !== null) throw new RuntimeException('Confirmation unavailable.');
            if ($record['issued_at'] !== null && strtotime($record['issued_at'] . ' UTC') > time()-300) {
                throw new RuntimeException('Please wait before requesting another confirmation.');
            }
            $token=bin2hex(random_bytes(32));
            $q=$this->pdo->prepare('UPDATE ac_member_email_confirmations SET token_sha256=?,issued_at=UTC_TIMESTAMP(),expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE) WHERE user_id=? AND confirmed_at IS NULL');
            $q->execute([hash('sha256',$token),$userId]);
            $this->pdo->commit();
            return $token;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Inbox possession only, never identity, age or consent. */
    public function confirm(string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/D',$token)) return false;
        $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->prepare("SELECT e.user_id FROM ac_member_email_confirmations e
                JOIN ac_users u ON u.id=e.user_id AND u.role='member' AND u.status IN ('pending','active')
                WHERE e.token_sha256=? AND e.confirmed_at IS NULL AND e.expires_at>UTC_TIMESTAMP() FOR UPDATE");
            $q->execute([hash('sha256',$token)]);
            $id=$q->fetchColumn();
            if ($id === false) { $this->pdo->commit(); return false; }
            $q=$this->pdo->prepare('UPDATE ac_member_email_confirmations SET confirmed_at=UTC_TIMESTAMP(),token_sha256=NULL,expires_at=NULL WHERE user_id=? AND confirmed_at IS NULL');
            $q->execute([(int)$id]);
            $confirmed=$q->rowCount() === 1;
            $this->pdo->commit();
            return $confirmed;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function verified(int $userId): bool
    {
        if ($userId < 1) return false;
        $q=$this->pdo->prepare('SELECT 1 FROM ac_member_email_confirmations WHERE user_id=? AND confirmed_at IS NOT NULL LIMIT 1');
        $q->execute([$userId]);
        return (bool)$q->fetchColumn();
    }
}
