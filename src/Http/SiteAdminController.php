<?php

declare(strict_types=1);

namespace AdultCore\Http;

use AdultCore\Auth\AdminSession;
use AdultCore\Auth\AdminMfaSchema;
use AdultCore\Auth\StaffSchema;
use AdultCore\Creator\CreatorSchema;
use AdultCore\Auth\RolePolicy;
use AdultCore\Database\Connection;
use AdultCore\Website\PageRepository;
use AdultCore\Website\SiteSchema;
use AdultCore\Member\MemberSchema;
use AdultCore\Member\MemberEmailSchema;
use AdultCore\Member\PasswordRecoverySchema;
use AdultCore\Membership\MembershipSchema;
use AdultCore\Moderation\ModerationSchema;
use AdultCore\Verification\VerificationSchema;
use AdultCore\UI\Layout;
use AdultCore\Design\DesignSchema;
use AdultCore\Design\CutoutPlacement;
use PDO;
use Throwable;
use RuntimeException;

/** Admin-only setup and CMS for plain-text public pages; does not handle adult media. */
final class SiteAdminController
{
    public function __construct(private readonly string $root) {}

    /** @param array{id:int,email:string,role:string} $admin */
    public function handle(string $path, string $method, array $admin): void
    {
        if (!RolePolicy::allows($admin['role'], 'site.settings.manage')) {
            $this->render(403, 'Access denied', '<p>Permission denied.</p>'); return;
        }
        if (!in_array($method, ['GET', 'POST'], true)) {
            header('Allow: GET, POST'); $this->render(405, 'Invalid method', '<p>Method not allowed.</p>'); return;
        }
        if ($method === 'POST' && !AdminSession::csrfValid($_POST['csrf'] ?? null)) {
            $this->render(403, 'Invalid token', '<p>Reload the form and try again.</p>'); return;
        }
        try {
            $pdo = Connection::open($this->root);
            if ($path === '/admin/setup') { $this->setup($pdo, $method); return; }
            if (!$this->schemaReady($pdo)) {
                $this->render(503, 'Setup required', '<p>The site schema is not yet installed. <a href="/admin/setup">Complete site setup</a>.</p>');
                return;
            }
            if ($path === '/admin/page/preview') {
                if ($method!=='GET') {
                    header('Allow: GET');
                    $this->render(405,'Method not allowed','<p>Preview is read-only.</p>');
                    return;
                }
                $this->preview($pdo);
                return;
            }
            if ($path === '/admin/pages') { $this->pages($pdo); return; }
            if ($path === '/admin/page') { $this->edit($pdo, $method); return; }
            if ($path === '/admin/members') { $this->members($pdo); return; }
            $this->render(404, 'Not found', '<p>Unknown administrator page.</p>');
        } catch (Throwable $e) {
            $this->render(503, 'Action unavailable', '<p>' . self::e($e instanceof RuntimeException ? $e->getMessage() : 'The database action failed.') . '</p>');
        }
    }

    private function schemaReady(PDO $pdo): bool
    {
        $q = $pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
        $q->execute([SiteSchema::VERSION]);
        return (int) $q->fetchColumn() > 0;
    }

    private function setup(PDO $pdo, string $method): void
    {
        if ($method === 'POST') {
            // Additive DDL only. A partly-applied migration can be safely re-run.
            SiteSchema::migrate($pdo);
            AdminMfaSchema::migrate($pdo);
            DesignSchema::migrate($pdo);
            CutoutPlacement::migrate($pdo);
            MemberSchema::migrate($pdo);
            StaffSchema::migrate($pdo);
            MemberEmailSchema::migrate($pdo);
            CreatorSchema::migrate($pdo);
            PasswordRecoverySchema::migrate($pdo);
            MembershipSchema::migrate($pdo);
            ModerationSchema::migrate($pdo);
            VerificationSchema::migrate($pdo);
            header('Location: /admin/pages?result=setup', true, 303);
            return;
        }
        $status = $this->schemaReady($pdo) ? 'Installed' : 'Not installed';
        $html = '<p class="intro">This installs additional non-destructive website and member-account tables. Back up your existing database first. No public adult content is enabled.</p>'
            . '<p>Current website schema: <strong>' . self::e($status) . '</strong></p>'
            . '<form method="post" action="/admin/setup" class="install-form">'
            . '<input type="hidden" name="csrf" value="' . self::e(AdminSession::csrf()) . '">'
            . '<button class="install-button" type="submit">Apply additive migrations</button></form>';
        $this->render(200, 'Site setup', $html);
    }

