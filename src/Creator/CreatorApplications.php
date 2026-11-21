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
 * Application intake and moderator triage only.
 * No creator role is granted; no age, identity, performer consent or payment
 * is inferred from profile text, declarations or moderator decisions.
 */
final class CreatorApplications
{
    public function __construct(private readonly PDO $pdo) {}

    public static function name(string $value): string
    {
        $value=trim($value);
        if (strlen($value)<2 || strlen($value)>80
            || preg_match('/[\x00-\x1f\x7f]/',$value)===1
            || preg_match('//u',$value)!==1) {
            throw new RuntimeException('Choose a creator display name of 2–80 bytes without control characters.');
        }
        return $value;
    }

    public static function introduction(string $value): string
    {
        $value=trim($value);
        if (strlen($value)<20 || strlen($value)>500
            || preg_match('/[\x00-\x08\x0b-\x1f\x7f]/',$value)===1
            || preg_match('//u',$value)!==1) {
            throw new RuntimeException('Describe your creator plans in 20–500 bytes without private documents.');
        }
        return $value;
    }

    public static function reason(string $value): string
    {
        $value=trim($value);
        if (strlen($value)<10 || strlen($value)>500
            || preg_match('/[\x00-\x08\x0b-\x1f\x7f]/',$value)===1
            || preg_match('//u',$value)!==1) {
            throw new RuntimeException('Enter a review explanation between 10 and 500 bytes.');
        }
        return $value;
    }

    /** @return array<string,mixed>|null */
    public function forMember(int $memberId): ?array
    {
        if ($memberId<1) return null;
        $q=$this->pdo->prepare("SELECT a.id,a.display_name,a.introduction,a.status,
            a.review_note,a.revision,a.updated_at
            FROM ac_creator_applications a JOIN ac_users u ON u.id=a.member_id
            WHERE a.member_id=? AND u.role='member' AND u.status IN ('pending','active') LIMIT 1");
        $q->execute([$memberId]);
        $item=$q->fetch(PDO::FETCH_ASSOC);
        return is_array($item)?$item:null;
    }

    /**
     * Original revision prevents conflicting submissions. Rejected intake is
     * final until independent admin review; no self-approval or reactivation.
     */
    public function submit(int $memberId, string $name, string $introduction, ?int $expectedRevision): int
    {
        if ($memberId<1 || ($expectedRevision!==null && $expectedRevision<1)) {
            throw new RuntimeException('Invalid application revision.');
        }
        $name=self::name($name);
        $introduction=self::introduction($introduction);
        $this->pdo->beginTransaction();
        try {
            // Lock member first; never trust a form-supplied email or member ID.
            $q=$this->pdo->prepare('SELECT role,status FROM ac_users WHERE id=? FOR UPDATE');
            $q->execute([$memberId]);
            $member=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($member) || $member['role']!=='member'
                || !in_array($member['status'],['pending','active'],true)
                || !(new EmailConfirmations($this->pdo))->verified($memberId)) {
                throw new RuntimeException('Confirm your member email before applying.');
            }
            $q=$this->pdo->prepare('SELECT id,status,revision FROM ac_creator_applications WHERE member_id=? FOR UPDATE');
            $q->execute([$memberId]);
            $prior=$q->fetch(PDO::FETCH_ASSOC);
            if ($prior===false) {
                if ($expectedRevision!==null) throw new RuntimeException('Application changed; reload and try again.');
                $q=$this->pdo->prepare("INSERT INTO ac_creator_applications
                    (member_id,display_name,introduction,status,review_note,revision,created_at,updated_at)
                    VALUES (?,? ,?,'pending','',1,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
                $q->execute([$memberId,$name,$introduction]);
                $id=(int)$this->pdo->lastInsertId();
                $event='submitted';
            } else {
                if ((int)$prior['revision']!==$expectedRevision) {
                    throw new RuntimeException('Application changed; reload and try again.');
                }
                if (!in_array($prior['status'],['pending','needs_changes'],true)) {
                    throw new RuntimeException('This application cannot be resubmitted.');
                }
                $id=(int)$prior['id'];
                $this->pdo->prepare("UPDATE ac_creator_applications
                    SET display_name=?,introduction=?,status='pending',review_note='',
                        revision=revision+1,updated_at=UTC_TIMESTAMP() WHERE id=?")
                    ->execute([$name,$introduction,$id]);
                $event='resubmitted';
            }
            $this->log($id,$memberId,$event);
            $this->pdo->commit();
            return $id;
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            if ($e->getCode()==='23000') {
                throw new RuntimeException('Application changed; reload and try again.',0,$e);
            }
            throw $e;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public function queue(int $moderatorId): array
    {
        $this->requireModerator($moderatorId);
        return $this->pdo->query("SELECT a.id,a.member_id,a.display_name,a.introduction,
            a.status,a.review_note,a.revision,a.updated_at
            FROM ac_creator_applications a ORDER BY a.updated_at DESC,a.id DESC LIMIT 50")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The only available decisions are to request changes or reject.
     * Never approve an applicant or mutate user role/status/age verification.
     */
    public function decide(int $moderatorId, int $applicationId, int $expectedRevision, string $action, string $note): void
    {
        if ($applicationId<1 || $expectedRevision<1
            || !in_array($action,['needs_changes','rejected'],true)) {
            throw new RuntimeException('Invalid creator review decision.');
        }
        $note=self::reason($note);
        $this->pdo->beginTransaction();
        try {
            $this->requireModerator($moderatorId,true);
            $q=$this->pdo->prepare('SELECT status,revision FROM ac_creator_applications WHERE id=? FOR UPDATE');
            $q->execute([$applicationId]);
            $prior=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($prior) || $prior['status']!=='pending') {
                throw new RuntimeException('Only pending creator applications can be reviewed.');
            }
            if ((int)$prior['revision']!==$expectedRevision) {
                throw new RuntimeException('Application changed; reload the review queue.');
            }
            $this->pdo->prepare("UPDATE ac_creator_applications
                SET status=?,review_note=?,revision=revision+1,updated_at=UTC_TIMESTAMP() WHERE id=?")
                ->execute([$action,$note,$applicationId]);
            $this->log($applicationId,$moderatorId,$action);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function requireModerator(int $moderatorId,bool $lock=false): void
    {
        if ($moderatorId<1) throw new RuntimeException('Moderator permission required.');
        $sql="SELECT role FROM ac_users WHERE id=? AND status='active'";
        if ($lock) $sql.=' FOR UPDATE';
        $q=$this->pdo->prepare($sql);
        $q->execute([$moderatorId]);
        if (!RolePolicy::allows((string)$q->fetchColumn(),'content.moderate')) {
            throw new RuntimeException('Moderator permission required.');
        }
    }

    private function log(int $applicationId,int $actorId,string $event): void
    {
        // No display names, application text, review notes, passwords, IDs
        // documents, or other sensitive content in the append-only action log.
        $q=$this->pdo->prepare('INSERT INTO ac_creator_application_events
            (application_id,actor_id,event,created_at) VALUES (?,?,?,UTC_TIMESTAMP())');
        $q->execute([$applicationId,$actorId,$event]);
    }
}
