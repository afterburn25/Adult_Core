<?php

declare(strict_types=1);

namespace AdultCore\Creator;

use AdultCore\Auth\RolePolicy;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Local text-only creator draft handoff: submit, request changes, reject.
 * IMPORTANT: no "approve", no publish, no media, no age or consent flags.
 */
final class CreatorDraftReview
{
    public function __construct(private readonly PDO $pdo) {}

    public static function note(string $value): string
    {
        $value=trim($value);
        if (strlen($value)<10 || strlen($value)>500
            || preg_match('//u',$value)!==1
            || preg_match('/[\x00-\x08\x0b-\x1f\x7f]/',$value)===1) {
            throw new RuntimeException('Enter 10–500 bytes of private review feedback.');
        }
        return $value;
    }

    /**
     * Creator can submit ONLY their own editable planning text.
     * Changing status here never makes anything publicly visible.
     */
    public function submit(int $memberId,int $draftId,int $expectedRevision): void
    {
        if ($memberId<1 || $draftId<1 || $expectedRevision<1) {
            throw new RuntimeException('Invalid creator review request.');
        }
        $this->pdo->beginTransaction();
        try {
            // Same locking order as LocalCreators::saveDraft():
            // creator profile first, then matching private draft.
            $q=$this->pdo->prepare("SELECT c.id FROM ac_creator_profiles c
                JOIN ac_users u ON u.id=c.member_id
                JOIN ac_member_email_confirmations e
                    ON e.user_id=u.id AND e.confirmed_at IS NOT NULL
                WHERE c.member_id=? AND c.status='enabled' AND u.role='member'
                    AND u.status IN ('pending','active') LIMIT 1 FOR UPDATE");
            $q->execute([$memberId]);
            $creatorId=(int)$q->fetchColumn();
            if ($creatorId<1) throw new RuntimeException('Creator workspace unavailable.');
            $q=$this->pdo->prepare('SELECT revision,status FROM ac_creator_drafts
                WHERE id=? AND creator_id=? FOR UPDATE');
            $q->execute([$draftId,$creatorId]);
            $draft=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($draft) || $draft['status']!=='private') {
                throw new RuntimeException('Only editable private plans can be submitted.');
            }
            if ((int)$draft['revision']!==$expectedRevision) {
                throw new RuntimeException('Draft changed; reload your workspace.');
            }
            $this->pdo->prepare("UPDATE ac_creator_drafts
                SET status='submitted',revision=revision+1,
                    review_note='',updated_at=UTC_TIMESTAMP()
                WHERE id=? AND creator_id=?")->execute([$draftId,$creatorId]);
            $this->event($draftId,$memberId,'submitted');
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Withdraw an undecided private submission for further editing.
     * This never deletes audit/evidence and cannot undo moderator decisions.
     */
    public function withdraw(int $memberId,int $draftId,int $expectedRevision): void
    {
        if ($memberId<1 || $draftId<1 || $expectedRevision<1) {
            throw new RuntimeException('Invalid creator review request.');
        }
        $this->pdo->beginTransaction();
        try {
            // Same locking order as submit/saveDraft: active creator then draft.
            $q=$this->pdo->prepare("SELECT c.id FROM ac_creator_profiles c
                JOIN ac_users u ON u.id=c.member_id
                JOIN ac_member_email_confirmations e
                    ON e.user_id=u.id AND e.confirmed_at IS NOT NULL
                WHERE c.member_id=? AND c.status='enabled' AND u.role='member'
                    AND u.status IN ('pending','active') LIMIT 1 FOR UPDATE");
            $q->execute([$memberId]);
            $creatorId=(int)$q->fetchColumn();
            if ($creatorId<1) throw new RuntimeException('Creator workspace unavailable.');
            $q=$this->pdo->prepare('SELECT revision,status FROM ac_creator_drafts
                WHERE id=? AND creator_id=? FOR UPDATE');
            $q->execute([$draftId,$creatorId]);
            $draft=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($draft) || $draft['status']!=='submitted') {
                throw new RuntimeException('Only undecided submitted plans can be withdrawn.');
            }
            if ((int)$draft['revision']!==$expectedRevision) {
                throw new RuntimeException('Draft changed; reload your workspace.');
            }
            $this->pdo->prepare("UPDATE ac_creator_drafts SET
                status='private',review_note='',revision=revision+1,
                updated_at=UTC_TIMESTAMP()
                WHERE id=? AND creator_id=?")->execute([$draftId,$creatorId]);
            $this->event($draftId,$memberId,'withdrawn');
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> Backward-compatible first page. */
    public function queue(int $moderatorId): array
    {
        return $this->queuePage($moderatorId,null)['drafts'];
    }

    /**
     * Authenticated keyset paging for the local submitted-text queue.
     *
     * Cursor encodes only the previous review timestamp + draft ID.
     * It is NOT an authorization token: requireModerator checks the live
     * database role on every page request, and SQL binds all cursor fields.
     *
     * @return array{drafts:list<array<string,mixed>>,next:?string}
     */
    public function queuePage(int $moderatorId,?string $after=null): array
    {
        $this->requireModerator($moderatorId,false);
        $cutoff=self::parseQueueCursor($after);
        $sql="SELECT d.id,d.creator_id,d.title,d.summary,d.status,
            d.revision,d.updated_at,c.display_name,c.status AS creator_status
            FROM ac_creator_drafts d
            JOIN ac_creator_profiles c ON c.id=d.creator_id
            WHERE d.status='submitted'";
        $values=[];
        if ($cutoff!==null) {
            $sql.=' AND (d.updated_at>? OR (d.updated_at=? AND d.id>?))';
            $values=[$cutoff['stamp'],$cutoff['stamp'],$cutoff['id']];
        }
        $q=$this->pdo->prepare($sql.' ORDER BY d.updated_at ASC,d.id ASC LIMIT 51');
        $q->execute($values);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $hasMore=count($rows)>50;
        if ($hasMore) array_pop($rows);
        $next=null;
        if ($hasMore && $rows!==[]) {
            $last=end($rows);
            // DB-provided values only, never an arbitrary user path.
            $next=rtrim(strtr(base64_encode((string)$last['updated_at'].'|'.(int)$last['id']),
                '+/','-_'),'=');
        }
        return ['drafts'=>$rows,'next'=>$next];
    }

    /**
     * Decode bounded cursor without trusting the browser's timestamp or ID.
     * Invalid/altered cursors fail closed without ever altering review state.
     *
     * @return array{stamp:string,id:int}|null
     */
    private static function parseQueueCursor(?string $cursor): ?array
    {
        if ($cursor===null) return null;
        if (strlen($cursor)<2 || strlen($cursor)>100
            || preg_match('/^[A-Za-z0-9_-]+$/D',$cursor)!==1) {
            throw new RuntimeException('Invalid private review queue cursor.');
        }
        $b64=strtr($cursor,'-_','+/');
        $decoded=base64_decode($b64.str_repeat('=',(4-strlen($b64)%4)%4),true);
        if (!is_string($decoded)
            || preg_match('/^([0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2})\|([1-9][0-9]{0,17})$/D',
                $decoded,$match)!==1) {
            throw new RuntimeException('Invalid private review queue cursor.');
        }
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$match[1],
            new \DateTimeZone('UTC'));
        if ($date===false || $date->format('Y-m-d H:i:s')!==$match[1]) {
            throw new RuntimeException('Invalid private review queue cursor.');
        }
        $id=filter_var($match[2],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($id===false) throw new RuntimeException('Invalid private review queue cursor.');
        return ['stamp'=>$match[1],'id'=>(int)$id];
    }

    /**
     * Moderators can only ask for revisions or reject a submitted plan.
     * Unlike public content moderation there is intentionally NO approval.
     */
    public function decide(int $moderatorId,int $draftId,int $expectedRevision,
                           string $decision,string $feedback): void
    {
        if ($moderatorId<1 || $draftId<1 || $expectedRevision<1
            || !in_array($decision,['changes','rejected'],true)) {
            throw new RuntimeException('Invalid creator review decision.');
        }
        $feedback=self::note($feedback);
        $this->pdo->beginTransaction();
        try {
            $this->requireModerator($moderatorId,true);
            $q=$this->pdo->prepare('SELECT revision,status FROM ac_creator_drafts
                WHERE id=? FOR UPDATE');
            $q->execute([$draftId]);
            $draft=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($draft) || $draft['status']!=='submitted') {
                throw new RuntimeException('Only submitted private plans can be reviewed.');
            }
            if ((int)$draft['revision']!==$expectedRevision) {
                throw new RuntimeException('Draft changed; reload the review queue.');
            }
            $this->pdo->prepare('UPDATE ac_creator_drafts
                SET status=?,review_note=?,revision=revision+1,
                    updated_at=UTC_TIMESTAMP() WHERE id=?')
                ->execute([$decision,$feedback,$draftId]);
            // Snapshot feedback in the same transaction as the decision.
            // The numeric event log stays free of private review text.
            $history=$this->pdo->prepare('INSERT INTO ac_creator_feedback_history
                (draft_id,reviewer_id,submitted_revision,decision,feedback,reviewed_at)
                VALUES (?,?,?,?,?,UTC_TIMESTAMP())');
            $history->execute([$draftId,$moderatorId,$expectedRevision,$decision,$feedback]);
            $this->event($draftId,$moderatorId,$decision);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Minimal event metadata. Feedback stays in the private draft row. */
    private function event(int $draftId,int $actorId,string $action): void
    {
        $q=$this->pdo->prepare('INSERT INTO ac_creator_draft_events
            (draft_id,actor_id,action,created_at)
            VALUES (?,?,?,UTC_TIMESTAMP())');
        $q->execute([$draftId,$actorId,$action]);
    }

    private function requireModerator(int $moderatorId,bool $lock): void
    {
        if ($moderatorId<1) throw new RuntimeException('Moderation permission required.');
        $sql="SELECT role FROM ac_users WHERE id=? AND status='active' LIMIT 1";
        if ($lock) $sql.=' FOR UPDATE';
        $q=$this->pdo->prepare($sql);
        $q->execute([$moderatorId]);
        if (!RolePolicy::allows((string)$q->fetchColumn(),'content.moderate')) {
            throw new RuntimeException('Moderation permission required.');
        }
    }
}