    /**
     * Read-only preview of a SAVED page, including unpublished drafts.
     * This route remains under the existing admin session/MFA/role/license
     * gates, and never changes the public page publication state.
     */
    private function preview(PDO $pdo): void
    {
        $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1]]);
        if ($id===false || $id===null) {
            $this->render(404,'Preview unavailable','<p>Saved page not found.</p>');
            return;
        }
        $pageRepo=new PageRepository($pdo);
        $page=$pageRepo->byId((int)$id);
        if (!is_array($page)) {
            $this->render(404,'Preview unavailable','<p>Saved page not found.</p>');
            return;
        }
        $siteName=(string)($pdo->query("SELECT setting_value FROM ac_settings WHERE setting_key='site_name'")->fetchColumn() ?: 'Adult Core');
        $design=[];
        $schema=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
        $schema->execute([DesignSchema::VERSION]);
        if ((int)$schema->fetchColumn()>0) {
            $designRepo=new \AdultCore\Design\DesignRepository($pdo);
            $design=$designRepo->current();
            $assetId=(int)($design['hero_asset_id']??0);
            // Never preview unapproved decorative assets as if public.
            if ($page['slug']==='home' && $assetId>0
                && $designRepo->publicAsset($assetId)!==null) {
                $design['public_asset_id']=$assetId;
            }
        }
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');
        echo Layout::site((string)$page['title'],(string)$page['body'],
            $pageRepo->publishedMenu(),$siteName,$page['slug']==='home',$design,true);
    }

    private function pages(PDO $pdo): void
    {
        $repo = new PageRepository($pdo);
        $html = '<p class="admin-section-subtitle">Make thoughtful changes to your published website.</p>'
            . '<div class="page-actions"><a class="button-secondary" href="/admin/page">Create page</a>'
            . '<a class="button-secondary" href="/admin/setup">Schema setup</a></div>';
        if (($_GET['result'] ?? '') === 'saved') $html .= '<p role="status">Page saved.</p>';
        $html .= '<section class="panel"><h2>Public pages</h2><table class="page-table"><thead><tr><th>Title</th><th>Slug</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($repo->all() as $page) {
            $id = (int) $page['id'];
            $html .= '<tr><td>' . self::e((string) $page['title']) . '</td><td>' . self::e((string) $page['slug'])
                . '</td><td>' . self::e((string) $page['status']) . '</td><td><a href="/admin/page?id=' . $id . '">Edit</a>'
                . ' · <a href="/admin/page/preview?id=' . $id . '" target="_blank" rel="noopener noreferrer">Preview saved</a></td></tr>';
        }
        $html .= '</tbody></table></section><p class="muted">Pages support escaped plain text, not user-supplied HTML or scripts.</p>';
        $this->render(200, 'Manage pages', $html);
    }

    private function edit(PDO $pdo, string $method): void
    {
        $repo = new PageRepository($pdo);
        $id = filter_var($_REQUEST['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $error = null;
        $entry = $id > 0 ? $repo->byId($id) : null;
        if ($id > 0 && $entry === null) { $this->render(404, 'Page missing', '<p>Page not found.</p>'); return; }
        if ($method === 'POST') {
            $slug = $_POST['slug'] ?? null;
            $title = $_POST['title'] ?? null;
            $body = $_POST['body'] ?? null;
            $status = $_POST['status'] ?? null;
            if (!is_string($slug) || !is_string($title) || !is_string($body) || !is_string($status)) {
                $error = 'Invalid page fields.';
            } else {
                try {
                    $repo->save($id, trim($slug), trim($title), $body, $status);
                    header('Location: /admin/pages?result=saved', true, 303); return;
                } catch (Throwable) {
                    $error = 'Could not save page. Check fields and ensure the slug is unique.';
                }
            }
            $entry = ['slug'=>is_string($slug)?$slug:'','title'=>is_string($title)?$title:'',
                'body'=>is_string($body)?$body:'','status'=>is_string($status)?$status:'draft'];
        }
        $entry ??= ['slug'=>'','title'=>'','body'=>'','status'=>'draft'];
        $html = $error ? '<div class="alert-error" role="alert">' . self::e($error) . '</div>' : '';
        $html .= '<div class="editor-layout"><section class="panel editor-primary"><form method="post" action="/admin/page' . ($id > 0 ? '?id='.$id : '') . '" class="install-form">'
            . '<input type="hidden" name="csrf" value="' . self::e(AdminSession::csrf()) . '">'
            . '<label for="page-slug">Slug (home = homepage)</label><input id="page-slug" name="slug" maxlength="80" required value="' . self::e((string) $entry['slug']) . '">'
            . '<label for="page-title">Title</label><input id="page-title" name="title" maxlength="190" required value="' . self::e((string) $entry['title']) . '">'
            . '<label for="page-body">Page copy (plain text)</label><textarea id="page-body" name="body" rows="12" maxlength="30000">' . self::e((string) $entry['body']) . '</textarea>'
            . '<label for="page-status">Visibility</label><select id="page-status" name="status">'
            . '<option value="draft"' . ($entry['status'] === 'draft' ? ' selected' : '') . '>Draft</option>'
            . '<option value="published"' . ($entry['status'] === 'published' ? ' selected' : '') . '>Published</option></select>'
            . '<button class="install-button" type="submit">Save page</button></form></section>'
            . '<aside class="panel editor-help"><span class="section-overline">EDITOR NOTES</span><h2>Build something beautiful.</h2>'
            . '<p>Keep your content clear and welcoming. Pages are stored as plain text and escaped before display.</p>'
            . '<div class="editor-help-rule"></div><strong>Publishing checklist</strong>'
            . '<ul><li>Choose a memorable title</li><li>Keep the URL slug simple</li><li>Save a draft before publishing</li><li>Preview your public site</li></ul>'
            . '<a href="/" class="button-secondary" target="_blank" rel="noopener noreferrer">Open live website ↗</a>'
            . ($id>0 ? '<a href="/admin/page/preview?id='.$id.'" class="button-secondary" target="_blank" rel="noopener noreferrer">Preview saved page ↗</a>' : '')
            . '</aside></div>';
        $this->render(200, $id ? 'Edit page' : 'Create page', $html);
    }

    private function members(PDO $pdo): void
    {
        $q = $pdo->query("SELECT id,email,role,status,created_at FROM ac_users WHERE role='member' ORDER BY id DESC LIMIT 100");
        $html = '<p class="intro">Registered members are pending until appropriate identity/age verification and consent workflows are implemented. Administrators cannot bypass verification here.</p>'
            . '<section class="panel"><table class="page-table"><thead><tr><th>ID</th><th>Email</th><th>Status</th><th>Created</th></tr></thead><tbody>';
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $html .= '<tr><td>' . (int) $row['id'] . '</td><td>' . self::e((string)$row['email'])
                . '</td><td>' . self::e((string)$row['status']) . '</td><td>' . self::e((string)$row['created_at']) . '</td></tr>';
        }
        $html .= '</tbody></table></section>';
        $this->render(200, 'Registered members', $html);
    }

    private function render(int $code, string $title, string $body): void
    {
        http_response_code($code);
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/admin/pages'), PHP_URL_PATH);
        echo Layout::admin($title,
            '<div class="admin-heading page-heading"><div><p class="eyebrow">CONTENT MANAGEMENT</p><h1>'
            . self::e($title) . '</h1></div><span class="page-heading-tag">Adult Core / Workspace</span></div>' . $body,
            is_string($path) ? $path : '/admin/pages', AdminSession::csrf(), (new \AdultCore\Licensing\LicenseManager($this->root))->config()['edition'] ?? 'unknown');
    }

    private static function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
