<?php

declare(strict_types=1);

namespace AdultCore\Http;

use AdultCore\Auth\AdminSession;
use AdultCore\Auth\AdminMfaSchema;
use AdultCore\Auth\StaffSchema;
use AdultCore\Creator\CreatorSchema;
use AdultCore\Creator\CreatorReviewSchema;
use AdultCore\Creator\CreatorFeedbackSchema;
use AdultCore\Auth\RolePolicy;
use AdultCore\Database\Connection;
use AdultCore\Website\PageRepository;
use AdultCore\Website\PageRevisionSchema;
use AdultCore\Website\PageNavigationSchema;
use AdultCore\Website\PageHistorySchema;
use AdultCore\Website\SiteSchema;
use AdultCore\Website\SiteIdentity;
use AdultCore\Website\SiteIdentitySchema;
use AdultCore\Member\MemberSchema;
use AdultCore\Member\MemberEmailSchema;
use AdultCore\Member\PasswordRecoverySchema;
use AdultCore\Membership\MembershipSchema;
use AdultCore\Moderation\ModerationSchema;
use AdultCore\Verification\VerificationSchema;
use AdultCore\UI\Layout;
use AdultCore\Design\DesignSchema;
use AdultCore\Design\HighlightSchema;
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
            if (in_array($path,['/admin/pages','/admin/page','/admin/page/preview','/admin/page/history'],true)
                && !PageRevisionSchema::installed($pdo)) {
                $this->render(503,'Page editor setup required',
                    '<p>Back up the database, then apply the additive page-revision migration in <a href="/admin/setup">Site setup</a>.</p>');
                return;
            }
            if (in_array($path,['/admin/pages','/admin/page'],true)
                && !PageNavigationSchema::installed($pdo)) {
                $this->render(503,'Page navigation setup required',
                    '<p>Back up the database and apply the additive page navigation migration in <a href="/admin/setup">Site setup</a>.</p>');
                return;
            }
            if (in_array($path,['/admin/pages','/admin/page','/admin/page/preview','/admin/page/history'],true)
                && !PageHistorySchema::installed($pdo)) {
                $this->render(503,'Page history setup required',
                    '<p>Back up the database, then apply the additive private page history migration in <a href="/admin/setup">Site setup</a>.</p>');
                return;
            }
            if ($path === '/admin/page/history') {
                if ($method!=='GET') {
                    header('Allow: GET');
                    $this->render(405,'Method not allowed','<p>History preview is read-only.</p>');
                    return;
                }
                $this->historicalPreview($pdo);
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
            if ($path === '/admin/site') {
                if (!SiteIdentitySchema::installed($pdo)) {
                    $this->render(503,'Local site identity setup required',
                        '<p>Back up the database, then apply site identity in <a href="/admin/setup">Site setup</a>.</p>');
                    return;
                }
                $this->identity($pdo,$method);
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

    /** Owner-only local branding; existing CSRF session replaces repeat passwords. */
    private function identity(PDO $pdo,string $method): void
    {
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');
        $repo=new SiteIdentity($pdo);
        $error='';
        if ($method==='POST') {
            $rawRevision=$_POST['revision']??null;
            $revision=is_string($rawRevision)
                ? filter_var($rawRevision,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])
                : false;
            try {
                if (!is_int($revision)) throw new RuntimeException('Invalid local site identity revision.');
                $repo->rename($_POST['site_name']??null,$revision);
                header('Location: /admin/site?result=saved',true,303);
                return;
            } catch (RuntimeException $e) {
                $error=in_array($e->getMessage(),[
                    'Site name changed; reload before saving.',
                    'Invalid local site identity revision.',
                    'Site name must be valid single-line text, up to 120 bytes.',
                    'Site name must be between 2 and 120 bytes.',
                ],true)?$e->getMessage():'Could not change site name. Check the local installation.';
            }
        }
        $current=$repo->current();
        $html='<p class="intro">Change the branding of this independently hosted website. '
            .'Its license domain, Lite/Pro edition and member data are unchanged.</p>';
        if ($error!=='') $html.='<p class="alert-error" role="alert">'.self::e($error).'</p>';
        if (($_GET['result']??'')==='saved' && $method==='GET') {
            $html.='<p role="status">Website name saved. No new login was required.</p>';
        }
        $html.='<section class="panel"><h2>Website name</h2>'
            .'<form method="post" action="/admin/site" class="install-form">'
            .'<input type="hidden" name="csrf" value="'.self::e(AdminSession::csrf()).'">'
            .'<input type="hidden" name="revision" value="'.$current['revision'].'">'
            .'<label for="local-site-name">Public site name (2–120 UTF-8 bytes)</label>'
            .'<input id="local-site-name" name="site_name" maxlength="120" required value="'
            .self::e($current['site_name']).'">'
            .'<p class="muted">This appears in the website title, public header and footer. '
            .'Reload before saving if another administrator changes the name.</p>'
            .'<button class="install-button" type="submit">Save website name</button>'
            .'</form></section>';
        $this->render(200,'Site identity',$html);
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
            PageRevisionSchema::migrate($pdo);
            PageNavigationSchema::migrate($pdo);
            PageHistorySchema::migrate($pdo);
            SiteIdentitySchema::migrate($pdo);
            AdminMfaSchema::migrate($pdo);
            DesignSchema::migrate($pdo);
            CutoutPlacement::migrate($pdo);
            HighlightSchema::migrate($pdo);
            MemberSchema::migrate($pdo);
            StaffSchema::migrate($pdo);
            MemberEmailSchema::migrate($pdo);
            CreatorSchema::migrate($pdo);
            CreatorReviewSchema::migrate($pdo);
            CreatorFeedbackSchema::migrate($pdo);
            PasswordRecoverySchema::migrate($pdo);
            MembershipSchema::migrate($pdo);
            ModerationSchema::migrate($pdo);
            VerificationSchema::migrate($pdo);
            header('Location: /admin/pages?result=setup', true, 303);
            return;
        }
        // Base-site installation alone does not imply later migrations ran.
        // Display each additive feature schema independently and read-only;
        // only an authenticated, CSRF-protected POST applies any changes.
        $versions=[
            'Public page schema'=>SiteSchema::VERSION,
            'Page revision protection'=>PageRevisionSchema::VERSION,
            'Page navigation settings'=>PageNavigationSchema::VERSION,
            'Private page revision history'=>PageHistorySchema::VERSION,
            'Local site identity'=>SiteIdentitySchema::VERSION,
            'Administrator authenticator MFA'=>AdminMfaSchema::VERSION,
            'Design Studio'=>DesignSchema::VERSION,
            'Cutout position controls'=>CutoutPlacement::VERSION,
            'Homepage information cards'=>HighlightSchema::VERSION,
            'Member accounts'=>MemberSchema::VERSION,
            'Restricted staff accounts'=>StaffSchema::VERSION,
            'Member email confirmation'=>MemberEmailSchema::VERSION,
            'Local creator workspaces'=>CreatorSchema::VERSION,
            'Local creator text review'=>CreatorReviewSchema::VERSION,
            'Creator private feedback history'=>CreatorFeedbackSchema::VERSION,
            'Member password recovery'=>PasswordRecoverySchema::VERSION,
            'Membership records'=>MembershipSchema::VERSION,
            'Moderation records'=>ModerationSchema::VERSION,
            'Signed verification receipts'=>VerificationSchema::VERSION,
        ];
        $present=array_fill_keys($pdo->query('SELECT version FROM ac_schema_migrations')
            ->fetchAll(PDO::FETCH_COLUMN),true);
        $pending=0;
        $html='<p class="intro">These are additive local database migrations for this independent '
            .'Lite/Pro website. Back up the existing site before applying updates. '
            .'An Installed status means only the listed schema exists, not that '
            .'adult content, age/consent providers, billing or hosting are production-certified.</p>'
            .'<section class="panel"><h2>Website migration status</h2>'
            .'<table class="page-table"><thead><tr><th>Feature</th><th>Schema status</th></tr></thead><tbody>';
        foreach ($versions as $name=>$version) {
            $ready=isset($present[$version]);
            if (!$ready) ++$pending;
            $html.='<tr><td>'.self::e($name).'</td><td>'
                .($ready?'Installed':'Pending — apply setup')
                .'</td></tr>';
        }
        $html.='</tbody></table><p>'.($pending===0
            ? 'All listed local schema migrations have been recorded.'
            : $pending.' local schema migration(s) pending.')
            .'</p></section>'
            .'<form method="post" action="/admin/setup" class="install-form">'
            .'<input type="hidden" name="csrf" value="'.self::e(AdminSession::csrf()).'">'
            .'<button class="install-button" type="submit">Apply additive migrations</button></form>';
        $this->render(200,'Site setup',$html);
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
            $pageRepo->publishedMenu(),$siteName,$page['slug']==='home',$design,true,(string)$page['slug']);
    }

    /**
     * Inspect a past snapshot before deciding to restore it. No public-site
     * layout, active media selection, live page status or mutation is involved.
     */
    private function historicalPreview(PDO $pdo): void
    {
        $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1]]);
        $revision=filter_var($_GET['revision']??null,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1]]);
        if (!is_int($id) || !is_int($revision)) {
            $this->render(404,'History unavailable','<p>Saved revision not found.</p>');
            return;
        }
        $snapshot=(new PageRepository($pdo))->historyDetail($id,$revision);
        if (!is_array($snapshot)) {
            $this->render(404,'History unavailable','<p>Saved revision not found.</p>');
            return;
        }
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');
        $html='<p role="status"><strong>PRIVATE SAVED REVISION</strong> — Not the live website. '
            .'This snapshot does not restore or publish anything.</p>'
            .'<section class="panel"><h2>'.self::e((string)$snapshot['title']).'</h2>'
            .'<p class="muted">Revision '.(int)$snapshot['revision']
            .' · Saved state: '.self::e((string)$snapshot['status'])
            .' · Recorded UTC: '.self::e((string)$snapshot['saved_at']).'</p>'
            .'<div class="site-copy">'
            .nl2br(self::e((string)$snapshot['body']),false).'</div></section>'
            .'<p><a class="button-secondary" href="/admin/page?id='.$id.'">Back to page editor</a></p>';
        $this->render(200,'Historical page text',$html);
    }

    private function pages(PDO $pdo): void
    {
        $repo = new PageRepository($pdo);
        $rawBefore=$_GET['before']??null;
        $before=$rawBefore===null?null:(is_string($rawBefore)
            ? filter_var($rawBefore,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])
            : false);
        if ($before===false) {
            $this->render(422,'Invalid page list cursor',
                '<p role="alert">The page link was invalid. <a href="/admin/pages">Return to the first page</a>.</p>');
            return;
        }
        $rawSearch=$_GET['q']??'';
        if (!is_string($rawSearch)) {
            $this->render(422,'Invalid page search',
                '<p role="alert">The page search was invalid.</p>');
            return;
        }
        try {
            $pageSet=$repo->pageList($before,$rawSearch);
        } catch (RuntimeException) {
            $this->render(422,'Invalid page search',
                '<p role="alert">Search must contain no control characters and at most 80 bytes.</p>');
            return;
        }
        $html = '<p class="admin-section-subtitle">Make thoughtful changes to your published website. Newest created pages first.</p>'
            . '<div class="page-actions"><a class="button-secondary" href="/admin/page">Create page</a>'
            . '<a class="button-secondary" href="/admin/setup">Schema setup</a></div>'
            . '<form method="get" action="/admin/pages" class="install-form">'
            . '<label for="admin-page-search">Find local pages by title or URL slug</label>'
            . '<input id="admin-page-search" name="q" maxlength="80" value="'.self::e(trim($rawSearch)).'">'
            . '<button class="button-secondary" type="submit">Search pages</button>'
            . '<a href="/admin/pages">Clear search</a></form>';
        if (($_GET['result'] ?? '') === 'saved') $html .= '<p role="status">Page saved.</p>';
        $html .= '<section class="panel"><h2>Public pages</h2><table class="page-table"><thead><tr><th>Title</th><th>Slug</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($pageSet['pages'] as $page) {
            $id = (int) $page['id'];
            $html .= '<tr><td>' . self::e((string) $page['title']) . '</td><td>' . self::e((string) $page['slug'])
                . '</td><td>' . self::e((string) $page['status']) . '</td><td><a href="/admin/page?id=' . $id . '">Edit</a>'
                . ' · <a href="/admin/page/preview?id=' . $id . '" target="_blank" rel="noopener noreferrer">Preview saved</a></td></tr>';
        }
        $html .= '</tbody></table>';
        if ($pageSet['pages']===[]) {
            $html.='<p>No saved pages match this search or page.</p>';
        }
        if ($pageSet['next']!==null) {
            $html.='<p><a href="/admin/pages?before='.(int)$pageSet['next']
                .($rawSearch!==''?'&amp;q='.rawurlencode(trim($rawSearch)):'')
                .'">Older pages</a></p>';
        }
        if ($before!==null) {
            $html.='<p><a href="/admin/pages'
                .($rawSearch!==''?'?q='.rawurlencode(trim($rawSearch)):'')
                .'">Back to newest pages</a></p>';
        }
        $html.='</section><p class="muted">Pages support escaped plain text, not user-supplied HTML or scripts.</p>';
        $this->render(200, 'Manage pages', $html);
    }

    private function edit(PDO $pdo, string $method): void
    {
        $repo = new PageRepository($pdo);
        $id = filter_var($_REQUEST['id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
        $error = null;
        $entry = $id > 0 ? $repo->byId($id) : null;
        if ($id > 0 && $entry === null) { $this->render(404, 'Page missing', '<p>Page not found.</p>'); return; }
        $action=$_POST['action']??'save';
        if ($method==='POST' && !in_array($action,['save','duplicate','restore'],true)) {
            $this->render(422,'Invalid page action','<p>Unknown page action.</p>');
            return;
        }
        if ($method==='POST' && $action==='restore') {
            try {
                $current=$_POST['revision']??null;
                $source=$_POST['history_revision']??null;
                $currentVersion=is_string($current)
                    ? filter_var($current,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) : false;
                $fromVersion=is_string($source)
                    ? filter_var($source,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) : false;
                if ($id<1 || !is_int($currentVersion) || !is_int($fromVersion)) {
                    throw new RuntimeException('Invalid saved page revision.');
                }
                $repo->restoreTextAsDraft($id,$currentVersion,$fromVersion);
                header('Location: /admin/page?id='.$id.'&result=restored',true,303);
                return;
            } catch (RuntimeException $e) {
                $error=in_array($e->getMessage(),[
                    'Page changed; reload before restoring.',
                    'Saved page revision not found.',
                    'Invalid saved page revision.',
                ],true)?$e->getMessage():'Could not restore the private page revision.';
            } catch (Throwable) {
                $error='Could not restore the private page revision.';
            }
        } elseif ($method==='POST' && $action==='duplicate') {
            try {
                $rawRevision=$_POST['revision']??null;
                $revision=is_string($rawRevision)
                    ? filter_var($rawRevision,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])
                    : false;
                $rawSlug=$_POST['new_slug']??null;
                if ($id<1 || !is_int($revision) || !is_string($rawSlug)) {
                    throw new RuntimeException('Invalid private page copy request.');
                }
                $newId=$repo->duplicateAsDraft($id,$revision,trim($rawSlug));
                header('Location: /admin/page?id='.$newId.'&result=duplicated',true,303);
                return;
            } catch (RuntimeException $e) {
                $error=in_array($e->getMessage(),[
                    'Page revision required; reload before copying.',
                    'Page changed; reload before copying.',
                    'Invalid page fields. Use a lowercase slug, a title and up to 30 KB of plain text.',
                ],true)?$e->getMessage():'Could not create a private page copy.';
            } catch (Throwable) {
                $error='Could not create a private page copy.';
            }
        } elseif ($method === 'POST') {
            $slug = $_POST['slug'] ?? null;
            $title = $_POST['title'] ?? null;
            $body = $_POST['body'] ?? null;
            $status = $_POST['status'] ?? null;
            // Modern forms send hidden 0 + checked 1; older open tabs may
            // omit both fields. Preserve current nav settings on omission,
            // never silently relist an unlisted published page.
            $rawMenu=$_POST['show_in_menu']??null;
            $rawPosition=$_POST['nav_position']??null;
            $showInMenu=$rawMenu===null?null:(is_string($rawMenu)
                && in_array($rawMenu,['0','1'],true)?$rawMenu==='1':false);
            $validMenu=$rawMenu===null
                || (is_string($rawMenu) && in_array($rawMenu,['0','1'],true));
            $navPosition=$rawPosition===null?null:(is_string($rawPosition)
                && preg_match('/^(0|[1-9][0-9]{0,2})$/D',$rawPosition)===1
                ? (int)$rawPosition:false);
            $rawRevision=$_POST['revision']??null;
            $revision=$id>0 && is_string($rawRevision)
                ? filter_var($rawRevision,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])
                : null;
            if (!is_string($slug) || !is_string($title) || !is_string($body) || !is_string($status)) {
                $error = 'Invalid page fields.';
            } elseif ($id>0 && !is_int($revision)) {
                $error = 'Page revision required; reload before saving.';
            } elseif (!$validMenu || $navPosition===false) {
                $error = 'Invalid navigation settings.';
            } else {
                try {
                    $repo->save($id, trim($slug), trim($title), $body, $status, $revision,
                        $showInMenu,$navPosition);
                    header('Location: /admin/pages?result=saved', true, 303); return;
                } catch (RuntimeException $e) {
                    $error=in_array($e->getMessage(),[
                        'Page changed; reload before saving.',
                        'Page revision required; reload before saving.',
                        'The homepage URL slug is reserved.',
                    ],true)?$e->getMessage()
                        :'Could not save page. Check fields and ensure the slug is unique.';
                } catch (Throwable) {
                    $error = 'Could not save page. Check fields and ensure the slug is unique.';
                }
            }
            $entry = ['slug'=>is_string($slug)?$slug:'','title'=>is_string($title)?$title:'',
                'body'=>is_string($body)?$body:'','status'=>is_string($status)?$status:'draft',
                'revision'=>is_int($revision)?$revision:(int)($entry['revision']??0),
                'show_in_menu'=>$showInMenu===null?(int)($entry['show_in_menu']??1):($showInMenu?1:0),
                'nav_position'=>is_int($navPosition)?$navPosition:(int)($entry['nav_position']??100)];
        }
        $entry ??= ['slug'=>'','title'=>'','body'=>'','status'=>'draft','revision'=>0,
            'show_in_menu'=>1,'nav_position'=>100];
        $html = $error ? '<div class="alert-error" role="alert">' . self::e($error) . '</div>' : '';
        if (($_GET['result']??'')==='duplicated' && $id>0 && $method==='GET') {
            $html.='<p role="status">A new private draft copy was created. Review it before publishing. '
                .'The original page is unchanged.</p>';
        }
        if (($_GET['result']??'')==='restored' && $id>0 && $method==='GET') {
            $html.='<p role="status">An earlier page text revision was restored to a NEW PRIVATE DRAFT. '
                .'Publish separately after reviewing. Previous revisions are retained.</p>';
        }
        $html .= '<div class="editor-layout"><section class="panel editor-primary"><form method="post" action="/admin/page' . ($id > 0 ? '?id='.$id : '') . '" class="install-form">'
            . '<input type="hidden" name="csrf" value="' . self::e(AdminSession::csrf()) . '">'
            . ($id>0 ? '<input type="hidden" name="revision" value="'.(int)$entry['revision'].'">' : '')
            . '<label for="page-slug">Slug (home is reserved for the homepage)</label><input id="page-slug" name="slug" maxlength="80" required'
            . (($id>0 && $entry['slug']==='home')?' readonly aria-describedby="page-home-slug-note"':'')
            . ' value="' . self::e((string) $entry['slug']) . '">'
            . (($id>0 && $entry['slug']==='home')?'<p class="muted" id="page-home-slug-note">The homepage URL cannot be renamed. Title, text, visibility and menu settings remain editable.</p>':'')
            . '<label for="page-title">Title</label><input id="page-title" name="title" maxlength="190" required value="' . self::e((string) $entry['title']) . '">'
            . '<label for="page-body">Page copy (plain text)</label><textarea id="page-body" name="body" rows="12" maxlength="30000">' . self::e((string) $entry['body']) . '</textarea>'
            . '<label for="page-status">Visibility</label><select id="page-status" name="status">'
            . '<option value="draft"' . ($entry['status'] === 'draft' ? ' selected' : '') . '>Draft</option>'
            . '<option value="published"' . ($entry['status'] === 'published' ? ' selected' : '') . '>Published</option></select>'
            . '<input type="hidden" name="show_in_menu" value="0">'
            . '<label><input type="checkbox" name="show_in_menu" value="1"'
            . ((int)$entry['show_in_menu']===1?' checked':'')
            . '> Show this page in the public navigation</label>'
            . '<label for="page-nav-position">Menu order (0–999; lower appears first)</label>'
            . '<input id="page-nav-position" name="nav_position" type="number" min="0" max="999" value="'
            . (int)$entry['nav_position'].'">'
            . '<p class="muted">Hiding a link does not make a published page private. Use Draft to unpublish it. The Home link is always shown.</p>'
            . '<button class="install-button" type="submit">Save page</button></form></section>'
            . '<aside class="panel editor-help"><span class="section-overline">EDITOR NOTES</span><h2>Build something beautiful.</h2>'
            . '<p>Keep your content clear and welcoming. Pages are stored as plain text and escaped before display.</p>'
            . '<section class="page-live-preview" aria-label="Unsaved page text preview">'
            . '<h3>Unsaved text preview</h3>'
            . '<p class="muted">Preview only, in this browser. Editing does not save or publish the page.</p>'
            . '<h4 id="page-live-title">' . self::e((string)$entry['title']) . '</h4>'
            . '<p class="page-live-body" id="page-live-body">' . self::e((string)$entry['body']) . '</p>'
            . '</section>'
            . '<div class="editor-help-rule"></div><strong>Publishing checklist</strong>'
            . '<ul><li>Choose a memorable title</li><li>Keep the URL slug simple</li><li>Save a draft before publishing</li><li>Preview your public site</li></ul>'
            . '<a href="/" class="button-secondary" target="_blank" rel="noopener noreferrer">Open live website ↗</a>'
            . ($id>0 ? '<a href="/admin/page/preview?id='.$id.'" class="button-secondary" target="_blank" rel="noopener noreferrer">Preview saved page ↗</a>' : '')
            . ($id>0 ? '<hr><h2>Copy as private draft</h2>'
                .'<p>Make a new unpublished page with this saved text. The original is never changed or published.</p>'
                .'<form method="post" action="/admin/page?id='.$id.'" class="install-form">'
                .'<input type="hidden" name="csrf" value="'.self::e(AdminSession::csrf()).'">'
                .'<input type="hidden" name="action" value="duplicate">'
                .'<input type="hidden" name="revision" value="'.(int)$entry['revision'].'">'
                .'<label for="page-copy-slug">New private page URL slug</label>'
                .'<input id="page-copy-slug" name="new_slug" maxlength="80" '
                .'pattern="[a-z0-9][a-z0-9-]*" placeholder="new-page-draft" required>'
                .'<button class="button-secondary" type="submit">Create unpublished copy</button></form>' : '')
            . '</aside></div>';
        if ($id>0) {
            $html.='<section class="panel"><h2>Private page revision history</h2>'
                .'<p class="muted">Only saved revisions since this feature was installed are available. '
                .'Restoring an earlier version copies its title and text into a new <strong>unpublished draft</strong>. '
                .'It never restores the old published state, URL or navigation settings.</p>'
                .'<table class="page-table"><thead><tr><th>Revision</th><th>Title</th><th>Saved as</th><th>Saved at (UTC)</th><th></th></tr></thead><tbody>';
            foreach ($repo->history($id) as $snapshot) {
                $version=(int)$snapshot['revision'];
                $html.='<tr><td>'. $version .'</td><td>'.self::e((string)$snapshot['title'])
                    .'</td><td>'.self::e((string)$snapshot['status'])
                    .'</td><td>'.self::e((string)$snapshot['saved_at']).'</td><td>';
                $html.='<a href="/admin/page/history?id='.$id.'&amp;revision='.$version
                    .'" target="_blank" rel="noopener noreferrer">Preview revision</a>';
                if ($version<(int)$entry['revision']) {
                    $html.=' · <form method="post" action="/admin/page?id='.$id.'">'
                        .'<input type="hidden" name="csrf" value="'.self::e(AdminSession::csrf()).'">'
                        .'<input type="hidden" name="action" value="restore">'
                        .'<input type="hidden" name="revision" value="'.(int)$entry['revision'].'">'
                        .'<input type="hidden" name="history_revision" value="'.$version.'">'
                        .'<button class="button-secondary" type="submit">Restore text as draft</button></form>';
                } else {
                    $html.='<span class="muted">Current revision</span>';
                }
                $html.='</td></tr>';
            }
            $html.='</tbody></table><p class="muted">Showing up to 20 most recent revisions.</p></section>';
        }
        $html.='<script defer src="/assets/page-editor.js"></script>';
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
