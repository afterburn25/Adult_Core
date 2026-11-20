<?php

declare(strict_types=1);

namespace AdultCore\Membership;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

/** Default-deny private content authorization; never trust member-supplied tier IDs. */
final class Entitlements
{
    public function __construct(private readonly PDO $pdo) {}

    public function canView(int $userId, int $contentId, ?DateTimeImmutable $now = null): bool
    {
        if ($userId < 1 || $contentId < 1) return false;
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $when = $now->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('SELECT c.status,c.visibility,c.tier_id,c.review_status,c.requires_consent,u.role,u.status AS user_status '
            . 'FROM ac_content c JOIN ac_users u ON u.id = :user_id WHERE c.id = :content_id LIMIT 1');
        $stmt->execute(['user_id'=>$userId, 'content_id'=>$contentId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($record) || $record['status'] !== 'published' || $record['review_status'] !== 'approved'
            || $record['user_status'] !== 'active') return false;

        // Open reports deny even otherwise valid media entitlements.
        $open=$this->pdo->prepare("SELECT 1 FROM ac_content_reports WHERE content_id=? AND status='open' LIMIT 1");
        $open->execute([$contentId]);
        if ($open->fetchColumn()) return false;
        if ((int)$record['requires_consent'] === 1) {
            // A content item requiring performer consent must have approved verification evidence.
            // Production publishing must also ensure EVERY depicted performer is represented.
            $q=$this->pdo->prepare("SELECT COUNT(*) FROM ac_performer_records WHERE content_id=? AND review_status='approved' AND verified_at IS NOT NULL AND consent_at IS NOT NULL");
            $q->execute([$contentId]);
            if ((int)$q->fetchColumn() < 1) return false;
            $q=$this->pdo->prepare("SELECT COUNT(*) FROM ac_performer_records WHERE content_id=? AND (review_status<>'approved' OR verified_at IS NULL OR consent_at IS NULL)");
            $q->execute([$contentId]);
            if ((int)$q->fetchColumn() !== 0) return false;
        }
        if (in_array($record['role'], ['owner', 'admin'], true)) return true;
        if ($record['role'] !== 'member') return false;
        $q = $this->pdo->prepare('SELECT 1 FROM ac_age_verifications WHERE user_id=? AND verified_at <= ? '
            . 'AND (expires_at IS NULL OR expires_at > ?) LIMIT 1');
        $q->execute([$userId,$when,$when]);
        if (!$q->fetchColumn()) return false;
        if ($record['visibility'] === 'members' && $record['tier_id'] === null) return true;
        if ($record['visibility'] !== 'paid' || $record['tier_id'] === null) return false;
        $q = $this->pdo->prepare('SELECT 1 FROM ac_memberships m JOIN ac_membership_tiers t ON t.id=m.tier_id '
            . 'WHERE m.user_id=? AND m.tier_id=? AND t.is_active=1 '
            . "AND m.status='active' AND m.starts_at<=? AND m.expires_at>? LIMIT 1");
        $q->execute([$userId,$record['tier_id'],$when,$when]);
        return (bool)$q->fetchColumn();
    }
}
