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
    public function pageList(?int $before=null): array
    {
        if ($before!==null && $before<1) {
            throw new RuntimeException('Invalid page list cursor.');
        }
        $sql='SELECT id,slug,title,status,show_in_menu,nav_position,updated_at
            FROM ac_pages';
        if ($before!==null) $sql.=' WHERE id<?';
        $q=$this->pdo->prepare($sql.' ORDER BY id DESC LIMIT 51');
        $q->execute($before===null?[]:[$before]);
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

    /**
     * Owner-only operation invoked only after the admin controller session,
     * role and CSRF gates: copy safe page text to an UNPUBLISHED new slug.
     * Row lock + revision compare avoids cloning a stale source. Navigation
     * starts hidden, and neither media nor membership records are touched.
     */
    public function duplicateAsDraft(int $sourceId,int $expectedRevision,string $newSlug): int
    {
        if ($sourceId<1 || $expectedRevision<1) {
            throw new RuntimeException('Page revision required; reload before copying.');
        }
        $this->pdo->beginTransaction();
        try {
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
            $this->pdo->commit();
            return $newId;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function save(int $id, string $slug, string $title, string $body, string $status,
                         ?int $expectedRevision=null, ?bool $showInMenu=null,
                         ?int $navPosition=null): void
    {
        self::validate($slug, $title, $body, $status);
        self::validateNavigation($showInMenu,$navPosition);
        if ($id > 0) {
            if ($expectedRevision===null || $expectedRevision<1) {
                throw new RuntimeException('Page revision required; reload before saving.');
            }
            // Compare-and-swap is atomic even across separate admin sessions
            // and within one second, unlike updated_at timestamp comparisons.
            $s=$this->pdo->prepare('UPDATE ac_pages SET
                slug=?,title=?,body=?,status=?,revision=revision+1,
                show_in_menu=COALESCE(?,show_in_menu),
                nav_position=COALESCE(?,nav_position),
                updated_at=UTC_TIMESTAMP() WHERE id=? AND revision=?');
            $s->execute([$slug,$title,$body,$status,
                $showInMenu===null?null:($showInMenu?1:0),
                $navPosition,$id,$expectedRevision]);
            if ($s->rowCount()!==1) {
                if ($this->byId($id)===null) throw new RuntimeException('Page not found.');
                throw new RuntimeException('Page changed; reload before saving.');
            }
            return;
        }
        if ($expectedRevision!==null) {
            throw new RuntimeException('New pages cannot supply an existing page revision.');
        }
        $s = $this->pdo->prepare('INSERT INTO ac_pages
            (slug,title,body,status,show_in_menu,nav_position,updated_at,created_at)
            VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
        $s->execute([$slug,$title,$body,$status,
            $showInMenu===null?1:($showInMenu?1:0),$navPosition??100]);
    }
}
