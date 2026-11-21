<?php

declare(strict_types=1);

namespace AdultCore\Http;

use AdultCore\Auth\AdminSession;
use AdultCore\Auth\RolePolicy;
use AdultCore\Database\Connection;
use AdultCore\Moderation\ModerationRepository;
use AdultCore\Moderation\ModerationSchema;
use AdultCore\UI\Layout;
use PDO;
use RuntimeException;
use Throwable;

/** Admin-only quarantine and report triage. Does not approve or publish media. */
final class ModerationController
{
    public function __construct(private readonly string $root) {}

    /** @param array{id:int,email:string,role:string} $admin */
    public function handle(string $method, array $admin): void
    {
        if (!RolePolicy::allows($admin['role'], 'content.moderate')) {
            $this->render(403,'Access denied','<p>Moderation permission required.</p>'); return;
        }
        if (!in_array($method,['GET','POST'],true)) {
            header('Allow: GET, POST'); $this->render(405,'Method not allowed','<p>Unsupported method.</p>'); return;
        }
        if ($method==='POST' && !AdminSession::csrfValid($_POST['csrf']??null)) {
            $this->render(403,'Invalid token','<p>Reload the page to continue.</p>'); return;
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
                    $repo->createDraft($_POST['title'], $admin['id']);
                } elseif ($action==='declare_performers') {
                    $repo->declarePerformers(self::positiveId($_POST['content_id']??null),$admin['id'],self::positiveId($_POST['performer_count']??null));
                } elseif (in_array($action,['quarantine','reject'],true)) {
                    $repo->moderateContent(self::positiveId($_POST['content_id']??null),$admin['id'],$action,self::safeString($_POST['note']??null));
                } elseif (in_array($action,['resolved','dismissed'],true)) {
                    $repo->resolveReport(self::positiveId($_POST['report_id']??null),$admin['id'],$action,self::safeString($_POST['note']??null));
                } else {
                    throw new RuntimeException('Unknown moderation action.');
                }
                header('Location: /admin/moderation?result=saved',true,303); return;
            }
            $this->render(200,'Content moderation',$this->dashboard($repo));
        } catch (RuntimeException $e) {
            $allowed=['Unknown moderation action.','Invalid record ID.','Invalid review details.','Unsupported review action.','Content not found.','Report not open.','Unsupported report resolution.','Administrator privileges required.','Title is required.','Use a title between 1 and 190 bytes.','Enter a review explanation between 10 and 500 bytes.'];
            $message=in_array($e->getMessage(),$allowed,true)?$e->getMessage():'Could not complete the review action.';
            $this->render(422,'Moderation error','<p>'.self::e($message).'</p><p><a href="/admin/moderation">Return to moderation</a>.</p>');
        } catch (Throwable) {
            $this->render(503,'Moderation unavailable','<p>Database unavailable. No review action was confirmed.</p>');
        }
    }

    private static function positiveId(mixed $value): int
    {
        if (!is_string($value) && !is_int($value)) throw new RuntimeException('Invalid record ID.');
        $v=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($v===false) throw new RuntimeException('Invalid record ID.');
        return $v;
    }

    private static function safeString(mixed $value): string
    {
        if (!is_string($value)) throw new RuntimeException('Invalid review details.');
        return $value;
    }

    private function dashboard(ModerationRepository $repo): string
    {
        $csrf=self::e(AdminSession::csrf(), (new \AdultCore\Licensing\LicenseManager($this->root))->config()['edition'] ?? 'unknown');
        $html='<p class="intro">Private content stays unpublished until independent age, consent, and media review integrations are implemented. This panel supports draft preparation, quarantine, rejection, and report triage only. Closing a report does not restore content.</p>';
        if (($_GET['result']??'')==='saved') $html.='<p role="status">Moderation action recorded.</p>';
        $html.='<section class="panel"><h2>Create private content draft</h2><form method="post" action="/admin/moderation" class="install-form">'
            .'<input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="action" value="new_draft">'
            .'<label for="content-title">Draft title</label><input id="content-title" name="title" maxlength="190" required>'
            .'<button class="install-button">Create unpublished draft</button></form></section>';
        $html.='<section class="panel"><h2>Content queue</h2><table class="page-table"><thead><tr><th>ID</th><th>Title</th><th>Status</th><th>Performer evidence</th><th>Open reports</th><th>Review action</th></tr></thead><tbody>';
        foreach ($repo->contentQueue() as $item) {
            $id=(int)$item['id'];
            $html.='<tr><td>'.$id.'</td><td>'.self::e((string)$item['title']).'</td><td>'.self::e((string)$item['status']).' / '.self::e((string)$item['review_status']).'</td>'
                .'<td>'.self::e(self::evidenceSummary($item)).'</td><td>'.(int)$item['open_reports'].'</td><td><form method="post" action="/admin/moderation">'
                .'<input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="content_id" value="'.$id.'">'
                .'<label>Decision<select name="action"><option value="quarantine">Quarantine</option><option value="reject">Reject</option></select></label>'
                .'<label>Reason<input name="note" required minlength="10" maxlength="500" placeholder="Reason for review decision"></label>'
                .'<button class="button-secondary">Record decision</button></form></td></tr>';
        }
        $html.='</tbody></table></section>';
        $html.='<section class="panel"><h2>Declare performer roster (draft only)</h2><p>Enter the number of people depicted in the draft. This does not verify anyone or permit publication. Each record receives a private numeric reference for the contracted verification provider.</p>'
            .'<form method="post" action="/admin/moderation" class="install-form">'
            .'<input type="hidden" name="csrf" value="'.$csrf.'"><input type="hidden" name="action" value="declare_performers">'
            .'<label>Draft content ID<input name="content_id" type="number" min="1" required></label>'
            .'<label>Number of performers<input name="performer_count" type="number" min="1" max="20" required></label>'
            .'<button class="button-secondary">Create pending records</button></form></section>';
        $html.='<section class="panel"><h2>Performer verification records</h2><table class="page-table"><thead><tr><th>Record ID</th><th>Content ID</th><th>Identity</th><th>Consent</th><th>Review</th></tr></thead><tbody>';
        foreach ($repo->performerQueue() as $record) {
            $html.='<tr><td>'.(int)$record['id'].'</td><td>'.(int)$record['content_id'].'</td><td>'.($record['verified_at']===null?'Pending':'Assertion received').'</td><td>'.($record['consent_at']===null?'Pending':'Assertion received').'</td><td>'.self::e((string)$record['review_status']).'</td></tr>';
        }
        $html.='</tbody></table></section>';
        $html.='<section class="panel"><h2>Reports</h2><table class="page-table"><thead><tr><th>Report</th><th>Content</th><th>Reason</th><th>Status</th><th>Disposition</th></tr></thead><tbody>';
        foreach ($repo->reportQueue() as $item) {
            $id=(int)$item['id'];
            $html.='<tr><td>'.$id.'</td><td>'.(int)$item['content_id'].' — '.self::e((string)$item['title']).'</td><td>'.self::e((string)$item['reason']).'</td><td>'.self::e((string)$item['status']).'</td><td>';
            if ($item['status']==='open') {
                $html.='<form method="post" action="/admin/moderation"><input type="hidden" name="csrf" value="'.$csrf.'">'
                    .'<input type="hidden" name="report_id" value="'.$id.'"><label>Decision<select name="action"><option value="resolved">Resolved</option><option value="dismissed">Dismissed</option></select></label>'
                    .'<label>Reason<input name="note" required minlength="10" maxlength="500"></label><button class="button-secondary">Close report</button></form>';
            } else { $html.='Closed'; }
            $html.='</td></tr>';
        }
        $html.='</tbody></table></section><p class="muted">Review actions are appended to the private ac_moderation_actions audit table. Uploaded media, ID documents and performer evidence are not accepted by this prototype.</p>';
        return $html;
    }

    /** Evidence status only: never an automated approval or publishing decision. */
    private static function evidenceSummary(array $item): string
    {
        $expected=(int)($item['expected_performers']??0);
        $recorded=(int)($item['recorded_performers']??0);
        $evidenced=(int)($item['evidenced_performers']??0);
        if ($expected < 1) return 'Roster not declared';
        if ($recorded !== $expected) {
            return sprintf('Roster incomplete (%d of %d records)', $recorded, $expected);
        }
        if ($evidenced < $expected) {
            return sprintf('Pending evidence (%d of %d reviewed)', $evidenced, $expected);
        }
        return sprintf('Evidence recorded (%d of %d) — independent approval still required', $evidenced, $expected);
    }

    private function render(int $code,string $title,string $body): void
    {
        http_response_code($code);
        echo Layout::admin($title,
            '<div class="admin-heading page-heading"><div><p class="eyebrow">TRUST &amp; SAFETY</p><h1>'
            . self::e($title) . '</h1></div><span class="page-heading-tag">Restricted workflow</span></div>' . $body,
            '/admin/moderation', AdminSession::csrf(), (new \AdultCore\Licensing\LicenseManager($this->root))->config()['edition'] ?? 'unknown');
    }

    private static function e(string $v):string {return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
