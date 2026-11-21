<?php

declare(strict_types=1);

namespace AdultCore\Moderation;

use AdultCore\Media\PrivateMedia;
use PDO;
use RuntimeException;
use Throwable;

/** Quarantine-first moderation. There is deliberately NO approve/publish endpoint. */
final class ModerationRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public static function validateTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '' || strlen($title) > 190 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $title)) {
            throw new RuntimeException('Use a title between 1 and 190 bytes.');
        }
        return $title;
    }

    public static function validateNote(string $note): string
    {
        $note = trim($note);
        if (strlen($note) < 10 || strlen($note) > 500 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $note)) {
            throw new RuntimeException('Enter a review explanation between 10 and 500 bytes.');
        }
        return $note;
    }

    public static function validateReportReason(string $reason): string
    {
        $reason = trim($reason);
        if (strlen($reason) < 10 || strlen($reason) > 1000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $reason)) {
            throw new RuntimeException('Enter a report reason between 10 and 1000 bytes.');
        }
        return $reason;
    }

    public function createDraft(string $title, int $actorId): int
    {
        $title = self::validateTitle($title);
        $this->requireModerator($actorId);
        $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->prepare("INSERT INTO ac_content (title,status,visibility,review_status,requires_consent,created_at) VALUES (?,'draft','private','pending',1,UTC_TIMESTAMP())");
            $q->execute([$title]);
            $id = (int) $this->pdo->lastInsertId();
            $this->audit($id, null, $actorId, 'draft_created', 'New private draft created; publishing is not enabled.');
            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Stage an actual HTTP-uploaded image in a strictly private, unpublished
     * draft. Its DB record stays pending; no member/media route can serve it.
     * All actor and draft checks also run for direct service callers.
     */
    public function attachPrivateImage(
        int $contentId, int $actorId, string $temporaryPath,
        bool $uploaded, PrivateMedia $storage,
    ): int {
        if ($contentId < 1 || !$uploaded || !is_uploaded_file($temporaryPath)
            || !is_file($temporaryPath) || is_link($temporaryPath)) {
            throw new RuntimeException('Invalid private draft image upload.');
        }
        $this->requireModerator($actorId);
        $this->pdo->beginTransaction();
        $storedKey=null;
        try {
            $q=$this->pdo->prepare('SELECT status,visibility,review_status FROM ac_content WHERE id=? FOR UPDATE');
            $q->execute([$contentId]);
            $content=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($content) || $content['status']!=='draft'
                || $content['visibility']!=='private'
                || $content['review_status']!=='pending') {
                throw new RuntimeException('Only private, unreviewed drafts accept images.');
            }
            $count=$this->pdo->prepare('SELECT COUNT(*) FROM ac_media WHERE content_id=?');
            $count->execute([$contentId]);
            if ((int)$count->fetchColumn()>=40) {
                throw new RuntimeException('Private draft image limit reached.');
            }
            $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
            if (!is_string($mime) || !PrivateMedia::validMime($mime)) {
                throw new RuntimeException('Image type not allowed.');
            }
            $storedKey=$storage->storeVerifiedImage($temporaryPath, $uploaded);
            $q=$this->pdo->prepare("INSERT INTO ac_media
                (content_id,private_storage_key,mime_type,review_status)
                VALUES (?,?,?,'pending')");
            $q->execute([$contentId,$storedKey,$mime]);
            $id=(int)$this->pdo->lastInsertId();
            $this->audit($contentId,null,$actorId,'image_staged',
                'Private image stored for review; upload did not approve or publish content.');
            $this->pdo->commit();
            return $id;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            // If the DB/audit write failed after moving the upload, remove
            // its unreferenced private blob whenever safe to do so.
            if ($storedKey!==null) {
                $file=$storage->locate($storedKey);
                if ($file!==null) @unlink($file['path']);
            }
            throw $e;
        }
    }

    /**
     * Declare the number of depicted performers before requesting independent
     * evidence. Does not mark any evidence as verified or permit publication.
     * A roster can only be created once; subsequent changes need a formal review.
     */
    public function declarePerformers(int $contentId, int $adminId, int $count): array
    {
        if ($contentId < 1 || $count < 1 || $count > 20) throw new RuntimeException('Declare between 1 and 20 performers.');
        $this->requireModerator($adminId);
        $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->prepare('SELECT status,expected_performers FROM ac_content WHERE id=? FOR UPDATE');
            $q->execute([$contentId]);
            $content=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($content) || !in_array($content['status'], ['draft','quarantined'], true)
                || (int)$content['expected_performers'] !== 0) {
                throw new RuntimeException('Content is not an eligible unreviewed draft.');
            }
            $q=$this->pdo->prepare('SELECT COUNT(*) FROM ac_performer_records WHERE content_id=?');
            $q->execute([$contentId]);
            if ((int)$q->fetchColumn() !== 0) throw new RuntimeException('Performer roster already exists.');
            $this->pdo->prepare("UPDATE ac_content SET expected_performers=?,review_status='pending' WHERE id=?")
                ->execute([$count,$contentId]);
            $created=[];
            for ($i=0;$i<$count;$i++) {
                $key=bin2hex(random_bytes(20));
                $q=$this->pdo->prepare("INSERT INTO ac_performer_records (content_id,private_record_key,review_status) VALUES (?,?,'pending')");
                $q->execute([$contentId,$key]);
                $created[]=(int)$this->pdo->lastInsertId();
            }
            $this->audit($contentId,null,$adminId,'roster_declared',sprintf('Declared %d performer records; evidence is pending.', $count));
            $this->pdo->commit();
            return $created;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public function performerQueue(): array
    {
        return $this->pdo->query('SELECT p.id,p.content_id,p.review_status,p.verified_at,p.consent_at,c.title
            FROM ac_performer_records p JOIN ac_content c ON c.id=p.content_id ORDER BY p.id DESC LIMIT 100')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function contentQueue(): array
    {
        return $this->pdo->query("SELECT c.id,c.title,c.status,c.review_status,c.visibility,c.expected_performers,
            (SELECT COUNT(*) FROM ac_performer_records p WHERE p.content_id=c.id) AS recorded_performers,
            (SELECT COUNT(*) FROM ac_performer_records p WHERE p.content_id=c.id
                AND p.review_status='approved' AND p.verified_at IS NOT NULL
                AND p.consent_at IS NOT NULL) AS evidenced_performers,
            (SELECT COUNT(*) FROM ac_media m WHERE m.content_id=c.id AND m.review_status='pending') AS pending_images,
            (SELECT COUNT(*) FROM ac_content_reports r WHERE r.content_id=c.id AND r.status='open') AS open_reports
            FROM ac_content c ORDER BY c.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function reportQueue(): array
    {
        return $this->pdo->query("SELECT r.id,r.content_id,r.reporter_id,r.reason,r.status,r.created_at,c.title
            FROM ac_content_reports r JOIN ac_content c ON c.id=r.content_id
            ORDER BY (r.status='open') DESC,r.id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    public function auditTrail(int $contentId): array
    {
        if ($contentId < 1) return [];
        $q=$this->pdo->prepare('SELECT action,actor_id,note,created_at FROM ac_moderation_actions WHERE content_id=? ORDER BY id DESC LIMIT 50');
        $q->execute([$contentId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Called by authenticated member. An unknown ID produces the same external result. */
    public function submitReport(int $memberId, int $contentId, string $reason): void
    {
        $reason = self::validateReportReason($reason);
        if ($memberId < 1 || $contentId < 1) throw new RuntimeException('Invalid report.');
        $q = $this->pdo->prepare("SELECT id FROM ac_users WHERE id=? AND role='member' AND status IN ('pending','active') LIMIT 1");
        $q->execute([$memberId]);
        if (!$q->fetchColumn()) throw new RuntimeException('Member account is not eligible to report.');
        $this->pdo->beginTransaction();
        try {
            $q=$this->pdo->prepare('SELECT id FROM ac_content WHERE id=? FOR UPDATE');
            $q->execute([$contentId]);
            if (!$q->fetchColumn()) { $this->pdo->commit(); return; }
            // Serialize reports for this content row; allow at most one per member in 24h.
            $q=$this->pdo->prepare('SELECT COUNT(*) FROM ac_content_reports WHERE content_id=? AND reporter_id=? AND created_at >= (UTC_TIMESTAMP() - INTERVAL 1 DAY)');
            $q->execute([$contentId,$memberId]);
            if ((int)$q->fetchColumn() > 0) { $this->pdo->commit(); return; }
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

    public function moderateContent(int $contentId, int $adminId, string $decision, string $note): void
    {
        if (!in_array($decision, ['quarantine','reject'], true) || $contentId < 1) throw new RuntimeException('Unsupported review action.');
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

    public function resolveReport(int $reportId, int $adminId, string $decision, string $note): void
    {
        if (!in_array($decision, ['resolved','dismissed'], true) || $reportId < 1) throw new RuntimeException('Unsupported report resolution.');
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
            // Crucially, closing a report NEVER restores quarantined media.
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function requireModerator(int $id): void
    {
        if ($id < 1) throw new RuntimeException('Administrator privileges required.');
        $q=$this->pdo->prepare("SELECT role FROM ac_users WHERE id=? AND status='active' LIMIT 1");
        $q->execute([$id]);
        if (!in_array($q->fetchColumn(), ['admin','owner'], true)) throw new RuntimeException('Administrator privileges required.');
    }

    private function audit(int $contentId, ?int $reportId, int $actorId, string $action, string $note): void
    {
        $q=$this->pdo->prepare('INSERT INTO ac_moderation_actions (content_id,report_id,actor_id,action,note,created_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())');
        $q->execute([$contentId,$reportId,$actorId,$action,$note]);
    }
}
