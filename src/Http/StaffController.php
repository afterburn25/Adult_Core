<?php

declare(strict_types=1);

namespace AdultCore\Http;

use AdultCore\Auth\AdminAccounts;
use AdultCore\Auth\AdminSession;
use AdultCore\Auth\RolePolicy;
use AdultCore\Auth\StaffRepository;
use AdultCore\Auth\StaffSchema;
use AdultCore\Database\Connection;
use AdultCore\Installer\InstallRateLimiter;
use AdultCore\Licensing\LicenseManager;
use AdultCore\UI\Layout;
use PDO;
use RuntimeException;
use Throwable;

/** Strictly scoped moderator lifecycle with a bounded server-side manager authorization window. */
final class StaffController
{
    public function __construct(private readonly string $root) {}

    /** @param array{id:int,email:string,role:string} $admin */
    public function handle(string $method,array $admin): void
    {
        if (!RolePolicy::allows($admin['role'],'members.manage')) {
            $this->render(403,'Access denied','<p>Manager permission required.</p>');return;
        }
        if (!in_array($method,['GET','POST'],true)) {
            header('Allow: GET, POST');
            $this->render(405,'Method not allowed','<p>Unsupported staff request.</p>');return;
        }
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');
        if ($method==='POST' && !AdminSession::csrfValid($_POST['csrf']??null)) {
            $this->render(403,'Invalid token','<p>Reload the staff page and try again.</p>');return;
        }
        try {
            $pdo=Connection::open($this->root);
            $q=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
            $q->execute([StaffSchema::VERSION]);
            if ((int)$q->fetchColumn()!==1) {
                $this->render(503,'Setup required',
                    '<p>Apply the additive staff audit migration through <a href="/admin/setup">site setup</a> before creating moderators.</p>');
                return;
            }
            $repo=new StaffRepository($pdo);
            if ($method==='POST') {
                $action=$_POST['action']??null;
                if ($action==='verify') {
                    // Only this explicit one-time unlock form accepts the
                    // manager password; routine changes reuse the server
                    // session for the next 30 minutes.
                    $password=$_POST['manager_password']??null;
                    if (!is_string($password) || strlen($password)>72 || $password==='') {
                        throw new RuntimeException('Manager password confirmation required.');
                    }
                    (new InstallRateLimiter($this->root.'/storage'))->consume(
                        'staff-stepup:'.(string)($_SERVER['REMOTE_ADDR']??'unknown'));
                    $verified=(new AdminAccounts($pdo))->verifyCredentials($admin['email'],$password);
                    if ($verified===null || (int)$verified['id']!==$admin['id']
                        || !RolePolicy::allows($verified['role'],'members.manage')) {
                        throw new RuntimeException('Manager password confirmation failed.');
                    }
                    AdminSession::markManagerVerified();
                    header('Location: /admin/staff?result=unlocked',true,303);
                    return;
                }
                if (!AdminSession::managerVerifiedRecently()) {
                    throw new RuntimeException('Confirm your password once to unlock staff changes.');
                }
                if ($action==='create') {
                    $newPassword=$_POST['new_password']??null;
                    if (!is_string($newPassword)) throw new RuntimeException('Invalid staff credentials.');
                    $managerReuse=(new AdminAccounts($pdo))
                        ->verifyCredentials($admin['email'],$newPassword);
                    if ($managerReuse!==null && (int)$managerReuse['id']===$admin['id']) {
                        throw new RuntimeException('Choose a different password for the new moderator.');
                    }
                    $repo->createModerator($admin['id'],self::text($_POST['email']??null),$newPassword);
                } elseif ($action==='suspend') {
                    $repo->suspendModerator($admin['id'],self::positive($_POST['staff_id']??null));
                } elseif ($action==='restore') {
                    $newPassword=$_POST['new_password']??null;
                    if (!is_string($newPassword)) throw new RuntimeException('Invalid staff credentials.');
                    $managerReuse=(new AdminAccounts($pdo))
                        ->verifyCredentials($admin['email'],$newPassword);
                    if ($managerReuse!==null && (int)$managerReuse['id']===$admin['id']) {
                        throw new RuntimeException('Choose a different password for the moderator.');
                    }
                    $repo->restoreModerator($admin['id'],self::positive($_POST['staff_id']??null),$newPassword);
                } else {
                    throw new RuntimeException('Unsupported staff action.');
                }
                header('Location: /admin/staff?result=saved',true,303);return;
            }
            $this->render(200,'Moderation team',$this->dashboard($repo,$admin['id']));
        } catch (RuntimeException $e) {
            $allowed=[
                'Manager password confirmation required.','Manager password confirmation failed.',
                'Confirm your password once to unlock staff changes.',
                'Enter a valid staff email.','Use a staff password between 16 and 72 bytes without control characters.',
                'Staff credential creation unavailable.','Moderator account limit reached.',
                'Staff email already in use.','Invalid moderator account.','Moderator is not active.',
                'Moderator is not suspended.','Staff management permission required.',
                'Replacement password must differ from the previous moderator password.',
                'Invalid staff credentials.','Choose a different password for the new moderator.',
                'Choose a different password for the moderator.','Unsupported staff action.',
            ];
            $message=in_array($e->getMessage(),$allowed,true)?$e->getMessage():'Staff change could not be verified.';
            $this->render(422,'Staff change refused','<p role="alert">'.self::e($message).'</p>');
        } catch (Throwable) {
            $this->render(503,'Staff management unavailable','<p>No staff change could be confirmed.</p>');
        }
    }

