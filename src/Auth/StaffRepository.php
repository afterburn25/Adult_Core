<?php

declare(strict_types=1);

namespace AdultCore\Auth;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Owner/admin-managed moderator accounts only. No administrator role grants,
 * member editing, email delivery, payment changes, or provider verification.
 */
final class StaffRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public static function validateEmail(string $email): string
    {
        $email=strtolower(trim($email));
        if (strlen($email)<3 || strlen($email)>190
            || filter_var($email,FILTER_VALIDATE_EMAIL)===false) {
            throw new RuntimeException('Enter a valid staff email.');
        }
        return $email;
    }

    public static function validatePassword(string $password): string
    {
        if (strlen($password)<16 || strlen($password)>72
            || preg_match('/[\x00-\x1f\x7f]/',$password)===1) {
            throw new RuntimeException('Use a staff password between 16 and 72 bytes without control characters.');
        }
        return $password;
    }

    /** @return list<array{id:int,email:string,status:string}> */
    public function moderators(int $actorId): array
    {
        $this->requireManager($actorId);
        $q=$this->pdo->query("SELECT id,email,status FROM ac_users WHERE role='moderator' ORDER BY id DESC LIMIT 50");
        $items=[];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $item) {
            $items[]=['id'=>(int)$item['id'],'email'=>(string)$item['email'],'status'=>(string)$item['status']];
        }
        return $items;
    }

    /** Only authenticated managers can see these minimal action records. */
    public function recentActions(int $actorId): array
    {
        $this->requireManager($actorId);
        return $this->pdo->query("SELECT staff_id,actor_id,action,created_at
            FROM ac_staff_actions ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createModerator(int $actorId,string $email,string $initialPassword): int
    {
        $email=self::validateEmail($email);
        $initialPassword=self::validatePassword($initialPassword);
        $this->requireManager($actorId);
        $passwordHash=password_hash($initialPassword,PASSWORD_DEFAULT);
        if (!is_string($passwordHash)) throw new RuntimeException('Staff credential creation unavailable.');
        $this->pdo->beginTransaction();
        try {
            if ((int)$this->pdo->query("SELECT COUNT(*) FROM ac_users WHERE role='moderator'")->fetchColumn()>=50) {
                throw new RuntimeException('Moderator account limit reached.');
            }
            $existing=$this->pdo->prepare('SELECT id FROM ac_users WHERE email=? LIMIT 1');
            $existing->execute([$email]);
            if ($existing->fetchColumn()!==false) throw new RuntimeException('Staff email already in use.');
            // No user-supplied role/status is accepted. Creation may only
            // provision a restricted moderator, never owner or admin.
            $q=$this->pdo->prepare("INSERT INTO ac_users
                (email,password_hash,role,status,created_at) VALUES (?,?,'moderator','active',UTC_TIMESTAMP())");
            $q->execute([$email,$passwordHash]);
            $id=(int)$this->pdo->lastInsertId();
            $this->log($actorId,$id,'created');
            $this->pdo->commit();
            return $id;
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($e->getCode()==='23000') throw new RuntimeException('Staff email already in use.',0,$e);
            throw $e;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Immediately invalidates existing sessions through AdminAccounts::authorizedById. */
    public function suspendModerator(int $actorId,int $moderatorId): void
    {
        if ($moderatorId<1 || $actorId===$moderatorId) throw new RuntimeException('Invalid moderator account.');
        $this->requireManager($actorId);
        $this->pdo->beginTransaction();
        try {
            $target=$this->lockedModerator($moderatorId);
            if ($target['status']!=='active') throw new RuntimeException('Moderator is not active.');
            $this->pdo->prepare("UPDATE ac_users SET status='suspended' WHERE id=?")
                ->execute([$moderatorId]);
            $this->log($actorId,$moderatorId,'suspended');
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Reenable only with a fresh 16+ byte credential. Old password becomes
     * unusable even if a previously suspended session cookie was retained.
     */
    public function restoreModerator(int $actorId,int $moderatorId,string $newPassword): void
    {
        if ($moderatorId<1 || $actorId===$moderatorId) throw new RuntimeException('Invalid moderator account.');
        $newPassword=self::validatePassword($newPassword);
        $this->requireManager($actorId);
        $hash=password_hash($newPassword,PASSWORD_DEFAULT);
        if (!is_string($hash)) throw new RuntimeException('Staff credential creation unavailable.');
        $this->pdo->beginTransaction();
        try {
            $target=$this->lockedModerator($moderatorId);
            if ($target['status']!=='suspended') throw new RuntimeException('Moderator is not suspended.');
            $this->pdo->prepare("UPDATE ac_users SET password_hash=?,status='active' WHERE id=?")
                ->execute([$hash,$moderatorId]);
            $this->log($actorId,$moderatorId,'restored');
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** The immutable role predicate prevents using this to edit admin/member accounts. */
    private function lockedModerator(int $moderatorId): array
    {
        $q=$this->pdo->prepare('SELECT id,status FROM ac_users WHERE id=? AND role=? FOR UPDATE');
        $q->execute([$moderatorId,'moderator']);
        $item=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($item)) throw new RuntimeException('Invalid moderator account.');
        return $item;
    }

    private function requireManager(int $actorId): void
    {
        if ($actorId<1) throw new RuntimeException('Staff management permission required.');
        $q=$this->pdo->prepare("SELECT role FROM ac_users WHERE id=? AND status='active' LIMIT 1");
        $q->execute([$actorId]);
        if (!RolePolicy::allows((string)$q->fetchColumn(),'members.manage')) {
            throw new RuntimeException('Staff management permission required.');
        }
    }

    private function log(int $actorId,int $targetId,string $action): void
    {
        $q=$this->pdo->prepare('INSERT INTO ac_staff_actions
            (actor_id,staff_id,action,created_at) VALUES (?,?,?,UTC_TIMESTAMP())');
        $q->execute([$actorId,$targetId,$action]);
    }
}
