<?php

declare(strict_types=1);

namespace AdultCore\Http;

use AdultCore\Auth\AdminSession;
use AdultCore\Auth\RolePolicy;
use AdultCore\Creator\CreatorSchema;
use AdultCore\Creator\CreatorReviewSchema;
use AdultCore\Creator\LocalCreators;
use AdultCore\Database\Connection;
use AdultCore\Licensing\LicenseManager;
use AdultCore\UI\Layout;
use RuntimeException;
use Throwable;

/**
 * Local website-owner creator assignments only.
 * No shared accounts, vendor approval, creator applications or publish route.
 */
final class CreatorAdminController
{
    public function __construct(private readonly string $root) {}

    /**
     * Read-only local owner review of private creator plans.
     * A suspended creator's retained plans may still be inspected by
     * the owner, but no draft status can be changed or made public here.
     *
     * @param array{id:int,email:string,role:string} $admin
     */
    public function drafts(string $path,string $method,array $admin): void
    {
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');
        if (!RolePolicy::allows($admin['role'],'members.manage')) {
            $this->render(403,'Access denied','<p>Local manager permission required.</p>');
            return;
        }
        if ($method!=='GET') {
            header('Allow: GET');
            $this->render(405,'Method not allowed','<p>Owner draft review is read-only.</p>');
            return;
        }
        if (preg_match('#^/admin/creators/drafts/([1-9][0-9]{0,17})$#D',$path,$match)!==1) {
            $this->render(404,'Not found','<p>Private planning drafts unavailable.</p>');
            return;
        }
        try {
            $pdo=Connection::open($this->root);
            $check=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
            $check->execute([CreatorSchema::VERSION]);
            if ((int)$check->fetchColumn()!==1) {
                $this->render(503,'Setup required','<p>Apply the local creator migration first.</p>');
                return;
            }
            $reviewCheck=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
            $reviewCheck->execute([CreatorReviewSchema::VERSION]);
            if ((int)$reviewCheck->fetchColumn()!==1) {
                $this->render(503,'Text-review setup required',
                    '<p>Apply the additive local creator text-review migration in Site setup.</p>');
                return;
            }
            $data=(new LocalCreators($pdo))->privatePlansForOwner($admin['id'],(int)$match[1]);
            if ($data===null) {
                $this->render(404,'Not found','<p>Private planning drafts unavailable.</p>');
                return;
            }
            $profile=$data['profile'];
            $html='<p class="intro">Owner-only, read-only saved plans from this installation. '
                .'No media, identity evidence, performer consent, approval or publishing controls '
                .'are available here.</p>'
                .'<section class="panel"><h2>'.self::e((string)$profile['display_name']).'</h2>'
                .'<p>Local member: '.self::e((string)$profile['email'])
                .' · Workspace: '.self::e((string)$profile['status']).'</p></section>'
                .'<section class="panel"><h2>Private planning drafts</h2>';
            if ($data['drafts']===[]) $html.='<p>No saved private drafts.</p>';
            foreach ($data['drafts'] as $draft) {
                $html.='<article class="panel"><h3>'.self::e((string)$draft['title'])
                    .'</h3><p>Status: '.self::e((string)$draft['status']).', unpublished.</p>'
                    .'<p>'.nl2br(self::e((string)$draft['summary'])).'</p>';
                if ((string)($draft['review_note']??'')!=='') {
                    $html.='<p>Local review feedback: '
                        .nl2br(self::e((string)$draft['review_note'])).'</p>';
                }
                $html.='<p class="muted">Draft #'.(int)$draft['id']
                    .' · Last changed '.self::e((string)$draft['updated_at']).' UTC</p>'
                    .'</article>';
            }
            $html.='</section><section class="panel"><h2>Text-review history</h2>'
                .'<p>Latest 100 local text-plan actions only; numeric actor/draft references. '
                .'No feedback notes or private content are duplicated in the event log.</p>'
                .'<table class="page-table"><thead><tr><th>Draft</th><th>Actor</th>'
                .'<th>Action</th><th>UTC</th></tr></thead><tbody>';
            foreach ($data['events'] as $event) {
                $html.='<tr><td>'.(int)$event['draft_id'].'</td>'
                    .'<td>'.(int)$event['actor_id'].'</td>'
                    .'<td>'.self::e((string)$event['action']).'</td>'
                    .'<td>'.self::e((string)$event['created_at']).'</td></tr>';
            }
            $html.='</tbody></table></section>'
                .'<p><a href="/admin/creators">Return to local creators</a></p>';
            $this->render(200,'Private creator plans',$html);
        } catch (Throwable) {
            $this->render(503,'Private creator plans unavailable','<p>Could not load saved plans.</p>');
        }
    }

