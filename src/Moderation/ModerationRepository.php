<?php

declare(strict_types=1);

namespace AdultCore\Moderation;

use PDO;
use RuntimeException;
use Throwable;

/** Quarantine-first review. No approve or publish method exists. */
final class ModerationRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public static function validateTitle(string $title): string
    {
        $title=trim($title);
        if ($title==='' || strlen($title)>190 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$title)) {
            throw new RuntimeException('Use a title between 1 and 190 bytes.');
        }
        return $title;
    }
    public static function validateNote(string $note): string
    {
        $note=trim($note);
        if (strlen($note)<10 || strlen($note)>500 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$note)) {
            throw new RuntimeException('Enter a review explanation between 10 and 500 bytes.');
        }
        return $note;
    }
    public static function validateReportReason(string $reason): string
    {
        $reason=trim($reason);
        if (strlen($reason)<10 || strlen($reason)>1000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$reason)) {
            throw new RuntimeException('Enter a report reason between 10 and 1000 bytes.');
        }
        return $reason;
    }
    private function requireModerator(int $id): void
    {
        if ($id<1) throw new RuntimeException('Administrator privileges required.');
        $q=$this->pdo->prepare("SELECT role FROM ac_users WHERE id=? AND status='active' LIMIT 1");
        $q->execute([$id]);
        if (!in_array($q->fetchColumn(),['admin','owner'],true)) throw new RuntimeException('Administrator privileges required.');
    }
    private function audit(int $contentId, ?int $reportId, int $actorId, string $action, string $note): void
    {
        $q=$this->pdo->prepare('INSERT INTO ac_moderation_actions (content_id,report_id,actor_id,action,note,created_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())');
        $q->execute([$contentId,$reportId,$actorId,$action,$note]);
    }
    public function createDraft(string $title, int $actorId): int
    {
        $title=self::validateTitle($title);
        $this->requireModerator($actorId);
        $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->prepare("INSERT INTO ac_content (title,status,visibility,review_status,requires_consent,created_at) VALUES (?,'draft','private','pending',1,UTC_TIMESTAMP())");
            $q->execute([$title]);
            $id=(int)$this->pdo->lastInsertId();
            $this->audit($id,null,$actorId,'draft_created','New private draft created; publishing is not enabled.');
            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }
    public function contentQueue(): array
    {
        return $this->pdo->query("SELECT c.id,c.title,c.status,c.review_status,c.visibility,
            (SELECT COUNT(*) FROM ac_content_reports r WHERE r.content_id=c.id AND r.status='open') AS open_reports
            FROM ac_content c ORDER BY c.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    }
    public function reportQueue(): array
    {
        return $this->pdo->query("SELECT r.id,r.content_id,r.reporter_id,r.reason,r.status,r.created_at,c.title
            FROM ac_content_reports r JOIN ac_content c ON c.id=r.content_id
            ORDER BY (r.status='open') DESC,r.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    }
    public function auditTrail(int $contentId): array
    {
        if ($contentId<1) return [];
        $q=$this->pdo->prepare('SELECT action,actor_id,note,created_at FROM ac_moderation_actions WHERE content_id=? ORDER BY id DESC LIMIT 50');
        $q->execute([$contentId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public function submitReport(int $memberId,int $contentId,string $reason): void
    {
        $reason=self::validateReportReason($reason);
        if ($memberId<1 || $contentId<1) throw new RuntimeException('Invalid report.');
        $q=$this->pdo->prepare("SELECT id FROM ac_users WHERE id=? AND role='member' AND status IN ('pending','active') LIMIT 1");
        $q->execute([$memberId]);
        if (!$q->fetchColumn()) throw new RuntimeException('Member account is not eligible to report.');
        $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->prepare('SELECT id FROM ac_content WHERE id=? FOR UPDATE');
            $q->execute([$contentId]);
            if (!$q->fetchColumn()) { $this->pdo->commit();return; }
            $q=$this->pdo->prepare('SELECT COUNT(*) FROM ac_content_reports WHERE content_id=? AND reporter_id=? AND created_at >= (UTC_TIMESTAMP() - INTERVAL 1 DAY)');
            $q->execute([$contentId,$memberId]);
            if ((int)$q->fetchColumn()>0) { $this->pdo->commit();return; }
            $q=$this->pdo->prepare("INSERT INTO ac_content_reports (content_id,reporter_id,reason,status,created_at) VALUES (?,?,?,'open',UTC_TIMESTAMP())");
            $q->execute([$contentId,$memberId,$reason]);
            $reportId=(int)$this->pdo->lastInsertId();
            $this->pdo->prepare("UPDATE ac_content SET status='quarantined',review_status='pending' WHERE id=?")->execute([$contentId]);
            $this->pdo->prepare("UPDATE ac_media SET review_status='pending' WHERE content_id=?")->execute([$contentId]);
            $this->audit($contentId,$reportId,$memberId,'member_reported','Content automatically quarantined after a member report.');
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }
    public function moderateContent(int $contentId,int $adminId,string $decision,string $note): void
    {
        if ($contentId<1 || !in_array($decision,['quarantine','reject'],true)) throw new RuntimeException('Unsupported review action.');
        $note=self::validateNote($note);
        $this->requireModerator($adminId);
        $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->prepare('SELECT id FROM ac_content WHERE id=? FOR UPDATE');
            $q->execute([$contentId]);
            if (!$q->fetchColumn()) throw new RuntimeException('Content not found.');
            $status=$decision==='reject'?'rejected':'quarantined';
            $review=$decision==='reject'?'rejected':'pending';
            $this->pdo->prepare('UPDATE ac_content SET status=?,review_status=? WHERE id=?')->execute([$status,$review,$contentId]);
            $this->pdo->prepare('UPDATE ac_media SET review_status=? WHERE content_id=?')->execute([$review,$contentId]);
            $this->audit($contentId,null,$adminId,$decision,$note);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }
    public function resolveReport(int $reportId,int $adminId,string $decision,string $note): void
    {
        if ($reportId<1 || !in_array($decision,['resolved','dismissed'],true)) throw new RuntimeException('Unsupported report resolution.');
        $note=self::validateNote($note);
        $this->requireModerator($adminId);
        $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->prepare('SELECT content_id,status FROM ac_content_reports WHERE id=? FOR UPDATE');
            $q->execute([$reportId]);
            $record=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($record) || $record['status']!=='open') throw new RuntimeException('Report not open.');
            $this->pdo->prepare('UPDATE ac_content_reports SET status=? WHERE id=?')->execute([$decision,$reportId]);
            $this->audit((int)$record['content_id'],$reportId,$adminId,'report_'.$decision,$note);
            // Closing a report does not reinstate quarantined content.
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }
}