    private function dashboard(StaffRepository $repo,int $actorId): string
    {
        $csrf=self::e(AdminSession::csrf());
        $unlocked=AdminSession::managerVerifiedRecently();
        $html='<p class="intro">Create or suspend restricted moderation accounts. '
            .'Your secure login cookie keeps your session active across page reloads and browser restarts '
            .'within the server timeout. Staff changes use a 30-minute manager authorization window '
            .'after sign-in or a single password confirmation; no password is stored in the cookie. '
            .'Moderators cannot publish, activate paid membership, manage licenses or site settings.</p>';
        if (($_GET['result']??'')==='saved') {
            $html.='<p role="status">Staff change recorded in the private audit history.</p>';
        }
        if (($_GET['result']??'')==='unlocked') {
            $html.='<p role="status">Staff changes unlocked for 30 minutes.</p>';
        }
        if (!$unlocked) {
            $html.='<section class="panel"><h2>Confirm once to edit staff</h2>'
                .'<p>Your signed-in session remains valid. Confirm your manager password once '
                .'to authorize staff changes for 30 minutes; no repeated password per change.</p>'
                .'<form method="post" action="/admin/staff" class="install-form" autocomplete="off">'
                .'<input type="hidden" name="csrf" value="'.$csrf.'">'
                .'<input type="hidden" name="action" value="verify">'
                .'<label>Manager password<input name="manager_password" type="password" maxlength="72" required autocomplete="current-password"></label>'
                .'<button class="install-button">Unlock staff changes</button></form></section>';
        } else {
            $html.='<p role="status">Staff editing authorized for this session. '
                .'Confirmation expires 30 minutes after your last password verification.</p>';
        }
        if ($unlocked) $html.='<section class="panel"><h2>Add restricted moderator</h2>'
            .'<p>Set a unique initial password and transmit it privately to the intended person. '
            .'No invitation email or identity verification is sent by this development build. '
            .'Do not reuse your administrator password.</p>'
            .'<form method="post" action="/admin/staff" class="install-form" autocomplete="off">'
            .'<input type="hidden" name="csrf" value="'.$csrf.'">'
            .'<input type="hidden" name="action" value="create">'
            .'<label>Staff email<input name="email" type="email" maxlength="190" required></label>'
            .'<label>Initial moderator password<input name="new_password" type="password" minlength="16" maxlength="72" required autocomplete="new-password"></label>'
            .'<button class="install-button" type="submit">Create moderator</button></form></section>';
        $html.='<section class="panel"><h2>Moderator accounts</h2>'
            .'<table class="page-table"><thead><tr><th>ID</th><th>Email</th><th>Status</th><th>Authenticator</th><th>Account action</th></tr></thead><tbody>';
        foreach ($repo->moderators($actorId) as $item) {
            $id=$item['id'];$status=$item['status'];
            $html.='<tr><td>'.$id.'</td><td>'.self::e($item['email']).'</td>'
                .'<td>'.self::e($status).'</td>'
                .'<td>'.($item['mfa_enabled']===1?'Enrolled':'Not enrolled').'</td><td>';
            if ($status==='active' && $unlocked) {
                $html.='<form method="post" action="/admin/staff" class="install-form" autocomplete="off">'
                    .'<input type="hidden" name="csrf" value="'.$csrf.'">'
                    .'<input type="hidden" name="action" value="suspend">'
                    .'<input type="hidden" name="staff_id" value="'.$id.'">'
                    .'<button class="button-secondary" type="submit">Suspend moderator</button></form>';
            } elseif ($status==='suspended' && $unlocked) {
                $html.='<form method="post" action="/admin/staff" class="install-form" autocomplete="off">'
                    .'<input type="hidden" name="csrf" value="'.$csrf.'">'
                    .'<input type="hidden" name="action" value="restore">'
                    .'<input type="hidden" name="staff_id" value="'.$id.'">'
                    .'<label>Replacement moderator password<input name="new_password" type="password" minlength="16" maxlength="72" required autocomplete="new-password"></label>'
                    .'<button class="button-secondary" type="submit">Restore with new password</button></form>';
            } else {
                $html.=$unlocked?'Unavailable':'Unlock staff changes to edit';
            }
            $html.='</td></tr>';
        }
        $html.='</tbody></table></section><section class="panel"><h2>Private staff history</h2>'
            .'<p>Latest 50 actions, with numeric actor/staff references only. No passwords or secret tokens are logged.</p>'
            .'<table class="page-table"><thead><tr><th>Staff ID</th><th>Actor ID</th><th>Action</th><th>UTC</th></tr></thead><tbody>';
        foreach ($repo->recentActions($actorId) as $row) {
            $html.='<tr><td>'.(int)$row['staff_id'].'</td><td>'.(int)$row['actor_id'].'</td>'
                .'<td>'.self::e((string)$row['action']).'</td>'
                .'<td>'.self::e((string)$row['created_at']).'</td></tr>';
        }
        return $html.'</tbody></table></section>';
    }

    private static function positive(mixed $value): int
    {
        if (!is_string($value) && !is_int($value)) throw new RuntimeException('Invalid moderator account.');
        $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($id===false) throw new RuntimeException('Invalid moderator account.');
        return (int)$id;
    }

    private static function text(mixed $value): string
    {
        if (!is_string($value)) throw new RuntimeException('Invalid staff credentials.');
        return $value;
    }

    private function render(int $code,string $title,string $body): void
    {
        http_response_code($code);
        echo Layout::admin($title,
            '<div class="admin-heading"><div><p class="eyebrow">TEAM ACCESS</p><h1>'
            .self::e($title).'</h1></div><span class="page-heading-tag">Restricted roles</span></div>'.$body,
            '/admin/staff',AdminSession::csrf(),
            (new LicenseManager($this->root))->config()['edition']??'unknown');
    }

    private static function e(string $v): string
    {
        return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    }
}
