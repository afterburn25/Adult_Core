<?php

declare(strict_types=1);

namespace AdultCore\Http;

use AdultCore\Auth\AdminSession;
use AdultCore\Auth\RolePolicy;
use AdultCore\Creator\CreatorDraftReview;
use AdultCore\Creator\CreatorReviewSchema;
use AdultCore\Database\Connection;
use AdultCore\Licensing\LicenseManager;
use AdultCore\UI\Layout;
use RuntimeException;
use Throwable;

/** Local moderator review of submitted text-only plans; never approval/publishing. */
final class CreatorReviewController
{
    public function __construct(private readonly string $root) {}

    /** @param array{id:int,email:string,role:string} $admin */
    public function handle(string $method,array $admin): void
    {
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');
        if (!RolePolicy::allows($admin['role'],'content.moderate')) {
            $this->render(403,'Access denied','<p>Moderation permission required.</p>',$admin['role']);
            return;
        }
        if (!in_array($method,['GET','POST'],true)) {
            header('Allow: GET, POST');
            $this->render(405,'Method not allowed','<p>Unsupported request.</p>',$admin['role']);
            return;
        }
        if ($method==='POST' && !AdminSession::csrfValid($_POST['csrf']??null)) {
            $this->render(403,'Invalid token','<p>Reload the private review queue.</p>',$admin['role']);
            return;
        }
        try {
            $pdo=Connection::open($this->root);
            $q=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
            $q->execute([CreatorReviewSchema::VERSION]);
            if ((int)$q->fetchColumn()!==1) {
                $this->render(503,'Site setup needed',
                    '<p>Apply the additive local creator text-review migration under Site setup.</p>',$admin['role']);
                return;
            }
            $review=new CreatorDraftReview($pdo);
            if ($method==='POST') {
                $draft=self::positive($_POST['draft_id']??null);
                $revision=self::positive($_POST['revision']??null);
                if (!is_string($_POST['decision']??null) || !is_string($_POST['feedback']??null)) {
                    throw new RuntimeException('Invalid creator review decision.');
                }
                $review->decide($admin['id'],$draft,$revision,$_POST['decision'],$_POST['feedback']);
                header('Location: /admin/creator-review?result=saved',true,303);
                return;
            }
            $html='<p class="intro">Review submitted TEXT-ONLY creative plans on this installation. '
                .'You can request revisions or reject a plan. Neither action approves a creator, '
                .'publishes media, verifies age/consent or grants payments. '
                .'This is not a cross-site application system.</p>';
            if (($_GET['result']??'')==='saved') {
                $html.='<p role="status">Local review feedback saved privately. No public change occurred.</p>';
            }
            $html.='<section class="panel"><h2>Submitted private plans</h2>';
            $queue=$review->queue($admin['id']);
            if ($queue===[]) $html.='<p>No submitted private plans await review.</p>';
            foreach ($queue as $draft) {
                $html.='<article class="panel"><h3>'.self::e((string)$draft['title']).'</h3>'
                    .'<p>Creator: '.self::e((string)$draft['display_name'])
                    .' (local creator #'.(int)$draft['creator_id'].')</p>'
                    .'<p>Workspace: '.self::e((string)$draft['creator_status'])
                    .' · Status: submitted for private text review</p>'
                    .'<p>'.nl2br(self::e((string)$draft['summary'])).'</p>'
                    .'<form method="post" action="/admin/creator-review" class="install-form">'
                    .'<input type="hidden" name="csrf" value="'.self::e(AdminSession::csrf()).'">'
                    .'<input type="hidden" name="draft_id" value="'.(int)$draft['id'].'">'
                    .'<input type="hidden" name="revision" value="'.(int)$draft['revision'].'">'
                    .'<label>Review decision<select name="decision" required>'
                    .'<option value="changes">Request changes</option>'
                    .'<option value="rejected">Reject this text plan</option>'
                    .'</select></label>'
                    .'<label>Private creator feedback (10–500 bytes)'
                    .'<textarea name="feedback" minlength="10" maxlength="500" rows="3" required></textarea></label>'
                    .'<button class="button-secondary">Send private review feedback</button>'
                    .'</form></article>';
            }
            $html.='</section>';
            $this->render(200,'Creator text review',$html,$admin['role']);
        } catch (RuntimeException $e) {
            $allowed=[
                'Invalid creator review decision.','Enter 10–500 bytes of private review feedback.',
                'Only submitted private plans can be reviewed.',
                'Draft changed; reload the review queue.',
                'Moderation permission required.',
            ];
            $message=in_array($e->getMessage(),$allowed,true)?$e->getMessage():'Private review decision refused.';
            $this->render(422,'Creator review refused','<p role="alert">'.self::e($message).'</p>',$admin['role']);
        } catch (Throwable) {
            $this->render(503,'Private creator review unavailable',
                '<p>Unable to access the local review queue.</p>',$admin['role']);
        }
    }

    private static function positive(mixed $candidate): int
    {
        if (!is_string($candidate) && !is_int($candidate)) {
            throw new RuntimeException('Invalid creator review decision.');
        }
        $id=filter_var($candidate,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($id===false) throw new RuntimeException('Invalid creator review decision.');
        return (int)$id;
    }

    private function render(int $code,string $title,string $content,string $role): void
    {
        http_response_code($code);
        echo Layout::admin($title,
            '<div class="admin-heading"><div><p class="eyebrow">LOCAL DRAFT TRIAGE</p>'
            .'<h1>'.self::e($title).'</h1></div></div>'.$content,
            '/admin/creator-review',AdminSession::csrf(),
            (string)((new LicenseManager($this->root))->config()['edition']??'unknown'),$role);
    }

    private static function e(string $s): string
    {
        return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
}
