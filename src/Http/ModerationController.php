<?php

declare(strict_types=1);

namespace AdultCore\Http;

use AdultCore\Auth\AdminSession;
use AdultCore\Auth\RolePolicy;
use AdultCore\Database\Connection;
use AdultCore\Moderation\ModerationRepository;
use AdultCore\Moderation\ModerationSchema;
use RuntimeException;
use Throwable;

/** Authenticated triage UI. No approve/publish operation exists. */
final class ModerationController
{
    public function __construct(private readonly string $root) {}

    public function handle(string $method,array $admin): void
    {
        if (!RolePolicy::allows($admin['role'],'content.moderate')) {
            $this->render(403,'Access denied','<p>Moderation permission required.</p>');return;
        }
        if (!in_array($method,['GET','POST'],true)) {
            header('Allow: GET, POST');$this->render(405,'Method not allowed','<p>Unsupported method.</p>');return;
        }
        if ($method==='POST' && !AdminSession::csrfValid($_POST['csrf']??null)) {
            $this->render(403,'Invalid token','<p>Reload the page to continue.</p>');return;
        }
        try {
            $pdo=Connection::open($this->root);
            $q=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
            $q->execute([ModerationSchema::VERSION]);
            if ((int)$q->fetchColumn()!==1) {
                $this->render(503,'Schema update required','<p><a href="/admin/setup">Apply additive database migrations</a> before using moderation.</p>');return;
            }
            $repo=new ModerationRepository($pdo);
            if ($method==='POST') {
                $action=$_POST['action']??null;
                if (!is_string($action)) throw new RuntimeException('Unknown moderation action.');
                if ($action==='new_draft') {
                    if (!is_string($_POST['title']??null)) throw new RuntimeException('Title is required.');
                    $repo->createDraft($_POST['title'],$admin['id']);
                } elseif (in_array($action,['quarantine','reject'],true)) {
                    $repo->moderateContent(self::id($_POST['content_id']??null),$admin['id'],$action,self::field($_POST['note']??null));
                } elseif (in_array($action,['resolved','dismissed'],true)) {
                    $repo->resolveReport(self::id($_POST['report_id']??null),$admin['id'],$action,self::field($_POST['note']??null));
                } else {
                    throw new RuntimeException('Unknown moderation action.');
                }
                header('Location: /admin/moderation?result=saved',true,303);return;
            }
            $this->render(200,'Content moderation',$this->dashboard($repo));
        } catch (RuntimeException $e) {
            $known=['Unknown moderation action.','Title is required.','Invalid record ID.','Invalid review details.','Unsupported review action.','Content not found.','Report not open.','Unsupported report resolution.','Administrator privileges required.','Use a title between 1 and 190 bytes.','Enter a review explanation between 10 and 500 bytes.'];
            $msg=in_array($e->getMessage(),$known,true)?$e->getMessage():'Could not complete the review action.';
            $this->render(422,'Moderation error','<p>'.self::e($msg).'</p><p><a href="/admin/moderation">Return to moderation</a>.</p>');
        } catch (Throwable) {
            $this->render(503,'Moderation unavailable','<p>Database unavailable. No review action was confirmed.</p>');
        }
    }
    private static function id(mixed $v): int
    {
        if (!is_int($v) && !is_string($v)) throw new RuntimeException('Invalid record ID.');
        $id=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($id===false) throw new RuntimeException('Invalid record ID.');
        return $id;
    }
    private static function field(mixed $v): string
    {
        if (!is_string($v)) throw new RuntimeException('Invalid review details.');
        return $v;
    }
    private function dashboard(ModerationRepository $repo): string
    {
        $csrf=self::e(AdminSession::csrf());
        $html='<p class="intro">Private content remains unpublished pending independent age, performer consent, and approval integrations. This panel can only draft, quarantine, reject, and resolve reports. Closing a report does not restore access.</p>';
        if (($_GET['result']??'')==='saved') $html.='<p role="status">Moderation action recorded.</p>';
        $html.='<section class="panel"><h2>Create private content draft</h2><form method="post" action="/admin/moderation" class="install-form">'
            .'<input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="action" value="new_draft">'
            .'<label for="draft-title">Draft title</label><input id="draft-title" name="title" maxlength="190" required>'
            .'<button class="install-button">Create unpublished draft</button></form></section>';
        $html.='<section class="panel"><h2>Content queue</h2><table class="page-table"><thead><tr><th>ID</th><th>Title</th><th>Status</th><th>Open reports</th><th>Review action</th></tr></thead><tbody>';
        foreach ($repo->contentQueue() as $r) {
            $id=(int)$r['id'];
            $html.='<tr><td>'.$id.'</td><td>'.self::e((string)$r['title']).'</td><td>'.self::e((string)$r['status']).' / '.self::e((string)$r['review_status']).'</td><td>'.(int)$r['open_reports'].'</td><td>'
                .'<form method="post" action="/admin/moderation"><input type="hidden" name="csrf" value="'.$csrf.'">'
                .'<input type="hidden" name="content_id" value="'.$id.'"><label>Decision<select name="action"><option value="quarantine">Quarantine</option><option value="reject">Reject</option></select></label>'
                .'<label>Reason<input name="note" required minlength="10" maxlength="500"></label><button class="button-secondary">Record decision</button></form></td></tr>';
        }
        $html.='</tbody></table></section>';
        $html.='<section class="panel"><h2>Reports</h2><table class="page-table"><thead><tr><th>Report</th><th>Content</th><th>Reason</th><th>Status</th><th>Disposition</th></tr></thead><tbody>';
        foreach ($repo->reportQueue() as $r) {
            $id=(int)$r['id'];
            $html.='<tr><td>'.$id.'</td><td>'.(int)$r['content_id'].' — '.self::e((string)$r['title']).'</td><td>'.self::e((string)$r['reason']).'</td><td>'.self::e((string)$r['status']).'</td><td>';
            if ($r['status']==='open') {
                $html.='<form method="post" action="/admin/moderation"><input type="hidden" name="csrf" value="'.$csrf.'">'
                    .'<input type="hidden" name="report_id" value="'.$id.'"><label>Disposition<select name="action"><option value="resolved">Resolved</option><option value="dismissed">Dismissed</option></select></label>'
                    .'<label>Reason<input name="note" required minlength="10" maxlength="500"></label><button class="button-secondary">Close report</button></form>';
            } else $html.='Closed';
            $html.='</td></tr>';
        }
        return $html.'</tbody></table></section><p class="muted">Review actions are recorded in ac_moderation_actions. Identity/performer document uploads are not supported.</p>';
    }
    private function render(int $status,string $title,string $body): void
    {
        http_response_code($status);
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.self::e($title).' · Adult Core</title><link rel="stylesheet" href="/assets/style.css"><link rel="stylesheet" href="/theme.css"></head>'
            .'<body><div class="shell"><header class="topbar"><a href="/admin">Dashboard</a><a href="/admin/setup">Migrations</a><a href="/admin/moderation">Moderation</a></header><main><h1>'.self::e($title).'</h1>'.$body.'</main><footer>Adult Core moderation</footer></div></body></html>';
    }
    private static function e(string $s): string { return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
}
