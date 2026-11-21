<?php

declare(strict_types=1);

namespace AdultCore\Http;

use AdultCore\Auth\AdminAccounts;
use AdultCore\Auth\AdminSession;
use AdultCore\Auth\RolePolicy;
use AdultCore\Database\Connection;
use AdultCore\Installer\InstallRateLimiter;
use AdultCore\Licensing\LicenseManager;
use AdultCore\Member\LocalMemberSafety;
use AdultCore\Member\MemberSafetySchema;
use AdultCore\UI\Layout;
use RuntimeException;
use Throwable;

/**
 * Site-local member suspension and pending-only restoration. Never activates
 * billing, identity/age verification, creator publication or adult media.
 */
final class MemberSafetyController
{
    public function __construct(private readonly string $root) {}

    /** @param array{id:int,email:string,role:string} $admin */
    public function handle(string $method,array $admin): void
    {
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');
        if (!RolePolicy::allows($admin['role'],'members.manage')) {
            $this->render(403,'Access denied','<p>Local member management requires manager permissions.</p>');
            return;
        }
        if (!in_array($method,['GET','POST'],true)) {
            header('Allow: GET, POST');
            $this->render(405,'Method not allowed','<p>Unsupported member safety request.</p>');
            return;
        }
        if ($method==='POST' && !AdminSession::csrfValid($_POST['csrf']??null)) {
            $this->render(403,'Invalid token','<p>Reload the member account review.</p>');
            return;
        }
        $rawId=$_GET['id']??null;
        $id=is_string($rawId)
            ? filter_var($rawId,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) : false;
        if (!is_int($id)) {
            $this->render(404,'Member not found','<p>Use a local account ID from Admin → Members.</p>');
            return;
        }
        if ($method==='POST' && (!is_string($_POST['member_id']??null)
            || $_POST['member_id']!==(string)$id)) {
            $this->render(422,'Invalid member action','<p>Account ID mismatch; reload the page.</p>');
            return;
        }
        try {
            $pdo=Connection::open($this->root);
            if (!MemberSafetySchema::installed($pdo)) {
                $this->render(503,'Member safety setup required',
                    '<p>Back up the site, then apply m21 in <a href="/admin/setup">Site setup</a>.</p>');
                return;
            }
            $repo=new LocalMemberSafety($pdo);
            $member=$repo->member($admin['id'],$id);
            if ($member===null) {
                $this->render(404,'Member not found','<p>No local member account with this ID.</p>');
                return;
            }
            if ($method==='POST') {
                $action=$_POST['action']??null;
                if ($action==='verify') {
                    $password=$_POST['manager_password']??null;
                    if (!is_string($password) || strlen($password)>72 || $password==='') {
                        throw new RuntimeException('Manager password confirmation required.');
                    }
                    (new InstallRateLimiter($this->root.'/storage'))->consume(
                        'member-safety-stepup:'.(string)($_SERVER['REMOTE_ADDR']??'unknown'));
                    $verified=(new AdminAccounts($pdo))->verifyCredentials($admin['email'],$password);
                    if ($verified===null || (int)$verified['id']!==$admin['id']
                        || !RolePolicy::allows($verified['role'],'members.manage')) {
                        throw new RuntimeException('Manager password confirmation failed.');
                    }
                    AdminSession::markManagerVerified();
                    header('Location: /admin/members/manage?id='.$id.'&result=unlocked',true,303);
                    return;
                }
                if (!AdminSession::managerVerifiedRecently()) {
                    throw new RuntimeException('Confirm your manager password once before changing member safety status.');
                }
                if (!in_array($action,['suspend','restore_pending'],true)) {
                    throw new RuntimeException('Unsupported local member safety action.');
                }
                $expected=$_POST['expected_status']??null;
                if (!is_string($expected)) {
                    throw new RuntimeException('Invalid local member safety action.');
                }
                $repo->change($admin['id'],$id,$action,$expected,$_POST['reason']??null);
                header('Location: /admin/members/manage?id='.$id.'&result=saved',true,303);
                return;
            }
            $rawAuditCursor=$_GET['audit_before']??null;
            $auditBefore=$rawAuditCursor===null?null:(is_string($rawAuditCursor)
                ? filter_var($rawAuditCursor,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) : false);
            if ($auditBefore===false) {
                $this->render(422,'Invalid member safety audit cursor',
                    '<p>Account audit page link is invalid. '
                    .'<a href="/admin/members/manage?id='.$id.'">Return to latest account events</a>.</p>');
                return;
            }
            $auditPage=$repo->historyPage($admin['id'],$id,$auditBefore);
            $csrf=self::e(AdminSession::csrf());
            $unlocked=AdminSession::managerVerifiedRecently();
            $html='<p class="intro">Safety controls for one local member account. '
                .'Suspension immediately denies sign-in and private media. Restoration is always '
                .'<strong>pending</strong>, never a paid or age/consent-approved status. '
                .'Old member sessions and reset tokens are revoked on every change.</p>'
                .'<section class="panel"><h2>Member #'.$member['id'].'</h2>'
                .'<p><strong>Email:</strong> '.self::e($member['email']).'</p>'
                .'<p><strong>Local account status:</strong> '.self::e($member['status']).'</p>'
                .'<p><strong>Registered (UTC):</strong> '.self::e($member['created_at']).'</p></section>';
            if (($_GET['result']??'')==='saved') {
                $html.='<p role="status">Local member safety change recorded; old member sessions revoked.</p>';
            } elseif (($_GET['result']??'')==='unlocked') {
                $html.='<p role="status">Member safety changes unlocked for 30 minutes.</p>';
            }
            if (!$unlocked) {
                $html.='<section class="panel"><h2>Unlock member safety changes</h2>'
                    .'<p>Confirm your manager password once for a 30-minute authorization window. '
                    .'Your existing admin cookie remains active; no password is stored in the browser.</p>'
                    .'<form method="post" action="/admin/members/manage?id='.$id.'" class="install-form" autocomplete="off">'
                    .'<input type="hidden" name="csrf" value="'.$csrf.'">'
                    .'<input type="hidden" name="member_id" value="'.$id.'">'
                    .'<input type="hidden" name="action" value="verify">'
                    .'<label>Manager password<input name="manager_password" type="password" maxlength="72" required autocomplete="current-password"></label>'
                    .'<button class="install-button" type="submit">Unlock member safety changes</button></form></section>';
            } elseif (in_array($member['status'],['pending','active','suspended'],true)) {
                $suspended=$member['status']==='suspended';
                $action=$suspended?'restore_pending':'suspend';
                $label=$suspended?'Restore member to pending':'Suspend local member';
                $html.='<section class="panel"><h2>'.self::e($label).'</h2>'
                    .'<p>Reason is retained in the private site-local security audit. '
                    .'No account data or protected evidence is deleted.</p>'
                    .'<form method="post" action="/admin/members/manage?id='.$id.'" class="install-form">'
                    .'<input type="hidden" name="csrf" value="'.$csrf.'">'
                    .'<input type="hidden" name="member_id" value="'.$id.'">'
                    .'<input type="hidden" name="action" value="'.$action.'">'
                    .'<input type="hidden" name="expected_status" value="'.self::e($member['status']).'">'
                    .'<label>Safety decision reason<input name="reason" minlength="10" maxlength="500" required></label>'
                    .'<button class="button-secondary" type="submit">'.self::e($label).'</button>'
                    .'</form></section>';
            }
            $html.='<section class="panel"><h2>Private safety audit</h2>'
                .'<p class="muted">20 most recent local actions per page. '
                .'Older audited decisions are retained. No identity evidence, billing records or creator media.</p>'
                .'<table class="page-table"><thead><tr><th>From</th><th>To</th><th>Reason</th><th>Actor ID</th><th>UTC</th></tr></thead><tbody>';
            foreach ($auditPage['items'] as $event) {
                $html.='<tr><td>'.self::e((string)$event['old_status']).'</td><td>'
                    .self::e((string)$event['new_status']).'</td><td>'
                    .self::e((string)$event['reason']).'</td><td>'
                    .(int)$event['actor_id'].'</td><td>'
                    .self::e((string)$event['created_at']).'</td></tr>';
            }
            $html.='</tbody></table>';
            if ($auditPage['next']!==null) {
                $html.='<p><a href="/admin/members/manage?id='.$id
                    .'&amp;audit_before='.(int)$auditPage['next']
                    .'">Older member safety actions</a></p>';
            }
            if ($auditBefore!==null) {
                $html.='<p><a href="/admin/members/manage?id='.$id
                    .'">Back to newest member safety actions</a></p>';
            }
            if ($auditPage['items']===[]) $html.='<p>No audited actions on this page.</p>';
            $html.='</section><p>'
                .'<a href="/admin/members">Back to local member directory</a></p>';
            $this->render(200,'Member safety review',$html);
        } catch (RuntimeException $e) {
            $allowed=[
                'Manager password confirmation required.',
                'Manager password confirmation failed.',
                'Confirm your manager password once before changing member safety status.',
                'Invalid local member safety action.',
                'Unsupported local member safety action.',
                'Local member account not found.',
                'Member account changed; reload before continuing.',
                'Local member status transition unavailable.',
                'Enter a single-line reason of 10–500 bytes.',
                'Invalid member safety audit cursor.',
            ];
            $message=in_array($e->getMessage(),$allowed,true)
                ? $e->getMessage():'Unable to change this local member account.';
            $this->render(422,'Member safety action refused',
                '<p role="alert">'.self::e($message).'</p>'
                .'<p><a href="/admin/members/manage?id='.$id.'">Reload member review</a></p>');
        } catch (Throwable) {
            $this->render(503,'Member safety unavailable',
                '<p>Unable to verify the local account safety state. No change was confirmed.</p>');
        }
    }

    private function render(int $code,string $title,string $content): void
    {
        http_response_code($code);
        $edition=(new LicenseManager($this->root))->config()['edition']??'unknown';
        echo Layout::admin($title,
            '<div class="admin-heading"><div><p class="eyebrow">LOCAL MEMBER SAFETY</p><h1>'
            .self::e($title).'</h1></div><span class="page-heading-tag">Restricted account security</span></div>'.$content,
            '/admin/members',AdminSession::csrf(),(string)$edition);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
}
