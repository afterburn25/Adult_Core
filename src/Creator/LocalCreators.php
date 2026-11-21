<?php

declare(strict_types=1);

namespace AdultCore\Creator;

use AdultCore\Auth\RolePolicy;
use AdultCore\Member\EmailConfirmations;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Creator status is scoped to the local installation. An enabled profile
 * permits only private TEXT drafts; it is NOT age/identity verification,
 * performer consent, moderation approval or authorization for public media.
 */
final class LocalCreators
{
    public function __construct(private readonly PDO $pdo) {}

    public static function displayName(string $value): string
    {
        $value=trim($value);
        if (strlen($value)<2 || strlen($value)>100
            || preg_match('//u',$value)!==1
            || preg_match('/[\x00-\x1f\x7f]/',$value)===1) {
            throw new RuntimeException('Use a creator display name of 2–100 bytes without control characters.');
        }
        return $value;
    }

    public static function draftTitle(string $value): string
    {
        $value=trim($value);
        if (strlen($value)<2 || strlen($value)>190
            || preg_match('//u',$value)!==1
            || preg_match('/[\x00-\x1f\x7f]/',$value)===1) {
            throw new RuntimeException('Use a private draft title of 2–190 bytes without control characters.');
        }
        return $value;
    }

    public static function draftSummary(string $value): string
    {
        $value=trim($value);
        if (strlen($value)<10 || strlen($value)>1200
            || preg_match('//u',$value)!==1
            || preg_match('/[\x00-\x08\x0b-\x1f\x7f]/',$value)===1) {
            throw new RuntimeException('Describe the private draft in 10–1200 bytes without private evidence.');
        }
        return $value;
    }

    public static function email(string $value): string
    {
        $value=strtolower(trim($value));
        if (strlen($value)>190 || filter_var($value,FILTER_VALIDATE_EMAIL)===false) {
            throw new RuntimeException('Enter an existing, confirmed local member email.');
        }
        return $value;
    }

