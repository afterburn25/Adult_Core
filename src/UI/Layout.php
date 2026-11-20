<?php

declare(strict_types=1);

namespace AdultCore\UI;

/**
 * Server-rendered, no-script UI shells. All caller-owned text must be escaped.
 * UI helpers do not perform routing, authentication, permission checks or migrations.
 */
final class Layout
{
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function head(string $title, string $class, string $brand = 'Adult Core'): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="theme-color" content="#0b1020">'
            . '<title>' . self::escape($title) . ' · ' . self::escape($brand) . '</title>'
            . '<link rel="stylesheet" href="/assets/style.css">'
            . '<link rel="stylesheet" href="/theme.css">'
            . ($class === 'public-site' || str_starts_with($class, 'public-site ') ? '<link rel="stylesheet" href="/design.css">' : '')
            . '</head><body class="' . $class . '">'
            . '<a class="skip-link" href="#main-content">Skip to content</a>';
    }

    private static function brand(bool $admin = false, string $siteName = 'Adult Core'): string
    {
        $destination = $admin ? '/admin' : '/';
        $name = $admin ? 'Adult Core' : (trim($siteName) ?: 'Adult Core');
        $words = preg_split('/\s+/u', $name) ?: ['Adult', 'Core'];
        $first = array_shift($words) ?: 'Adult';
        $rest = implode(' ', $words);
        $initials = strtoupper(substr($first, 0, 1) . ($rest !== '' ? substr($rest, 0, 1) : ''));
        return '<a class="brand" href="' . $destination . '" aria-label="' . self::escape($admin ? 'Adult Core administration' : $name . ' home') . '">'
            . '<span class="brand-emblem" aria-hidden="true"><span>' . self::escape($initials) . '</span></span>'
            . '<span class="brand-type"><strong>' . self::escape($first) . ($rest === '' ? '' : '<span>' . self::escape($rest) . '</span>') . '</strong><small>'
            . ($admin ? 'CONTROL CENTER' : 'THE MEMBERSHIP EXPERIENCE')
            . '</small></span></a>';
    }

    /**
     * @param list<array<string,mixed>> $menu Published, DB-backed navigation only.
     */
    public static function site(string $title, string $body, array $menu, string $siteName = 'Adult Core', bool $homepage = false, array $design = []): string
    {
        $siteName = trim($siteName) ?: 'Adult Core';
        $nav = '<a class="' . ($homepage ? 'is-active' : '') . '" href="/"' . ($homepage ? ' aria-current="page"' : '') . '>Home</a>';
        foreach ($menu as $page) {
            $slug = (string) ($page['slug'] ?? '');
            if ($slug === 'home' || preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $slug) !== 1) {
                continue;
            }
            $nav .= '<a href="/p/' . self::escape($slug) . '"' . (!$homepage && ($page['title'] ?? '') === $title ? ' aria-current="page"' : '') . '>'
                . self::escape((string) ($page['title'] ?? 'Page')) . '</a>';
        }
        $intro = $homepage ? 'A space that feels entirely yours' : 'Discover more';
        $siteWords = preg_split('/\s+/u', $siteName) ?: ['Adult','Core'];
        $siteFirst = strtoupper(substr((string) ($siteWords[0] ?? 'A'), 0, 1));
        $siteLast = strtoupper(substr((string) ($siteWords[count($siteWords)-1] ?? 'C'), 0, 1));
        $theme = (string)($design['theme_key'] ?? 'noir-gold');
        if (!array_key_exists($theme, \AdultCore\Design\ThemeCatalog::THEMES)) $theme = 'noir-gold';
        $side = ($design['image_side'] ?? 'right') === 'left' ? 'left' : 'right';
        $size = in_array(($design['image_size'] ?? 'large'), ['small','medium','large'], true) ? (string)($design['image_size'] ?? 'large') : 'large';
        $assetId = (int)($design['public_asset_id'] ?? 0);
        $art = $homepage && $assetId > 0
            ? '<div class="hero-image-frame"><div class="cutout-positioner"><img class="design-portrait" loading="eager" src="/design/asset/' . $assetId . '" alt="Decorative homepage image"></div></div>'
            : '<div class="art-orbit art-orbit-one"></div><div class="art-orbit art-orbit-two"></div>'
                . '<div class="art-glass"><div class="art-symbol">' . self::escape($siteFirst) . '<span>✧</span>' . self::escape($siteLast) . '</div><div class="art-caption">CURATED BY YOU <span>YOUR SPACE</span></div></div>'
                . '<div class="art-spark art-spark-one">✦</div><div class="art-spark art-spark-two">✧</div>';
        $content = '<div class="site-hero-grid"><div class="site-hero-content">'
            . '<div class="hero-kicker"><span class="kicker-line"></span> ' . self::escape($siteName) . ' <span class="kicker-diamond">✦</span> YOUR PRIVATE SPACE</div>'
            . '<h1>' . self::escape($title) . '</h1>'
            . '<div class="site-copy">' . nl2br(self::escape($body), false) . '</div>'
            . '<div class="hero-actions"><a class="btn btn-primary" href="/join">Explore membership <span aria-hidden="true">↗</span></a>'
            . '<a class="btn btn-outline" href="/members/login">Member sign in</a></div>'
            . '<p class="hero-footnote"><span class="status-light" aria-hidden="true"></span> ' . self::escape($intro) . ' · Private by design</p>'
            . '</div><div class="hero-art" aria-hidden="true">'
            . $art . '</div></div>';
        $secondary = '<section class="site-highlights" aria-label="Site information">'
            . '<div><span class="highlight-number">01</span><strong>Your space</strong><p>A thoughtfully designed home for your community.</p></div>'
            . '<div><span class="highlight-number">02</span><strong>Member accounts</strong><p>Dedicated account access with privacy-first safeguards.</p></div>'
            . '<div><span class="highlight-number">03</span><strong>Made personal</strong><p>Pages and styling curated by your site administrator.</p></div></section>';
        return self::head($title, 'public-site theme-' . $theme . ' image-' . $side . ' image-size-' . $size, $siteName)
            . '<div class="site-frame"><header class="site-header"><div class="site-container site-header-inner">'
            . self::brand(false, $siteName) . '<nav class="site-nav" aria-label="Main navigation">' . $nav . '</nav>'
            . '<div class="header-actions"><a class="header-login" href="/members/login">Sign in</a>'
            . '<a class="site-join" href="/join">Get access <span aria-hidden="true">↗</span></a></div></div></header>'
            . '<main id="main-content" class="site-main"><section class="site-hero site-container" aria-label="Welcome">' . $content . '</section>'
            . ($homepage ? '<div class="site-container">' . $secondary . '</div>' : '')
            . '</main><footer class="site-footer"><div class="site-container footer-inner"><span>© ' . date('Y')
            . ' ' . self::escape($siteName) . '</span><span>Powered by Adult Core · Self-hosted Lite / Pro</span></div></footer></div></body></html>';
    }

    public static function member(string $title, string $content): string
    {
        return self::head($title, 'member-site')
            . '<div class="auth-frame"><header class="auth-header">' . self::brand() . '<a href="/" class="auth-back">← Back to website</a></header>'
            . '<main id="main-content" class="auth-main"><div class="auth-panel">'
            . '<div class="auth-ornament" aria-hidden="true">✦</div><p class="eyebrow">MEMBER EXPERIENCE</p>'
            . '<h1>' . self::escape($title) . '</h1>' . $content
            . '</div><p class="auth-aside">A private space, handled with care.</p></main>'
            . '<footer class="auth-footer">Adult Core · Self-hosted membership</footer></div></body></html>';
    }

    public static function guest(string $title, string $content): string
    {
        return self::head($title, 'admin-guest')
            . '<div class="auth-frame"><header class="auth-header">' . self::brand(true)
            . '<a href="/" class="auth-back">← View website</a></header>'
            . '<main id="main-content" class="auth-main admin-auth"><div class="auth-panel">'
            . '<div class="auth-ornament" aria-hidden="true">✦</div>' . $content
            . '</div><p class="auth-aside">Authorized administrators only · Protected access</p></main>'
            . '<footer class="auth-footer">Adult Core · Secure administration</footer></div></body></html>';
    }

    /**
     * This method is called only by authenticated admin routes. The caller remains
     * responsible for validating the session, role and CSRF before dispatch.
     */
    public static function admin(string $title, string $content, string $currentPath = '/admin', ?string $csrf = null): string
    {
        $links = [
            ['url' => '/admin', 'label' => 'Overview', 'icon' => 'overview'],
            ['url' => '/admin/pages', 'label' => 'Pages', 'icon' => 'pages'],
            ['url' => '/admin/design', 'label' => 'Design studio', 'icon' => 'puzzle'],
            ['url' => '/admin/license', 'label' => 'License', 'icon' => 'shield'],
            ['url' => '/admin/members', 'label' => 'Members', 'icon' => 'members'],
            ['url' => '/admin/moderation', 'label' => 'Moderation', 'icon' => 'shield'],
            ['url' => '/admin/packages', 'label' => 'Add-ons & themes', 'icon' => 'puzzle'],
            ['url' => '/admin/setup', 'label' => 'Setup', 'icon' => 'settings'],
        ];
        $nav = '';
        foreach ($links as $link) {
            $active = $currentPath === $link['url'] || ($currentPath === '/admin/page' && $link['url'] === '/admin/pages');
            $nav .= '<a class="admin-nav-link' . ($active ? ' is-current' : '') . '" href="' . $link['url'] . '"'
                . ($active ? ' aria-current="page"' : '') . '>' . self::icon($link['icon']) . '<span>' . self::escape($link['label']) . '</span>'
                . ($active ? '<span class="current-dot" aria-hidden="true"></span>' : '') . '</a>';
        }
        $logout = is_string($csrf) && preg_match('/^[a-f0-9]{64}$/D', $csrf) === 1
            ? '<form method="post" action="/admin/logout" class="logout-form"><input type="hidden" name="csrf" value="' . self::escape($csrf) . '">'
                . '<button type="submit" class="admin-logout">' . self::icon('logout') . '<span>Sign out</span></button></form>'
            : '';
        return self::head($title, 'admin-site')
            . '<div class="admin-app"><aside class="admin-sidebar" aria-label="Administrator navigation">'
            . '<div class="sidebar-brand">' . self::brand(true) . '</div>'
            . '<div class="sidebar-group-label">WORKSPACE</div><nav class="admin-nav" aria-label="Administrator sections">' . $nav . '</nav>'
            . '<div class="sidebar-bottom"><div class="sidebar-edition"><span class="status-light"></span><div><strong>Development preview</strong>'
            . '<small>Lite / Pro architecture</small></div></div>' . $logout . '</div></aside>'
            . '<div class="admin-workspace"><header class="admin-topbar"><div class="admin-breadcrumb">Workspace <span>/</span> <strong>' . self::escape($title) . '</strong></div>'
            . '<div class="admin-toolbar"><span class="toolbar-status"><span class="status-light"></span> Secure workspace</span>'
            . '<a class="btn btn-topbar" href="/" target="_blank" rel="noopener noreferrer">View site ' . self::icon('external') . '</a></div></header>'
            . '<main id="main-content" class="admin-main">' . $content . '</main>'
            . '<footer class="admin-footer"><span>Adult Core <span class="footer-divider">/</span> Creator control center</span><span>Self-hosted · Development build</span></footer>'
            . '</div></div></body></html>';
    }

    private static function icon(string $key): string
    {
        $paths = [
            'overview' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
            'pages' => '<path d="M8 3h7l5 5v12a1 1 0 0 1-1 1H8a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3Z"/><path d="M14 3v6h6M9 13h6M9 17h7"/>',
            'members' => '<circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2M17 5a3 3 0 0 1 0 6M17 14a5 5 0 0 1 4 5v1"/>',
            'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>',
            'puzzle' => '<path d="M8 3h3a2 2 0 0 0 4 0h3v5a2 2 0 0 1 0 4v7h-5a2 2 0 0 1-4 0H5v-5a2 2 0 0 0 0-4V5a2 2 0 0 1 3-2Z"/>',
            'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3m0 14v3M2 12h3m14 0h3M5 5l2.1 2.1m9.8 9.8L19 19M5 19l2.1-2.1m9.8-9.8L19 5"/>',
            'logout' => '<path d="M10 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h5M14 7l5 5-5 5M19 12H9"/>',
            'external' => '<path d="M13 4h7v7M20 4l-9 9M20 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h5"/>',
        ];
        return '<svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.65" stroke-linecap="round" stroke-linejoin="round">'
            . ($paths[$key] ?? '') . '</svg>';
    }
}
