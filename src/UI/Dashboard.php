<?php

declare(strict_types=1);

namespace AdultCore\UI;

use AdultCore\Auth\AdminSession;

/** Verified, database-backed metrics only: never invent revenue or activity. */
final class Dashboard
{
    /** @param array{id:int,email:string,role:string} $admin */
    /** @param array{pending_members?:?int,draft_pages?:?int,open_reports?:?int,submitted_plans?:?int} $work */
    public static function render(string $siteName, string $edition, int $accounts, array $admin, array $work=[]): string
    {
        $e = static fn(string $value): string => Layout::escape($value);
        $editionLabel = strtoupper($edition);
        return '<div class="dashboard-hero"><div><p class="eyebrow">CONTROL CENTER <span class="eyebrow-sep">/</span> OVERVIEW</p>'
            . '<h1>Welcome to your workspace<span class="title-period">.</span></h1>'
            . '<p class="dashboard-subtitle">Manage ' . $e($siteName) . ' from one thoughtfully organized place.</p></div>'
            . '<a class="btn btn-primary" href="/admin/page">Create new page <span aria-hidden="true">↗</span></a></div>'
            . '<div class="dashboard-banner"><div class="banner-glow"></div><div class="banner-content">'
            . '<span class="banner-overline">YOUR CREATIVE SPACE</span><h2>Everything in its place.</h2>'
            . '<p>Your website, members, content reviews, and extensions — all within easy reach.</p>'
            . '<div class="banner-actions"><a href="/admin/pages">Manage website <span aria-hidden="true">↗</span></a>'
            . '<a href="/admin/moderation">Open moderation <span aria-hidden="true">↗</span></a></div></div>'
            . '<div class="banner-orb" aria-hidden="true"><div class="banner-orb-core">✦</div></div></div>'
            . '<div class="section-heading"><div><span class="section-overline">AT A GLANCE</span><h2>Workspace overview</h2></div>'
            . '<span class="section-note">Live installation details</span></div>'
            . '<div class="admin-stats">'
            . '<section class="panel metric-card"><div class="metric-label">INSTALLED EDITION <span class="metric-symbol">↗</span></div>'
            . '<h3>' . $e($editionLabel) . '</h3><p>Capability-verified deployment</p><span class="metric-accent accent-purple"></span></section>'
            . '<section class="panel metric-card"><div class="metric-label">REGISTERED ACCOUNTS <span class="metric-symbol">✧</span></div>'
            . '<h3>' . $accounts . '</h3><p>All user roles combined</p><span class="metric-accent accent-teal"></span></section>'
            . '<section class="panel metric-card"><div class="metric-label">SESSION SECURITY <span class="metric-symbol">◇</span></div>'
            . '<h3 class="metric-words">Protected</h3><p>Role checks &amp; CSRF enabled</p><span class="metric-accent accent-gold"></span></section>'
            . '<section class="panel metric-card"><div class="metric-label">SIGNED IN AS <span class="metric-symbol">◉</span></div>'
            . '<h3 class="metric-account">' . $e($admin['email']) . '</h3><p>Role: ' . $e($admin['role']) . '</p><span class="metric-accent accent-pink"></span></section>'
            . '</div><section class="panel owner-work-summary">'
            . '<h2>Local work overview</h2>'
            . '<p class="muted">Read-only site records, not age verification, payment approval, or publication permission. '
            . 'A pending member account is not independently age-verified.</p>'
            . '<div class="owner-work-grid">'
            . self::workCount('Pending local members',$work['pending_members']??null,'/admin/members?status=pending')
            . self::workCount('Unpublished website drafts',$work['draft_pages']??null,'/admin/pages?status=draft')
            . self::workCount('Open safety reports',$work['open_reports']??null,'/admin/moderation')
            . self::workCount('Submitted private text plans',$work['submitted_plans']??null,'/admin/creator-review')
            . '</div></section>'
            . '<div class="section-heading section-heading-tools"><div><span class="section-overline">QUICK ACCESS</span><h2>Your tools</h2></div></div>'
            . '<div class="tool-grid">'
            . self::tool('01', 'Pages & publishing', 'Create and edit your public-facing website pages.', '/admin/pages', 'Open page editor')
            . self::tool('02', 'Members', 'View registered accounts and pending status.', '/admin/members', 'Manage members')
            . self::tool('03', 'Safety & moderation', 'Review reports, drafts, and content decisions.', '/admin/moderation', 'Review queue')
            . self::tool('04', 'Themes & add-ons', 'Manage compatible, publisher-signed packages.', '/admin/packages', 'Explore packages')
            . '</div><div class="dashboard-note"><span class="note-icon">i</span><p><strong>Development environment.</strong> '
            . 'Live payments, independent adult verification, full media publishing, and Pro streaming are not available. '
            . 'Do not deploy this build as a production membership platform.</p></div>';
    }

    private static function workCount(string $title,?int $count,string $url): string
    {
        // Null never means zero; it indicates a feature was not migrated.
        $shown=$count===null?'Not configured':(string)max(0,$count);
        return '<a class="owner-work-item" href="'.$url.'"><strong>'
            .Layout::escape($shown).'</strong><span>'.Layout::escape($title)
            .'</span></a>';
    }

    private static function tool(string $number, string $title, string $description, string $url, string $cta): string
    {
        return '<a class="tool-card" href="' . $url . '"><div class="tool-number">' . $number . ' / EXPLORE</div>'
            . '<h3>' . Layout::escape($title) . '</h3><p>' . Layout::escape($description) . '</p>'
            . '<span class="tool-cta">' . Layout::escape($cta) . ' <span aria-hidden="true">↗</span></span></a>';
    }
}
