<?php

declare(strict_types=1);

namespace AdultCore\Membership;

use DateTimeImmutable;
use PDO;

/** Private authorization predicate; no client-side membership decisions. */
final class Entitlements
{
    public function __construct(private readonly PDO $pdo) {}

    public function canView(int $userId, int $contentId, ?DateTimeImmutable $now = null): bool
    {
        if ($userId < 1 || $contentId < 1) return false;
        $stmt = $this->pdo->prepare('SELECT c.status, c.visibility, c.tier_id, u.role, u.status AS user_status '
            . 'FROM ac_content c JOIN ac_users u ON u.id = :user_id WHERE c.id = :content_id LIMIT 1');
        $stmt->execute(['user_id'=>$userId, 'content_id'=>$contentId]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($record) || $record['status'] !== 'published' || $record['user_status'] !== 'active') return false;
        if (in_array($record['role'], ['owner', 'admin'], true)) return true;
        if ($record['visibility'] === 'members' && $record['tier_id'] === null) return true;
        if ($record['visibility'] !== 'paid' || $record['tier_id'] === null) return false;
        $now ??= new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $q = $this->pdo->prepare('SELECT 1 FROM ac_memberships WHERE user_id = :uid AND tier_id = :tid '
            . 'AND status = :status AND starts_at <= :now_start AND expires_at > :now_end LIMIT 1');
        $q->execute(['uid'=>$userId,'tid'=>$record['tier_id'],'status'=>'active','now_start'=>$now->format('Y-m-d H:i:s'),'now_end'=>$now->format('Y-m-d H:i:s')]);
        return (bool) $q->fetchColumn();
    }
}