    /** @param array{id:int,email:string,role:string} $admin */
    public function handle(string $method,array $admin): void
    {
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');
        if (!RolePolicy::allows($admin['role'],'members.manage')) {
            $this->render(403,'Access denied','<p>Local creator management requires an owner or administrator.</p>');
            return;
        }
        if (!in_array($method,['GET','POST'],true)) {
            header('Allow: GET, POST');
            $this->render(405,'Method not allowed','<p>Unsupported request method.</p>');
            return;
        }
        if ($method==='POST' && !AdminSession::csrfValid($_POST['csrf']??null)) {
            $this->render(403,'Invalid token','<p>Reload the local creator page to continue.</p>');
            return;
        }
        try {
            $pdo=Connection::open($this->root);
            $q=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
            $q->execute([CreatorSchema::VERSION]);
            if ((int)$q->fetchColumn()!==1) {
                $this->render(503,'Setup required',
                    '<p>Apply the local creator schema under <a href="/admin/setup">Site setup</a> first.</p>');
                return;
            }
            $creators=new LocalCreators($pdo);
            if ($method==='POST') {
                $action=$_POST['action']??null;
                if ($action==='provision') {
                    $creators->provision($admin['id'],
                        self::string($_POST['email']??null),
                        self::string($_POST['display_name']??null));
                } elseif ($action==='rename') {
                    $creators->rename($admin['id'],
                        self::positive($_POST['creator_id']??null),
                        self::positive($_POST['revision']??null),
                        self::string($_POST['display_name']??null));
                } elseif ($action==='suspend' || $action==='restore') {
                    $id=self::positive($_POST['creator_id']??null);
                    $revision=self::positive($_POST['revision']??null);
                    $creators->setStatus($admin['id'],$id,$revision,$action);
                } else {
                    throw new RuntimeException('Unsupported local creator action.');
                }
                header('Location: /admin/creators?result=saved',true,303);
                return;
            }
            $html='<p class="intro">Creators belong to THIS self-hosted website and are assigned '
                .'directly by its owner/admin. There is no central creator service, application, '
                .'software-vendor approval or cross-site account. Granting workspace access '
                .'does not independently verify age or identity, authorize content distribution '
                .'or enable payment and public publishing.</p>';
            if (($_GET['result']??'')==='saved') {
                $html.='<p role="status">Local creator setting saved. No content was published.</p>';
            }
            $html.='<section class="panel"><h2>Give a local member private creator tools</h2>'
                .'<p>Choose an already registered member with confirmed email. '
                .'They keep their regular member account and receive private text-draft tools only.</p>'
                .'<form method="post" action="/admin/creators" class="install-form">'
                .'<input type="hidden" name="csrf" value="'.self::e(AdminSession::csrf()).'">'
                .'<input type="hidden" name="action" value="provision">'
                .'<label>Existing confirmed member email'
                .'<input name="email" type="email" maxlength="190" required></label>'
                .'<label>Creator display name'
                .'<input name="display_name" maxlength="100" minlength="2" required></label>'
                .'<button class="install-button">Enable private creator workspace</button>'
                .'</form></section><section class="panel"><h2>Creators on this installation</h2>'
                .'<table class="page-table"><thead><tr><th>ID</th><th>Member</th>'
                .'<th>Display name</th><th>Workspace</th><th>Private drafts</th><th>Control</th><th>Rename</th>'
                .'</tr></thead><tbody>';
            $before=isset($_GET['before'])?self::positive($_GET['before']):null;
            $page=$creators->managed($admin['id'],$before);
            $more=count($page)>50;
            if ($more) array_pop($page);
            foreach ($page as $row) {
                $id=(int)$row['id'];
                $revision=(int)$row['revision'];
                $status=(string)$row['status'];
                $html.='<tr><td>'.$id.'</td><td>'.self::e((string)$row['email']).'</td>'
                    .'<td>'.self::e((string)$row['display_name']).'</td>'
                    .'<td>'.self::e($status).'</td><td>'.(int)$row['draft_count'].'</td><td>';
                $html.='<p><a href="/admin/creators/drafts/'.$id.'">View private plans</a></p>';
                if (in_array($status,['enabled','suspended'],true)) {
                    $action=$status==='enabled'?'suspend':'restore';
                    $html.='<form method="post" action="/admin/creators">'
                        .'<input type="hidden" name="csrf" value="'.self::e(AdminSession::csrf()).'">'
                        .'<input type="hidden" name="creator_id" value="'.$id.'">'
                        .'<input type="hidden" name="revision" value="'.$revision.'">'
                        .'<input type="hidden" name="action" value="'.$action.'">'
                        .'<button class="button-secondary">'
                        .($action==='suspend'?'Suspend workspace':'Restore private workspace')
                        .'</button></form>';
                } else {
                    $html.='<span class="muted">Unavailable</span>';
                }
                $html.='</td><td><form method="post" action="/admin/creators">'
                    .'<input type="hidden" name="csrf" value="'.self::e(AdminSession::csrf()).'">'
                    .'<input type="hidden" name="creator_id" value="'.$id.'">'
                    .'<input type="hidden" name="revision" value="'.$revision.'">'
                    .'<input type="hidden" name="action" value="rename">'
                    .'<label>New display name<input name="display_name" maxlength="100" minlength="2" required value="'
                    .self::e((string)$row['display_name']).'"></label>'
                    .'<button class="button-secondary">Save name</button></form></td></tr>';
            }
            $html.='</tbody></table>';
            if ($more && $page!==[]) {
                $last=end($page);
                $html.='<p><a href="/admin/creators?before='.(int)$last['id']
                    .'">Older local creators</a></p>';
            }
            $html.='</section><section class="panel"><h2>Private local action history</h2>'
                .'<p>Recent 50 actions record only numeric actor/creator references and action types.</p>'
                .'<table class="page-table"><thead><tr><th>Actor</th><th>Creator</th>'
                .'<th>Action</th><th>UTC</th></tr></thead><tbody>';
            foreach ($creators->audit($admin['id']) as $row) {
                $html.='<tr><td>'.(int)$row['actor_id'].'</td>'
                    .'<td>'.(int)$row['creator_id'].'</td>'
                    .'<td>'.self::e((string)$row['action']).'</td>'
                    .'<td>'.self::e((string)$row['created_at']).'</td></tr>';
            }
            $html.='</tbody></table></section>';
            $this->render(200,'Local creator accounts',$html);
        } catch (RuntimeException $e) {
            $allowed=[
                'Use a creator display name of 2–100 bytes without control characters.',
                'Enter an existing, confirmed local member email.',
                'Choose a locally registered member with confirmed email.',
                'This member already has a local creator account.',
                'Local creator account limit reached.',
                'Local creator management permission required.',
                'Invalid local creator action.','Local creator account not found.',
                'Creator status changed; reload the account list.',
                'Creator is not in the required state.',
                'Creator member account or email is no longer eligible.',
                'Invalid local creator input.','Unsupported local creator action.',
                'Invalid local creator page cursor.','Creator display name is unchanged.',
            ];
            $message=in_array($e->getMessage(),$allowed,true)?$e->getMessage():'Local creator change was refused.';
            $this->render(422,'Creator change refused',
                '<p role="alert">'.self::e($message).'</p>');
        } catch (Throwable) {
            $this->render(503,'Local creator tools unavailable',
                '<p>Unable to confirm the creator operation.</p>');
        }
    }

    private static function string(mixed $value): string
    {
        if (!is_string($value)) throw new RuntimeException('Invalid local creator input.');
        return $value;
    }

    private static function positive(mixed $value): int
    {
        $n=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($n===false || $n===null) throw new RuntimeException('Invalid local creator action.');
        return (int)$n;
    }

    private function render(int $status,string $title,string $content): void
    {
        http_response_code($status);
        echo Layout::admin($title,
            '<div class="admin-heading"><div><p class="eyebrow">SELF-HOSTED CREATORS</p>'
            .'<h1>'.self::e($title).'</h1></div></div>'.$content,
            '/admin/creators',AdminSession::csrf(),
            (string)((new LicenseManager($this->root))->config()['edition']??'unknown'));
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
}
