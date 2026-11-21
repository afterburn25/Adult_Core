<?php

declare(strict_types=1);

namespace AdultCore\Http;

use AdultCore\Auth\AdminSession;
use AdultCore\Auth\RolePolicy;
use AdultCore\Creator\CreatorApplications;
use AdultCore\Creator\CreatorSchema;
use AdultCore\Database\Connection;
use AdultCore\Licensing\LicenseManager;
use AdultCore\UI\Layout;
use RuntimeException;
use Throwable;

/** Moderator-only intake triage, deliberately no creator verification/approval. */
final class CreatorReviewController
{
    public function __construct(private readonly string $root) {}

    /** @param array{id:int,email:string,role:string} $admin */
    public function handle(string $method,array $admin): void
    {
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');
        if (!RolePolicy::allows($admin['role'],'content.moderate')) {
            $this->render(403,'Access denied','<p>Moderator permission required.</p>',$admin['role']);
            return;
        }
        if (!in_array($method,['GET','POST'],true)) {
            header('Allow: GET, POST');
            $this->render(405,'Method not allowed','<p>Unsupported request.</p>',$admin['role']);
            return;
        }
        if ($method==='POST' && !AdminSession::csrfValid($_POST['csrf']??null)) {
            $this->render(403,'Invalid token','<p>Reload the intake queue to continue.</p>',$admin['role']);
            return;
        }
        try {
            $pdo=Connection::open($this->root);
            $check=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
            $check->execute([CreatorSchema::VERSION]);
            if ((int)$check->fetchColumn()!==1) {
                $this->render(503,'Setup required','<p>Apply creator intake schema in Admin → Setup.</p>',$admin['role']);
                return;
            }
            $repo=new CreatorApplications($pdo);
            if ($method==='POST') {
                $id=filter_var($_POST['application_id']??null,FILTER_VALIDATE_INT,
                    ['options'=>['min_range'=>1]]);
                $version=filter_var($_POST['revision']??null,FILTER_VALIDATE_INT,
                    ['options'=>['min_range'=>1]]);
                if (!$id || !$version || !is_string($_POST['action']??null)
                    || !is_string($_POST['note']??null)) {
                    throw new RuntimeException('Invalid creator review decision.');
                }
                $repo->decide($admin['id'],(int)$id,(int)$version,$_POST['action'],$_POST['note']);
                header('Location: /admin/creators?result=saved',true,303);
                return;
            }
            $html='<p class="intro">Private creator interest and review queue only. '
                .'A member email confirmation is NOT independent age or identity verification. '
                .'There is no creator approval, upload, performer-consent verification, paid entitlement, or publication action here.</p>';
            if (($_GET['result']??'')==='saved') $html.='<p role="status">Decision saved privately.</p>';
            $html.='<section class="panel"><h2>Creator applications</h2>'
                .'<table class="page-table"><thead><tr><th>ID</th><th>Member ID</th>'
                .'<th>Preferred name</th><th>Plans</th><th>Status</th><th>Review</th></tr></thead><tbody>';
            foreach ($repo->queue($admin['id']) as $item) {
                $id=(int)$item['id'];$version=(int)$item['revision'];
                $html.='<tr><td>'.$id.'</td><td>'.(int)$item['member_id'].'</td>'
                    .'<td>'.self::e((string)$item['display_name']).'</td>'
                    .'<td>'.nl2br(self::e((string)$item['introduction'])).'</td>'
                    .'<td>'.self::e((string)$item['status']).'</td><td>';
                if ($item['status']==='pending') {
                    $html.='<form method="post" action="/admin/creators" class="install-form">'
                        .'<input type="hidden" name="csrf" value="'.self::e(AdminSession::csrf()).'">'
                        .'<input type="hidden" name="application_id" value="'.$id.'">'
                        .'<input type="hidden" name="revision" value="'.$version.'">'
                        .'<label>Decision<select name="action" required>'
                        .'<option value="needs_changes">Request changes</option>'
                        .'<option value="rejected">Reject application</option></select></label>'
                        .'<label>Review explanation (10–500 characters)'
                        .'<textarea name="note" minlength="10" maxlength="500" rows="3" required></textarea></label>'
                        .'<button class="button-secondary">Record review decision</button></form>';
                } else {
                    $html.=self::e((string)$item['review_note']);
                }
                $html.='</td></tr>';
            }
            $html.='</tbody></table></section>';
            $this->render(200,'Creator intake',$html,$admin['role']);
        } catch (RuntimeException $e) {
            $allowed=[
                'Invalid creator review decision.',
                'Enter a review explanation between 10 and 500 bytes.',
                'Only pending creator applications can be reviewed.',
                'Application changed; reload the review queue.',
                'Moderator permission required.',
            ];
            $message=in_array($e->getMessage(),$allowed,true)?$e->getMessage():'Review not saved.';
            $this->render(422,'Creator review refused','<p role="alert">'.self::e($message).'</p>',$admin['role']);
        } catch (Throwable) {
            $this->render(503,'Creator intake unavailable','<p>Unable to load the private review queue.</p>',$admin['role']);
        }
    }

    private function render(int $code,string $title,string $html,string $role): void
    {
        http_response_code($code);
        echo Layout::admin($title,
            '<div class="admin-heading"><div><p class="eyebrow">PRIVATE CREATOR INTAKE</p><h1>'
            .self::e($title).'</h1></div></div>'.$html,
            '/admin/creators',AdminSession::csrf(),
            (string)((new LicenseManager($this->root))->config()['edition']??'unknown'),$role);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
}
