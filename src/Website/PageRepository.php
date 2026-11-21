<?php

declare(strict_types=1);

namespace AdultCore\Website;

use PDO;
use RuntimeException;

/** Only plain-text page bodies; never store arbitrary HTML or PHP templates. */
final class PageRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public static function validate(string $slug, string $title, string $body, string $status): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $slug) !== 1
            || strlen($title) < 1 || strlen($title) > 190
            || strlen($body) > 30000 || !in_array($status, ['draft', 'published'], true)) {
            throw new RuntimeException('Invalid page fields. Use a lowercase slug, a title and up to 30 KB of plain text.');
        }
        if (trim($title) === '') {
            throw new RuntimeException('Page title cannot be blank.');
        }
    }

    /** Navigation is presentation only, never publication or authorization. */
    public static function validateNavigation(?bool $visible,?int $position): void
    {
        if ($position!==null && ($position<0 || $position>999)) {
            throw new RuntimeException('Navigation position must be between 0 and 999.');
        }
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->pdo->query('SELECT id, slug, title, status, show_in_menu, nav_position, updated_at FROM ac_pages ORDER BY updated_at DESC, id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Stable, bounded owner-only list (50/page). Page IDs are not authority;
     * each HTTP request still passes the site administrator role gate.
     * No drafts or archived records are ever exposed through PublicSite.
     *
     * @return array{pages:list<array<string,mixed>>,next:?int}
     */
    public function pageList(?int $before=null,?string $search=null,?string $status=null): array
    {
        if ($before!==null && $before<1) {
            throw new RuntimeException('Invalid page list cursor.');
        }
        // Validate BEFORE trim; PHP trim removes a leading/trailing NUL
        // and would accidentally accept an invalid input as empty search.
        $raw=$search??'';
        if (strlen($raw)>80 || preg_match('//u',$raw)!==1
            || preg_match('/[\x00-\x1f\x7f]/',$raw)===1) {
            throw new RuntimeException('Invalid page search term.');
        }
        $term=trim($raw);
        if ($status!==null && !in_array($status,['draft','published'],true)) {
            throw new RuntimeException('Invalid page status filter.');
        }
        $sql='SELECT id,slug,title,status,show_in_menu,nav_position,updated_at
            FROM ac_pages';
        $conditions=[];
        $bindings=[];
        if ($before!==null) {
            $conditions[]='id<?';
            $bindings[]=$before;
        }
        if ($term!=='') {
            // LOCATE treats input as a literal substring: wildcard
            // metacharacters do not alter the search.
            $conditions[]='(LOCATE(?,slug)>0 OR LOCATE(?,title)>0)';
            $bindings[]=$term;
            $bindings[]=$term;
        }
        if ($status!==null) {
            $conditions[]='status=?';
            $bindings[]=$status;
        }
        if ($conditions!==[]) $sql.=' WHERE '.implode(' AND ',$conditions);
        $q=$this->pdo->prepare($sql.' ORDER BY id DESC LIMIT 51');
        $q->execute($bindings);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $more=count($rows)>50;
        if ($more) array_pop($rows);
        $next=$more && $rows!==[] ? (int)end($rows)['id'] : null;
        return ['pages'=>$rows,'next'=>$next];
    }

    /** @return array<string,mixed>|null */
    public function byId(int $id): ?array
    {
        if ($id < 1) return null;
        $s = $this->pdo->prepare('SELECT id,slug,title,body,status,revision,show_in_menu,nav_position FROM ac_pages WHERE id=? LIMIT 1');
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function published(string $slug): ?array
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $slug) !== 1) return null;
        $s = $this->pdo->prepare("SELECT id,slug,title,body FROM ac_pages WHERE slug=? AND status='published' LIMIT 1");
        $s->execute([$slug]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> */
    public function publishedMenu(): array
    {
        // Existing installations keep their original menu until the owner
        // explicitly applies the additive m17 migration via Site setup.
        if (!PageNavigationSchema::installed($this->pdo)) {
            return $this->pdo->query("SELECT slug,title FROM ac_pages
                WHERE status='published' ORDER BY (slug='home') DESC,title LIMIT 30")
                ->fetchAll(PDO::FETCH_ASSOC);
        }
        return $this->pdo->query("SELECT slug,title FROM ac_pages
            WHERE status='published' AND show_in_menu=1
            ORDER BY (slug='home') DESC,nav_position ASC,title ASC,id ASC LIMIT 30")
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    private function historyReady(): void
    {
        if (!PageHistorySchema::installed($this->pdo)) {
            throw new RuntimeException('Apply the additive private page-history migration in Site setup before editing.');
        }
    }

    /**
     * Compose DB changes with a caller's existing Design Studio transaction.
     * Savepoints keep a failed history insert from leaving a partial page edit
     * even if a future outer caller catches the exception.
     */
    private function atomic(callable $operation): mixed
    {
        $nested=$this->pdo->inTransaction();
        if ($nested) $this->pdo->exec('SAVEPOINT ac_page_mutation');
        else $this->pdo->beginTransaction();
        try {
            $value=$operation();
            if ($nested) $this->pdo->exec('RELEASE SAVEPOINT ac_page_mutation');
            else $this->pdo->commit();
            return $value;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                if ($nested) {
                    $this->pdo->exec('ROLLBACK TO SAVEPOINT ac_page_mutation');
                    $this->pdo->exec('RELEASE SAVEPOINT ac_page_mutation');
                } else {
                    $this->pdo->rollBack();
                }
            }
            throw $e;
        }
    }

    /** Append only a committed, actual page version; no user-authored SQL. */
    private function recordHistory(int $pageId): void
    {
        $q=$this->pdo->prepare("INSERT INTO ac_page_history
            (page_id,revision,slug,title,body,status,show_in_menu,nav_position,saved_at)
            SELECT id,revision,slug,title,body,status,show_in_menu,nav_position,UTC_TIMESTAMP()
            FROM ac_pages WHERE id=?");
        $q->execute([$pageId]);
        if ($q->rowCount()!==1) throw new RuntimeException('Could not preserve the saved page version.');
    }

    /**
     * Private admin-only, bounded history list. Never query body into the
     * rendered table; restoring text requires a separate CSRF-protected POST.
     * @return list<array<string,mixed>>
     */
    public function history(int $pageId): array
    {
        $this->historyReady();
        if ($pageId<1) return [];
        $q=$this->pdo->prepare('SELECT revision,title,status,saved_at
            FROM ac_page_history WHERE page_id=? ORDER BY revision DESC LIMIT 20');
        $q->execute([$pageId]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Historical snapshots are never deleted, and older pages remain
     * reachable after 20+ edits. Query only display metadata, not body.
     * @return array{items:list<array<string,mixed>>,next:?int}
     */
    public function historyPage(int $pageId,?int $beforeRevision=null): array
    {
        $this->historyReady();
        if ($pageId<1 || ($beforeRevision!==null && $beforeRevision<1)) {
            throw new RuntimeException('Invalid page history cursor.');
        }
        $sql='SELECT revision,title,status,saved_at FROM ac_page_history
            WHERE page_id=?';
        $params=[$pageId];
        if ($beforeRevision!==null) {
            $sql.=' AND revision<?';
            $params[]=$beforeRevision;
        }
        $q=$this->pdo->prepare($sql.' ORDER BY revision DESC LIMIT 21');
        $q->execute($params);
        $rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $more=count($rows)>20;
        if ($more) array_pop($rows);
        return ['items'=>$rows,
            'next'=>$more && $rows!==[]?(int)$rows[count($rows)-1]['revision']:null];
    }

    /**
     * Exact private revision detail, scoped by BOTH page and version.
     * No public endpoint uses this method or exposes prior draft bodies.
     * @return array<string,mixed>|null
     */
    public function historyDetail(int $pageId,int $revision): ?array
    {
        $this->historyReady();
        if ($pageId<1 || $revision<1) return null;
        $q=$this->pdo->prepare('SELECT revision,slug,title,body,status,saved_at
            FROM ac_page_history WHERE page_id=? AND revision=? LIMIT 1');
        $q->execute([$pageId,$revision]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    /**
     * Restore only the old title/body, ALWAYS as a new unpublished draft.
     * Keep the current URL and menu flags; old public status is never restored.
     * Row locking + optimistic revision prevent stale saves and replay.
     */
    public function restoreTextAsDraft(int $pageId,int $expectedRevision,int $sourceRevision): int
    {
        $this->historyReady();
        if ($pageId<1 || $expectedRevision<1 || $sourceRevision<1
            || $sourceRevision >= $expectedRevision) {
            throw new RuntimeException('Invalid saved page revision.');
        }
        return $this->atomic(function () use ($pageId,$expectedRevision,$sourceRevision): int {
            $q=$this->pdo->prepare('SELECT slug,revision FROM ac_pages WHERE id=? FOR UPDATE');
            $q->execute([$pageId]);
            $current=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($current)) throw new RuntimeException('Page not found.');
            if ((int)$current['revision']!==$expectedRevision) {
                throw new RuntimeException('Page changed; reload before restoring.');
            }
            $q=$this->pdo->prepare('SELECT title,body FROM ac_page_history
                WHERE page_id=? AND revision=? LIMIT 1');
            $q->execute([$pageId,$sourceRevision]);
            $snapshot=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($snapshot)) throw new RuntimeException('Saved page revision not found.');
            self::validate((string)$current['slug'],(string)$snapshot['title'],(string)$snapshot['body'],'draft');
            $q=$this->pdo->prepare("UPDATE ac_pages SET
                title=?,body=?,status='draft',revision=revision+1,
                updated_at=UTC_TIMESTAMP() WHERE id=? AND revision=?");
            $q->execute([(string)$snapshot['title'],(string)$snapshot['body'],$pageId,$expectedRevision]);
            if ($q->rowCount()!==1) throw new RuntimeException('Page changed; reload before restoring.');
            $this->recordHistory($pageId);
            return $expectedRevision+1;
        });
    }

    /**
     * Owner-only operation invoked only after authenticated admin/CSRF gates.
     * Copy safe text into an unlisted UNPUBLISHED draft; do not copy media,
     * member entitlements, publication state, or the original revision number.
     */
    public function duplicateAsDraft(int $sourceId,int $expectedRevision,string $newSlug): int
    {
        $this->historyReady();
        if ($sourceId<1 || $expectedRevision<1) {
            throw new RuntimeException('Page revision required; reload before copying.');
        }
        if ($newSlug==='home') {
            throw new RuntimeException('The homepage URL slug is reserved.');
        }
        return $this->atomic(function () use ($sourceId,$expectedRevision,$newSlug): int {
            $q=$this->pdo->prepare('SELECT title,body,revision FROM ac_pages WHERE id=? FOR UPDATE');
            $q->execute([$sourceId]);
            $source=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($source)) throw new RuntimeException('Page not found.');
            if ((int)$source['revision']!==$expectedRevision) {
                throw new RuntimeException('Page changed; reload before copying.');
            }
            self::validate($newSlug,(string)$source['title'],(string)$source['body'],'draft');
            $q=$this->pdo->prepare("INSERT INTO ac_pages
                (slug,title,body,status,revision,show_in_menu,nav_position,updated_at,created_at)
                VALUES (?,?,?,'draft',1,0,100,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
            $q->execute([$newSlug,(string)$source['title'],(string)$source['body']]);
            $newId=(int)$this->pdo->lastInsertId();
            $this->recordHistory($newId);
            return $newId;
        });
    }

    public function save(int $id, string $slug, string $title, string $body, string $status,
                         ?int $expectedRevision=null, ?bool $showInMenu=null,
                         ?int $navPosition=null): void
    {
        $this->historyReady();
        self::validate($slug,$title,$body,$status);
        self::validateNavigation($showInMenu,$navPosition);
        if ($id===0 && $slug==='home') {
            throw new RuntimeException('The homepage URL slug is reserved.');
        }
        if ($id>0 && ($expectedRevision===null || $expectedRevision<1)) {
            throw new RuntimeException('Page revision required; reload before saving.');
        }
        if ($id===0 && $expectedRevision!==null) {
            throw new RuntimeException('New pages cannot supply an existing page revision.');
        }
        $this->atomic(function () use ($id,$slug,$title,$body,$status,
                                        $expectedRevision,$showInMenu,$navPosition): void {
            if ($id>0) {
                $q=$this->pdo->prepare('SELECT slug,revision FROM ac_pages WHERE id=? FOR UPDATE');
                $q->execute([$id]);
                $existing=$q->fetch(PDO::FETCH_ASSOC);
                if (!is_array($existing)) throw new RuntimeException('Page not found.');
                if (((string)$existing['slug']==='home') !== ($slug==='home')) {
                    throw new RuntimeException('The homepage URL slug is reserved.');
                }
                if ((int)$existing['revision']!==$expectedRevision) {
                    throw new RuntimeException('Page changed; reload before saving.');
                }
                $q=$this->pdo->prepare('UPDATE ac_pages SET
                    slug=?,title=?,body=?,status=?,revision=revision+1,
                    show_in_menu=COALESCE(?,show_in_menu),
                    nav_position=COALESCE(?,nav_position),
                    updated_at=UTC_TIMESTAMP() WHERE id=? AND revision=?');
                $q->execute([$slug,$title,$body,$status,
                    $showInMenu===null?null:($showInMenu?1:0),
                    $navPosition,$id,$expectedRevision]);
                if ($q->rowCount()!==1) {
                    throw new RuntimeException('Page changed; reload before saving.');
                }
                $this->recordHistory($id);
                return;
            }
            $q=$this->pdo->prepare('INSERT INTO ac_pages
                (slug,title,body,status,show_in_menu,nav_position,updated_at,created_at)
                VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
            $q->execute([$slug,$title,$body,$status,
                $showInMenu===null?1:($showInMenu?1:0),$navPosition??100]);
            $newId=(int)$this->pdo->lastInsertId();
            $this->recordHistory($newId);
        });
    }
}
