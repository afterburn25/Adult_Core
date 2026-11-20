<?php

declare(strict_types=1);

namespace AdultCore\Website;

use AdultCore\Database\Connection;
use PDO;
use Throwable;

/** Public pages only. This controller deliberately NEVER exposes private media. */
final class PublicSite
{
    public function __construct(private readonly string $root) {}

    public function handle(string $path, string $method): void
    {
        if ($method !== 'GET' && $method !== 'HEAD') {
            header('Allow: GET, HEAD'); http_response_code(405); return;
        }
        if ($path !== '/' && preg_match('#^/p/([a-z0-9][a-z0-9-]{0,79})$#D', $path, $m) !== 1) {
            http_response_code(404); $this->show('Page not found', 'There is no published page at this address.', []); return;
        }
        $slug = $path === '/' ? 'home' : $m[1];
        try {
            $pdo = Connection::open($this->root);
            // A safe public holding page is shown until the additive site migration is applied.
            $migration = $pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
            $migration->execute([SiteSchema::VERSION]);
            if ((int) $migration->fetchColumn() === 0) {
                http_response_code(503);
                $this->show('Opening soon', 'The website is being configured. Please check back later.', []);
                return;
            }
            $repo = new PageRepository($pdo);
            $page = $repo->published($slug);
            $menu = $repo->publishedMenu();
            if ($page === null) {
                http_response_code($slug === 'home' ? 503 : 404);
                $this->show($slug === 'home' ? 'Opening soon' : 'Page not found',
                    $slug === 'home' ? 'The website is being prepared.' : 'This page has not been published.', $menu);
                return;
            }
            $this->show((string) $page['title'], (string) $page['body'], $menu);
        } catch (Throwable) {
            http_response_code(503);
            $this->show('Temporarily unavailable', 'The site is currently undergoing maintenance.', []);
        }
    }

    /** @param list<array<string,mixed>> $menu */
    private function show(string $title, string $body, array $menu): void
    {
        $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $site = 'Adult Core';
        $links = '<a href="/">Home</a>';
        foreach ($menu as $page) {
            if ($page['slug'] === 'home') continue;
            $links .= '<a href="/p/' . $esc((string) $page['slug']) . '">' . $esc((string) $page['title']) . '</a>';
        }
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $esc($title) . ' · ' . $site . '</title><link rel="stylesheet" href="/assets/style.css">'
            . '<link rel="stylesheet" href="/theme.css"></head><body><div class="shell"><header class="topbar">'
            . '<div class="mark">AC</div><a href="/">Adult Core</a><nav class="site-nav">' . $links . '</nav>'
            . '<a class="site-join" href="/join">Membership</a></header><main><article class="panel site-page">'
            . '<p class="eyebrow">PRIVATE MEMBERSHIP COMMUNITY</p><h1>' . $esc($title) . '</h1>'
            . '<div class="site-copy">' . nl2br($esc($body), false) . '</div></article></main>'
            . '<footer>Powered by Adult Core Lite / Pro</footer></div></body></html>';
    }
}
