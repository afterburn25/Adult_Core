<?php

declare(strict_types=1);

namespace AdultCore\Website;

use AdultCore\Database\Connection;
use AdultCore\UI\Layout;
use AdultCore\Design\DesignRepository;
use AdultCore\Design\DesignSchema;
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
            $siteName = (string) ($pdo->query("SELECT setting_value FROM ac_settings WHERE setting_key = 'site_name'")->fetchColumn() ?: 'Adult Core');
            $design = [];
            // Design schema is optional until /admin/setup has been applied. Never expose private images.
            $d = $pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
            $d->execute([DesignSchema::VERSION]);
            if ((int)$d->fetchColumn() > 0) {
                $designRepo = new DesignRepository($pdo);
                $design = $designRepo->current();
                $assetId = (int)($design['hero_asset_id'] ?? 0);
                if ($slug === 'home' && $assetId > 0 && $designRepo->publicAsset($assetId) !== null) {
                    $design['public_asset_id'] = $assetId;
                }
            }
            $this->show((string) $page['title'], (string) $page['body'], $menu, $siteName, $slug === 'home', $design, $slug);
        } catch (Throwable) {
            http_response_code(503);
            $this->show('Temporarily unavailable', 'The site is currently undergoing maintenance.', []);
        }
    }

    /** @param list<array<string,mixed>> $menu */
    private function show(string $title, string $body, array $menu, string $siteName = 'Adult Core',
                          bool $homepage = false, array $design = [], ?string $currentSlug = null): void
    {
        echo Layout::site($title, $body, $menu, $siteName, $homepage, $design, false, $currentSlug);
    }
}
