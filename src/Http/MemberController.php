<?php

declare(strict_types=1);

namespace AdultCore\Http;

use AdultCore\Auth\LoginRateLimiter;
use AdultCore\Database\Connection;
use AdultCore\Member\MemberAccounts;
use AdultCore\Member\MemberSchema;
use AdultCore\Member\MemberSession;
use AdultCore\Moderation\ModerationRepository;
use AdultCore\Moderation\ModerationSchema;
use PDO;
use Throwable;

/** Registration and private account shell. Content is NEVER unlocked based on signup alone. */
final class MemberController
{
    public function __construct(private readonly string $root) {}

    public function handle(string $path, string $method): void
    {
        $https = in_array(strtolower((string)($_SERVER['HTTPS'] ?? '')), ['on','1'], true);
        $loopback = in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1','::1'], true);
        if (!$https && !$loopback) { $this->view(403,'HTTPS required','<p>Membership accounts require HTTPS.</p>'); return; }
        try {
            MemberSession::start($this->root, $https);
            $pdo = Connection::open($this->root);
            $migration = $pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
            $migration->execute([MemberSchema::VERSION]);
            if ((int)$migration->fetchColumn() !== 1) {
                $this->view(503,'Not ready','<p>Membership registration is not enabled yet.</p>'); return;
            }
            $accounts = new MemberAccounts($pdo);
            if ($path === '/members/logout') {
                if ($method !== 'POST') { $this->denyMethod('POST'); return; }
                if (!MemberSession::csrfValid($_POST['csrf'] ?? null)) { $this->view(403,'Invalid token','<p>Reload and retry.</p>'); return; }
                MemberSession::logout(); $this->redirect('/'); return;
            }
            if ($path === '/members/report') { $this->report($pdo,$method,$accounts); return; }
            if ($path === '/members') {
                if ($method !== 'GET') { $this->denyMethod('GET'); return; }
                $member = $this->member($accounts);
                if ($member === null) { $this->redirect('/members/login'); return; }
                $this->view(200,'Member account','<p>Signed in as <strong>' . self::e($member['email']) . '</strong>.</p>'
                    . '<p>Account status: <strong>' . self::e($member['status']) . '</strong>.</p>'
                    . '<p>Private content and paid access remain unavailable until independent age verification, content approval, and payment integrations have been completed.</p>'
                    . '<p><a href="/members/report">Report a content safety concern</a></p>'
                    . '<form method="post" action="/members/logout"><input type="hidden" name="csrf" value="' . MemberSession::csrf() . '"><button class="button-secondary">Sign out</button></form>');
                return;
            }
            if (!in_array($path, ['/join','/members/login'], true)) { $this->view(404,'Not found','<p>Page not found.</p>'); return; }
            if (!in_array($method, ['GET','POST'], true)) { $this->denyMethod('GET, POST'); return; }
            if ($this->member($accounts) !== null) { $this->redirect('/members'); return; }
            $this->form($path, $method, $accounts);
        } catch (Throwable) {
            $this->view(503,'Temporarily unavailable','<p>The member service is not available.</p>');
        }
    }

