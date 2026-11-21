<?php

declare(strict_types=1);

namespace AdultCore\Member;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Owner-controlled local-account safety, NEVER age/identity consent approval,
 * member activation, payment fulfillment, deletion or creator onboarding.
 */
final class LocalMemberSafety
{
    public function __construct(private readonly PDO $pdo) {}

    public static function reason(mixed $input): string
    {
        if (!is_string($input) || strlen($input)>500
            || preg_match('//u',$input)!==1
            || preg_match('/[\x00-\x1f\x7f]/',$input)===1) {
            throw new RuntimeException('Enter a single-line reason of 10–500 bytes.');
        }
        $reason=trim($input);
        if (strlen($reason)<10 || strlen($reason)>500) {
            throw new RuntimeException('Enter a single-line reason of 10–500 bytes.');
        }
        return $reason;
    }

    /** @return array{id:int,email:string,status:string,created_at:string}|null */
    public function member(int $actorId,int $memberId): ?array
    {
        $this->requireManager($actorId);
        if ($memberId<1) return null;
        $q=$this->pdo->prepare("SELECT id,email,status,created_at FROM ac_users
            WHERE id=? AND role='member' LIMIT 1");
        $q->execute([$memberId]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        return ['id'=>(int)$row['id'],'email'=>(string)$row['email'],
            'status'=>(string)$row['status'],'created_at'=>(string)$row['created_at']];
    }

    /** @return list<array<string,mixed>> */
    public function history(int $actorId,int $memberId): array
    {
        $this->requireManager($actorId);
        if (!MemberSafetySchema::installed($this->pdo) || $memberId<1) return [];
        $q=$this->pdo->prepare('SELECT actor_id,old_status,new_status,reason,created_at
            FROM ac_member_status_actions WHERE member_id=? ORDER BY id DESC LIMIT 20');
        $q->execute([$memberId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Suspend any local member or restore ONLY to pending.
     * Every transition revokes old member sessions AND reset/confirmation
     * tokens inside the same transaction; reusing an old session after
     * restoration cannot defeat the suspension.
     */
    public function change(int $actorId,int $memberId,string $action,
                           string $expectedStatus,mixed $reason): string
    {
        $note=self::reason($reason);
        if ($actorId<1 || $memberId<1
            || !in_array($action,['suspend','restore_pending'],true)
            || !in_array($expectedStatus,['pending','active','suspended'],true)) {
            throw new RuntimeException('Invalid local member safety action.');
        }
        if (!MemberSafetySchema::installed($this->pdo)) {
            throw new RuntimeException('Apply the local member safety migration before changing accounts.');
        }
        $this->pdo->beginTransaction();
        try {
            // Manager first, then target, under consistent row lock ordering.
            $this->requireManager($actorId,true);
            $q=$this->pdo->prepare('SELECT role,status FROM ac_users WHERE id=? FOR UPDATE');
            $q->execute([$memberId]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || $row['role']!=='member') {
                throw new RuntimeException('Local member account not found.');
            }
            if (!hash_equals((string)$row['status'],$expectedStatus)) {
                throw new RuntimeException('Member account changed; reload before continuing.');
            }
            $next=match ($action) {
                'suspend'=>$expectedStatus==='suspended'?null:'suspended',
                'restore_pending'=>$expectedStatus==='suspended'?'pending':null,
            };
            if ($next===null) throw new RuntimeException('Local member status transition unavailable.');
            $q=$this->pdo->prepare("UPDATE ac_users SET status=?
                WHERE id=? AND role='member' AND status=?");
            $q->execute([$next,$memberId,$expectedStatus]);
            if ($q->rowCount()!==1) {
                throw new RuntimeException('Member account changed; reload before continuing.');
            }
            // The member session carries this generation, checked on EVERY
            // authenticated request. Clearing tokens prevents replay after
            // the owner restores a suspended account to pending.
            $q=$this->pdo->prepare('INSERT IGNORE INTO ac_member_password_resets
                (user_id,created_at) VALUES (?,UTC_TIMESTAMP())');
            $q->execute([$memberId]);
            $q=$this->pdo->prepare('UPDATE ac_member_password_resets SET
                session_generation=session_generation+1,token_sha256=NULL,
                issued_at=NULL,expires_at=NULL WHERE user_id=?');
            $q->execute([$memberId]);
            if ($q->rowCount()!==1) {
                throw new RuntimeException('Member session invalidation failed.');
            }
            $q=$this->pdo->prepare('UPDATE ac_member_email_confirmations
                SET token_sha256=NULL,issued_at=NULL,expires_at=NULL
                WHERE user_id=? AND confirmed_at IS NULL');
            $q->execute([$memberId]);
            $q=$this->pdo->prepare('INSERT INTO ac_member_status_actions
                (member_id,actor_id,old_status,new_status,reason,created_at)
                VALUES (?,?,?,?,?,UTC_TIMESTAMP())');
            $q->execute([$memberId,$actorId,$expectedStatus,$next,$note]);
            $this->pdo->commit();
            return $next;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function requireManager(int $id,bool $lock=false): void
    {
        if ($id<1) throw new RuntimeException('Local member safety requires an active manager.');
        $q=$this->pdo->prepare("SELECT role,status FROM ac_users WHERE id=?"
            .($lock?' FOR UPDATE':' LIMIT 1'));
        $q->execute([$id]);
        $actor=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($actor) || !in_array($actor['role'],['owner','admin'],true)
            || $actor['status']!=='active') {
            throw new RuntimeException('Local member safety requires an active manager.');
        }
    }
}