    /** Owner/admin explicitly grants a local member access to private creator tools. */
    public function provision(int $actorId,string $email,string $name): int
    {
        $email=self::email($email);
        $name=self::displayName($name);
        $this->pdo->beginTransaction();
        try {
            $this->requireManager($actorId,true);
            if ((int)$this->pdo->query('SELECT COUNT(*) FROM ac_creator_profiles')->fetchColumn()>=500) {
                throw new RuntimeException('Local creator account limit reached.');
            }
            $q=$this->pdo->prepare("SELECT id FROM ac_users
                WHERE email=? AND role='member' AND status IN ('pending','active') LIMIT 1 FOR UPDATE");
            $q->execute([$email]);
            $memberId=(int)$q->fetchColumn();
            if ($memberId<1 || !(new EmailConfirmations($this->pdo))->verified($memberId)) {
                throw new RuntimeException('Choose a locally registered member with confirmed email.');
            }
            $q=$this->pdo->prepare('SELECT id FROM ac_creator_profiles WHERE member_id=? LIMIT 1');
            $q->execute([$memberId]);
            if ($q->fetchColumn()!==false) throw new RuntimeException('This member already has a local creator account.');
            $q=$this->pdo->prepare("INSERT INTO ac_creator_profiles
                (member_id,display_name,status,revision,created_by,created_at,updated_at)
                VALUES (?,?,'enabled',1,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
            $q->execute([$memberId,$name,$actorId]);
            $creatorId=(int)$this->pdo->lastInsertId();
            $this->record($actorId,$creatorId,'provisioned');
            $this->pdo->commit();
            return $creatorId;
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($e->getCode()==='23000') {
                throw new RuntimeException('This member already has a local creator account.',0,$e);
            }
            throw $e;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public function managed(int $actorId,?int $before=null): array
    {
        $this->requireManager($actorId,false);
        if ($before!==null && $before<1) {
            throw new RuntimeException('Invalid local creator page cursor.');
        }
        // One sentinel record beyond the 50 visible entries enables the
        // owner to page all local profiles instead of hiding older creators.
        $sql="SELECT c.id,c.member_id,c.display_name,c.status,c.revision,u.email,
            (SELECT COUNT(*) FROM ac_creator_drafts d WHERE d.creator_id=c.id) AS draft_count
            FROM ac_creator_profiles c JOIN ac_users u ON u.id=c.member_id";
        if ($before!==null) $sql.=' WHERE c.id<?';
        $q=$this->pdo->prepare($sql.' ORDER BY c.id DESC LIMIT 51');
        $q->execute($before===null?[]:[$before]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Local owner/admin can inspect only saved planning text on THIS site.
     * It does not contain original media, identity evidence, or access keys.
     *
     * @return array{profile:array<string,mixed>,drafts:list<array<string,mixed>>}|null
     */
    public function privatePlansForOwner(int $actorId,int $creatorId): ?array
    {
        $this->requireManager($actorId,false);
        if ($creatorId<1) return null;
        $q=$this->pdo->prepare("SELECT c.id,c.display_name,c.status,u.email
            FROM ac_creator_profiles c JOIN ac_users u ON u.id=c.member_id
            WHERE c.id=? LIMIT 1");
        $q->execute([$creatorId]);
        $profile=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($profile)) return null;
        $q=$this->pdo->prepare("SELECT id,title,summary,status,review_note,revision,updated_at
            FROM ac_creator_drafts WHERE creator_id=?
                AND status IN ('private','submitted','changes','rejected')
            ORDER BY id DESC LIMIT 50");
        $q->execute([$creatorId]);
        return ['profile'=>$profile,'drafts'=>$q->fetchAll(PDO::FETCH_ASSOC)];
    }

    /** @return list<array<string,mixed>> */
    public function audit(int $actorId): array
    {
        $this->requireManager($actorId,false);
        return $this->pdo->query("SELECT actor_id,creator_id,action,created_at
            FROM ac_creator_actions ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Change the presentation label only; never alter member credentials,
     * creator status, paid entitlement or any saved private content.
     */
    public function rename(int $actorId,int $creatorId,int $expectedRevision,string $newName): void
    {
        if ($creatorId<1 || $expectedRevision<1) {
            throw new RuntimeException('Invalid local creator action.');
        }
        $newName=self::displayName($newName);
        $this->pdo->beginTransaction();
        try {
            $this->requireManager($actorId,true);
            $q=$this->pdo->prepare('SELECT display_name,revision,status
                FROM ac_creator_profiles WHERE id=? FOR UPDATE');
            $q->execute([$creatorId]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || !in_array($row['status'],['enabled','suspended'],true)) {
                throw new RuntimeException('Local creator account not found.');
            }
            if ((int)$row['revision']!==$expectedRevision) {
                throw new RuntimeException('Creator status changed; reload the account list.');
            }
            if ($row['display_name']===$newName) {
                throw new RuntimeException('Creator display name is unchanged.');
            }
            $this->pdo->prepare('UPDATE ac_creator_profiles
                SET display_name=?,revision=revision+1,updated_at=UTC_TIMESTAMP()
                WHERE id=?')->execute([$newName,$creatorId]);
            $this->record($actorId,$creatorId,'renamed');
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function setStatus(int $actorId,int $creatorId,int $expectedRevision,string $action): void
    {
        if ($creatorId<1 || $expectedRevision<1
            || !in_array($action,['suspend','restore'],true)) {
            throw new RuntimeException('Invalid local creator action.');
        }
        $this->pdo->beginTransaction();
        try {
            $this->requireManager($actorId,true);
            $q=$this->pdo->prepare('SELECT member_id,status,revision FROM ac_creator_profiles WHERE id=? FOR UPDATE');
            $q->execute([$creatorId]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) throw new RuntimeException('Local creator account not found.');
            if ((int)$row['revision']!==$expectedRevision) {
                throw new RuntimeException('Creator status changed; reload the account list.');
            }
            $old=$action==='suspend'?'enabled':'suspended';
            $new=$action==='suspend'?'suspended':'enabled';
            if ($row['status']!==$old) throw new RuntimeException('Creator is not in the required state.');
            if ($action==='restore') {
                $member=$this->pdo->prepare("SELECT id FROM ac_users WHERE id=? AND role='member'
                    AND status IN ('pending','active') LIMIT 1 FOR UPDATE");
                $member->execute([(int)$row['member_id']]);
                if ($member->fetchColumn()===false ||
                    !(new EmailConfirmations($this->pdo))->verified((int)$row['member_id'])) {
                    throw new RuntimeException('Creator member account or email is no longer eligible.');
                }
            }
            $this->pdo->prepare('UPDATE ac_creator_profiles SET status=?,revision=revision+1,updated_at=UTC_TIMESTAMP()
                WHERE id=?')->execute([$new,$creatorId]);
            $this->record($actorId,$creatorId,$action==='suspend'?'suspended':'restored');
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return array<string,mixed>|null */
    public function activeForMember(int $memberId): ?array
    {
        if ($memberId<1) return null;
        $q=$this->pdo->prepare("SELECT c.id,c.display_name,c.revision
            FROM ac_creator_profiles c JOIN ac_users u ON u.id=c.member_id
            JOIN ac_member_email_confirmations e ON e.user_id=u.id AND e.confirmed_at IS NOT NULL
            WHERE c.member_id=? AND c.status='enabled' AND u.role='member'
                AND u.status IN ('pending','active') LIMIT 1");
        $q->execute([$memberId]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    /** @return list<array<string,mixed>> */
    public function ownDrafts(int $memberId): array
    {
        $creator=$this->activeForMember($memberId);
        if ($creator===null) return [];
        $q=$this->pdo->prepare("SELECT id,title,summary,status,review_note,revision,updated_at
            FROM ac_creator_drafts WHERE creator_id=?
                AND status IN ('private','submitted','changes','rejected')
            ORDER BY id DESC LIMIT 50");
        $q->execute([(int)$creator['id']]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Never touches public/ac_content or media/moderation/verification records. */
    public function saveDraft(int $memberId, ?int $draftId, ?int $expectedRevision,
                              string $title,string $summary): int
    {
        if ($memberId<1 || ($draftId!==null && $draftId<1)
            || ($expectedRevision!==null && $expectedRevision<1)
            || ($draftId===null)!==($expectedRevision===null)) {
            throw new RuntimeException('Invalid private draft revision.');
        }
        $title=self::draftTitle($title);
        $summary=self::draftSummary($summary);
        $this->pdo->beginTransaction();
        try {
            // The creator profile is locked first so the site owner can
            // suspend writes immediately without a status-check race.
            $q=$this->pdo->prepare("SELECT c.id FROM ac_creator_profiles c JOIN ac_users u ON u.id=c.member_id
                JOIN ac_member_email_confirmations e ON e.user_id=u.id AND e.confirmed_at IS NOT NULL
                WHERE c.member_id=? AND c.status='enabled' AND u.role='member'
                    AND u.status IN ('pending','active') LIMIT 1 FOR UPDATE");
            $q->execute([$memberId]);
            $creatorId=(int)$q->fetchColumn();
            if ($creatorId<1) throw new RuntimeException('Creator workspace unavailable.');
            if ($draftId===null) {
                $q=$this->pdo->prepare('SELECT COUNT(*) FROM ac_creator_drafts WHERE creator_id=?');
                $q->execute([$creatorId]);
                if ((int)$q->fetchColumn()>=50) throw new RuntimeException('Private draft limit reached.');
                $q=$this->pdo->prepare("INSERT INTO ac_creator_drafts
                    (creator_id,title,summary,status,revision,created_at,updated_at)
                    VALUES (?,?,?,'private',1,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
                $q->execute([$creatorId,$title,$summary]);
                $id=(int)$this->pdo->lastInsertId();
            } else {
                $q=$this->pdo->prepare('SELECT revision,status FROM ac_creator_drafts WHERE id=? AND creator_id=? FOR UPDATE');
                $q->execute([$draftId,$creatorId]);
                $draft=$q->fetch(PDO::FETCH_ASSOC);
                if (!is_array($draft) || !in_array($draft['status'],['private','changes'],true)) {
                    throw new RuntimeException('Private draft not found.');
                }
                if ((int)$draft['revision']!==$expectedRevision) {
                    throw new RuntimeException('Draft changed; reload your workspace.');
                }
                $this->pdo->prepare("UPDATE ac_creator_drafts
                    SET title=?,summary=?,status='private',review_note='',
                        revision=revision+1,updated_at=UTC_TIMESTAMP()
                    WHERE id=? AND creator_id=?")->execute([$title,$summary,$draftId,$creatorId]);
                $id=$draftId;
            }
            $this->record($memberId,$creatorId,$draftId===null?'draft_created':'draft_edited');
            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function requireManager(int $actorId,bool $lock): void
    {
        if ($actorId<1) throw new RuntimeException('Local creator management permission required.');
        $sql="SELECT role FROM ac_users WHERE id=? AND status='active'";
        if ($lock) $sql.=' FOR UPDATE';
        $q=$this->pdo->prepare($sql);
        $q->execute([$actorId]);
        if (!RolePolicy::allows((string)$q->fetchColumn(),'members.manage')) {
            throw new RuntimeException('Local creator management permission required.');
        }
    }

    private function record(int $actorId,int $creatorId,string $action): void
    {
        // Audit is numeric-only, with no member email or private draft text.
        $q=$this->pdo->prepare('INSERT INTO ac_creator_actions
            (actor_id,creator_id,action,created_at) VALUES (?,?,?,UTC_TIMESTAMP())');
        $q->execute([$actorId,$creatorId,$action]);
    }
}
