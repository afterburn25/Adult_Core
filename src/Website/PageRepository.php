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

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->pdo->query('SELECT id, slug, title, status, updated_at FROM ac_pages ORDER BY updated_at DESC, id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed>|null */
    public function byId(int $id): ?array
    {
        if ($id < 1) return null;
        $s = $this->pdo->prepare('SELECT id,slug,title,body,status FROM ac_pages WHERE id=? LIMIT 1');
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
        return $this->pdo->query("SELECT slug,title FROM ac_pages WHERE status='published' ORDER BY (slug='home') DESC, title LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function save(int $id, string $slug, string $title, string $body, string $status): void
    {
        self::validate($slug, $title, $body, $status);
        if ($id > 0) {
            $s = $this->pdo->prepare('UPDATE ac_pages SET slug=?,title=?,body=?,status=?,updated_at=UTC_TIMESTAMP() WHERE id=?');
            $s->execute([$slug, $title, $body, $status, $id]);
            if ($s->rowCount() === 0 && $this->byId($id) === null) {
                throw new RuntimeException('Page not found.');
            }
            return;
        }
        $s = $this->pdo->prepare('INSERT INTO ac_pages (slug,title,body,status,updated_at,created_at) VALUES (?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
        $s->execute([$slug, $title, $body, $status]);
    }
}