    private function report(PDO $pdo,string $method,MemberAccounts $accounts): void
    {
        if (!in_array($method,['GET','POST'],true)) { $this->denyMethod('GET, POST');return; }
        $member=$this->member($accounts);
        if ($member===null) { $this->redirect('/members/login');return; }
        $q=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
        $q->execute([ModerationSchema::VERSION]);
        if ((int)$q->fetchColumn()!==1) { $this->view(503,'Reporting unavailable','<p>Reporting has not been set up.</p>');return; }
        if ($method==='POST') {
            if (!MemberSession::csrfValid($_POST['csrf']??null)) {
                $this->view(403,'Invalid token','<p>Reload and retry.</p>');return;
            }
            $id=filter_var($_POST['content_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $reason=$_POST['reason']??null;
            if ($id===false || !is_string($reason)) {
                $this->view(422,'Invalid report','<p>Enter a valid content ID and reason.</p>');return;
            }
            try {
                (new ModerationRepository($pdo))->submitReport((int)$member['id'],(int)$id,$reason);
                $this->redirect('/members/report?result=received');
            } catch (\PDOException) {
                $this->view(503,'Reporting unavailable','<p>Report could not be saved. Try again later.</p>');
            } catch (\RuntimeException $e) {
                $this->view(422,'Invalid report','<p>'.self::e($e->getMessage()).'</p>');
            }
            return;
        }
        $prefill=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $html='<p class="intro">Report suspected nonconsensual, underage, privacy-invasive or unsafe material. Reports quarantine the content pending review. Do not submit identity documents or sensitive personal data here.</p>';
        if (($_GET['result']??'')==='received') $html.='<p role="status">Your report has been received if the content ID exists. Thank you.</p>';
        $html.='<section class="panel"><form method="post" action="/members/report" class="install-form">'
            .'<input type="hidden" name="csrf" value="'.self::e(MemberSession::csrf()).'">'
            .'<label for="report-id">Content ID</label><input id="report-id" name="content_id" type="number" min="1" required value="'.($prefill!==false?(int)$prefill:'').'">'
            .'<label for="report-reason">Reason</label><textarea id="report-reason" name="reason" minlength="10" maxlength="1000" rows="5" required></textarea>'
            .'<button class="install-button" type="submit">Submit safety report</button></form></section>';
        $this->view(200,'Report content',$html);
    }

    private function form(string $path, string $method, MemberAccounts $accounts): void
    {
        $register = $path === '/join'; $error = null;
        if ($method === 'POST') {
            if (!MemberSession::csrfValid($_POST['csrf'] ?? null)) { $this->view(403,'Invalid token','<p>Reload and try again.</p>'); return; }
            $email=$_POST['email']??null; $password=$_POST['password']??null;
            if (!is_string($email)||!is_string($password)) { $error='Invalid email or password.'; }
            else {
                try {
                    // File-based throttling is intentionally shared with auth; rate-limited signups.
                    (new LoginRateLimiter($this->root . '/storage', 'member'))->consume((string)($_SERVER['REMOTE_ADDR']??'unknown'), $email);
                    if ($register) {
                        $accounts->register($email,$password);
                        $this->view(200,'Registration received','<p>Your account is pending review. Registration alone never grants access to adult content. <a href="/members/login">Sign in</a>.</p>');
                        return;
                    }
                    $member = $accounts->verify($email, $password);
                    if ($member !== null) { MemberSession::login($member['id']); $this->redirect('/members'); return; }
                    $error='Invalid email or password.';
                } catch (Throwable) { $error=$register ? 'Registration unavailable or email already in use.' : 'Invalid credentials or temporarily unavailable.'; }
            }
            http_response_code(400);
        }
        $title=$register?'Create a member account':'Member sign in';
        $html=($error ? '<div class="alert-error" role="alert">'.self::e($error).'</div>':'')
            . '<p class="intro">' . ($register
                ? 'Registration is for adults only. This development version creates a pending account; it does not provide age verification or paid content access.'
                : 'Sign in to view your account status. Private content is not enabled.') . '</p>'
            . '<section class="panel"><form method="post" action="'.$path.'" class="install-form">'
            . '<input type="hidden" name="csrf" value="'.MemberSession::csrf().'">'
            . '<label for="email">Email</label><input id="email" type="email" name="email" required maxlength="190" autocomplete="email">'
            . '<label for="password">Password</label><input id="password" type="password" name="password" required minlength="'.($register?'12':'1').'" maxlength="72" autocomplete="'.($register?'new-password':'current-password').'">'
            . '<button class="install-button">'.($register?'Register':'Sign in').'</button></form></section>'
            . '<p><a href="'.($register?'/members/login':'/join').'">'.($register?'Already registered? Sign in':'Create an account').'</a></p>';
        $this->view(http_response_code()===400?400:200,$title,$html);
    }

    /** @return array{id:int,email:string,status:string}|null */
    private function member(MemberAccounts $accounts): ?array
    {
        $id=MemberSession::id();return $id===null?null:$accounts->byId($id);
    }

    private function view(int $status, string $title, string $content): void
    {
        http_response_code($status);
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>'.self::e($title).' · Adult Core</title><link rel="stylesheet" href="/assets/style.css"><link rel="stylesheet" href="/theme.css"></head>'
            . '<body><div class="shell"><header class="topbar"><a href="/">Home</a><span>Membership accounts</span></header>'
            . '<main class="admin-login"><h1>'.self::e($title).'</h1>'.$content.'</main><footer>Adult Core · Self-hosted</footer></div></body></html>';
    }

    private function denyMethod(string $allowed): void { header('Allow: '.$allowed);$this->view(405,'Method not allowed','<p>Unsupported method.</p>'); }
    private function redirect(string $path): void {header('Location: '.$path,true,303);}
    private static function e(string $v): string {return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
