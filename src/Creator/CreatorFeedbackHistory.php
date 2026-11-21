<?php

declare(strict_types=1);

namespace AdultCore\Creator;

use AdultCore\Auth\RolePolicy;
use PDO;
use RuntimeException;

/**
 * Bounded read-only local feedback timeline. Never stores a creator's media,
 * ID evidence, private draft text, or plaintext membership credentials.
 */
final class CreatorFeedbackHistory
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * @return list<array<string,mixed>>
     * Only an enabled, email-confirmed local creator can read their feedback.
     */
    public function forMember(int $memberId): array
    {
        if ($memberId<1) return [];
        $q=$this->pdo->prepare("SELECT h.id,h.draft_id,h.submitted_revision,h.decision,
            h.feedback,h.reviewed_at
            FROM ac_creator_feedback_history h
            JOIN ac_creator_drafts d ON d.id=h.draft_id
            JOIN ac_creator_profiles c ON c.id=d.creator_id
            JOIN ac_users u ON u.id=c.member_id
            JOIN ac_member_email_confirmations e ON e.user_id=u.id AND e.confirmed_at IS NOT NULL
            WHERE u.id=? AND u.role='member' AND u.status IN ('pending','active')
                AND c.status='enabled'
            ORDER BY h.id DESC LIMIT 100");
        $q->execute([$memberId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<array<string,mixed>>
     * Site owner/admin can review retained private feedback, even after
     * suspending the local creator. Moderators never access this owner route.
     */
    public function forOwner(int $actorId,int $creatorId): array
    {
        if ($actorId<1 || $creatorId<1) throw new RuntimeException('Local owner review permission required.');
        $actor=$this->pdo->prepare("SELECT role FROM ac_users WHERE id=? AND status='active'");
        $actor->execute([$actorId]);
        if (!RolePolicy::allows((string)$actor->fetchColumn(),'members.manage')) {
            throw new RuntimeException('Local owner review permission required.');
        }
        $q=$this->pdo->prepare("SELECT h.id,h.draft_id,h.reviewer_id,h.submitted_revision,
            h.decision,h.feedback,h.reviewed_at
            FROM ac_creator_feedback_history h
            JOIN ac_creator_drafts d ON d.id=h.draft_id
            WHERE d.creator_id=?
            ORDER BY h.id DESC LIMIT 100");
        $q->execute([$creatorId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
