<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\UI\Layout;
use AdultCore\UI\Dashboard;

$checks = 0;
function uiCheck(bool $truth, string $name): void {
    global $checks;
    if (!$truth) throw new RuntimeException('UI check failed: ' . $name);
    $checks++;
}

$sample = Layout::site('Welcome <unsafe>', 'Friendly & respectful <script>alert(1)</script>', [
    ['slug' => 'about', 'title' => 'About & values'],
    ['slug' => '../admin', 'title' => 'Evil'],
    ['slug' => 'home', 'title' => 'Home'],
], 'A & B Studio', true);
uiCheck(str_contains($sample, '<main id="main-content"'), 'Accessible main region');
uiCheck(str_contains($sample, 'href="#main-content"'), 'Keyboard skip-link');
uiCheck(str_contains($sample, 'A &amp; B Studio'), 'Site name escaped');
uiCheck(str_contains($sample, '&lt;unsafe&gt;'), 'Page title escaped');
uiCheck(str_contains($sample, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'Body HTML escaped');
uiCheck(!str_contains($sample, '<script>alert(1)</script>'), 'No content script injection');
uiCheck(str_contains($sample, '/p/about'), 'Published menu link included');
uiCheck(!str_contains($sample, '../admin'), 'Invalid slug refused');
uiCheck(str_contains($sample, 'aria-current="page"'), 'Navigation current page indicated');
uiCheck(str_contains($sample, 'class="site-highlights"'), 'Homepage editorial feature blocks');
uiCheck(str_contains($sample, '<span>✧</span>S'), 'Website identity drives the decorative monogram');
uiCheck(str_contains($sample, 'href="/theme.css"'), 'Signed CSS theme hook preserved');

$secondary = Layout::site('About', 'Information', [], 'Example', false);
uiCheck(!str_contains($secondary, 'class="site-highlights"'), 'No duplicate homepage features on inner pages');
uiCheck(str_contains($secondary, 'site-container'), 'Responsive site container');
uiCheck(!str_contains($secondary, 'ADMIN PREVIEW'), 'Public renderer has no administrative preview banner');
$privatePreview=Layout::site('Draft <private>', 'Only moderators can read this <script>unsafe()</script>',
    [], 'Demo', false, [], true);
uiCheck(str_contains($privatePreview,'ADMIN PREVIEW — SAVED PAGE'),
    'Admin-saved page previews are visibly identified as nonpublic');
uiCheck(str_contains($privatePreview,'Draft &lt;private&gt;')
    && str_contains($privatePreview,'&lt;script&gt;unsafe()&lt;/script&gt;')
    && !str_contains($privatePreview,'<script>unsafe()</script>'),
    'Private preview renders escaped page copy with production layout');

$admin = Layout::admin('Dashboard', Dashboard::render('Site <x>', 'lite', 8,
    ['id'=>1,'email'=>'admin@example.test','role'=>'admin']), '/admin', str_repeat('a', 64));
uiCheck(str_contains($admin, 'class="admin-sidebar"'), 'Sidebar layout');
uiCheck(str_contains($admin, 'aria-label="Administrator sections"'), 'Named admin navigation');
uiCheck(str_contains($admin, 'aria-current="page"'), 'Active admin route');
uiCheck(str_contains($admin, 'action="/admin/logout"'), 'POST logout preserved');
uiCheck(str_contains($admin, 'name="csrf"'), 'Logout CSRF remains present');
uiCheck(str_contains($admin, 'Site &lt;x&gt;'), 'Admin site name escaped');
uiCheck(!str_contains($admin, 'Site <x>'), 'No admin site injection');
uiCheck(str_contains($admin, 'admin@example.test'), 'Real admin label');
uiCheck(str_contains($admin, '>8</h3>'), 'Real account total rendered');
uiCheck(str_contains($admin, 'payments, independent adult verification'), 'Not production-ready note visible');
uiCheck(str_contains($admin, 'href="/admin/moderation"'), 'Moderation navigation');
$moderator=Layout::admin('Moderation','<p>Safe</p>','/admin/moderation',str_repeat('a',64),'lite','moderator');
uiCheck(str_contains($moderator,'href="/admin/moderation"')
    && str_contains($moderator,'href="/admin/security"')
    && str_contains($moderator,'action="/admin/logout"'),
    'Restricted moderator workspace retains permitted review, MFA and logout');
uiCheck(!str_contains($moderator,'href="/admin/tiers"')
    && !str_contains($moderator,'href="/admin/license"')
    && !str_contains($moderator,'href="/admin/packages"')
    && !str_contains($moderator,'href="/admin/setup"')
    && !str_contains($moderator,'href="/admin/upgrade"'),
    'Moderator sidebar hides privileged business, license and site settings');
uiCheck(!str_contains($moderator,'PRO FEATURES')
    && !str_contains($moderator,'Buy Pro license'),
    'Moderator does not receive privileged edition controls');

$empty = Layout::admin('Packages', '<p>Content</p>', '/admin/packages', null);
uiCheck(!str_contains($empty, 'action="/admin/logout"'), 'No logout action without valid CSRF');
uiCheck(str_contains($empty, 'href="/admin/packages" aria-current="page"'), 'Packages highlighted');

$guest = Layout::guest('Sign in <unsafe>', '<p>Content</p>');
uiCheck(str_contains($guest, 'Sign in &lt;unsafe&gt;'), 'Guest title escaped');
uiCheck(!str_contains($guest, 'class="admin-sidebar"'), 'Guest view never shows authenticated nav');

$member = Layout::member('Join', '<p>Existing member form</p>');
uiCheck(str_contains($member, 'Existing member form'), 'Member form inserted');
uiCheck(str_contains($member, 'class="auth-panel"'), 'Unified member form styling');

$css = file_get_contents(dirname(__DIR__) . '/public/assets/style.css');
uiCheck(is_string($css) && str_contains($css, 'prefers-reduced-motion'), 'Reduced-motion preferences respected');
uiCheck(is_string($css) && str_contains($css, '@media(max-width:480px)'), 'Small-screen responsive grid');
uiCheck(is_string($css) && !str_contains($css, '@import'), 'No external stylesheet import');

$liteNav=\AdultCore\UI\Layout::admin('Dashboard','<p>Welcome</p>','/admin',null,'lite');
uiCheck(str_contains($liteNav,'Pro Version Only')&&str_contains($liteNav,'aria-disabled="true"'),'Lite admin displays disabled Pro-only controls');
uiCheck(str_contains($liteNav,'href="https://cajunwebpro.com"'),'Lite admin upgrade links to placeholder owner website');
uiCheck(str_contains($liteNav,'href="/admin/upgrade"'),'Lite admin offers an in-place edition upgrade workflow');
$proNav=\AdultCore\UI\Layout::admin('Dashboard','<p>Welcome</p>','/admin',null,'pro');
uiCheck(str_contains($proNav,'In development')&&!str_contains($proNav,'Pro Version Only'),'Pro admin shows not-yet-implemented features without upgrade pitch');
echo "Passed $checks UI design-system checks.\n";
