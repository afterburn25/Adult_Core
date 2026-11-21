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

    /** @return list<array<string,mixed>> Pending local planning text only. */
    public function queue(int $moderatorId): array
    {
        $this->requireModerator($moderatorId,false);
        $q=$this->pdo->query("SELECT d.id,d.creator_id,d.title,d.summary,d.status,
            d.revision,d.updated_at,c.display_name,c.status AS creator_status
            FROM ac_creator_drafts d
            JOIN ac_creator_profiles c ON c.id=d.creator_id
            WHERE d.status='submitted'
            ORDER BY d.updated_at ASC,d.id ASC LIMIT 50");
        return $q->fetchAll(PDO::FETCH_ASSOC);
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
