<?php

declare(strict_types=1);

namespace AdultCore\Http;

use AdultCore\Auth\LoginRateLimiter;
use AdultCore\Creator\CreatorApplications;
use AdultCore\Creator\CreatorSchema;
use AdultCore\Database\Connection;
use AdultCore\Member\MemberAccounts;
use AdultCore\Member\MemberSchema;
use AdultCore\Member\MemberEmailSchema;
use AdultCore\Member\PasswordRecovery;
use AdultCore\Member\PasswordRecoverySchema;
use AdultCore\Member\EmailConfirmations;
use AdultCore\Member\EmailDelivery;
use AdultCore\Member\MemberSession;
use AdultCore\Moderation\ModerationRepository;
use AdultCore\Moderation\ModerationSchema;
use AdultCore\Membership\MemberLibrary;
use AdultCore\Membership\MembershipSchema;
use AdultCore\Membership\MemberSubscriptionSummary;
use AdultCore\UI\Layout;
use PDO;
use RuntimeException;
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
            $emailMigration = $pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
            $emailMigration->execute([MemberEmailSchema::VERSION]);
            if ((int)$emailMigration->fetchColumn() !== 1) {
                $this->view(503,'Setup required','<p>Apply the latest additive database migrations from the admin dashboard.</p>'); return;
            }
            $recoveryMigration = $pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
            $recoveryMigration->execute([PasswordRecoverySchema::VERSION]);
            if ((int)$recoveryMigration->fetchColumn() !== 1) {
                $this->view(503,'Setup required','<p>Apply the latest member security migration from /admin/setup.</p>'); return;
            }
            $accounts = new MemberAccounts($pdo);
            if ($path === '/members/recover' || $path === '/members/recover/reset') {
                $this->recovery($pdo, $path, $method);
                return;
            }
            if ($path === '/members/email/confirm') {
                if (!in_array($method,['GET','POST'],true)) { $this->denyMethod('GET, POST'); return; }
                $token = $method==='GET' ? ($_GET['token']??null) : ($_POST['token']??null);
                if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/D',$token)!==1) {
                    $this->view(400,'Email confirmation','<p>Invalid or expired confirmation link.</p>'); return;
                }
                if ($method==='GET') {
                    // Email scanners and browser link previews must NEVER consume the token.
                    $this->view(200,'Confirm email address','<p>To confirm ownership of this email address, press Confirm. This does not verify your age or unlock content.</p>'
                        .'<form method="post" action="/members/email/confirm" class="install-form">'
                        .'<input type="hidden" name="csrf" value="'.self::e(MemberSession::csrf()).'">'
                        .'<input type="hidden" name="token" value="'.self::e($token).'">'
                        .'<button class="install-button" type="submit">Confirm email address</button></form>');
                    return;
                }
                if (!MemberSession::csrfValid($_POST['csrf']??null)) {
                    $this->view(403,'Invalid token','<p>Reload the confirmation link and retry.</p>'); return;
                }
                $ok=(new EmailConfirmations($pdo))->confirm($token);
                $this->view($ok?200:400,'Email confirmation', $ok
                    ? '<p>Your email address is confirmed. This does not verify age or grant membership access.</p><p><a href="/members/login">Sign in</a></p>'
                    : '<p>Invalid or expired confirmation link. Sign in to request another link.</p>');
                return;
            }
            if ($path === '/members/email') {
                $this->emailConfirmation($pdo,$method,$accounts);
                return;
            }
            if ($path === '/members/logout') {
                if ($method !== 'POST') { $this->denyMethod('POST'); return; }
                if (!MemberSession::csrfValid($_POST['csrf'] ?? null)) { $this->view(403,'Invalid token','<p>Reload and retry.</p>'); return; }
                MemberSession::logout(); $this->redirect('/'); return;
            }
            if ($path === '/members/report') {
                $this->report($pdo, $method, $accounts);
                return;
            }
            if ($path === '/members/creator') {
                $this->creator($pdo,$method,$accounts);
                return;
            }
            if (str_starts_with($path, '/members/library/')) {
                if ($method !== 'GET') { $this->denyMethod('GET'); return; }
                if (preg_match('#^/members/library/([1-9][0-9]{0,17})$#D', $path, $match) !== 1) {
                    $this->view(404,'Not found','<p>Gallery unavailable.</p>'); return;
                }
                $member=$this->member($accounts);
                if ($member===null) { $this->redirect('/members/login'); return; }
                $gallery=(new MemberLibrary($pdo))->detail((int)$member['id'],(int)$match[1]);
                if ($gallery===null) {
                    // An unauthorized, unapproved or missing item is the
                    // same external response: no gallery metadata disclosure.
                    $this->view(404,'Not found','<p>Gallery unavailable.</p>'); return;
                }
                $this->view(200, 'Private gallery', MemberLibrary::renderDetail($gallery));
                return;
            }
            if ($path === '/members/library') {
                if ($method !== 'GET') { $this->denyMethod('GET'); return; }
                $member = $this->member($accounts);
                if ($member === null) { $this->redirect('/members/login'); return; }
                $library = (new MemberLibrary($pdo))->visible((int)$member['id']);
                $this->view(200, 'Private member library',
                    '<p>Only approved content authorized for your account appears here. '
                    . 'Unverified or unpublished content is never listed.</p>'
                    . MemberLibrary::render($library)
                    . '<p><a href="/members">Back to your account</a></p>');
                return;
            }
            if ($path === '/members') {
                if ($method !== 'GET') { $this->denyMethod('GET'); return; }
                $member = $this->member($accounts);
                if ($member === null) { $this->redirect('/members/login'); return; }
                $confirmed = (new EmailConfirmations($pdo))->verified((int)$member['id']);
                $subscriptionSchema=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
                $subscriptionSchema->execute([MembershipSchema::VERSION]);
                $subscriptionView=(int)$subscriptionSchema->fetchColumn()===1
                    ? MemberSubscriptionSummary::render((new MemberSubscriptionSummary($pdo))->forMember((int)$member['id']))
                    : '<p>Membership records have not been configured by the website administrator.</p>';
                $this->view(200,'Member account','<p>Signed in as <strong>' . self::e($member['email']) . '</strong>.</p>'
                    . '<p>Account status: <strong>' . self::e($member['status']) . '</strong>.</p>'
                    . '<p>Email address: <strong>' . ($confirmed ? 'confirmed' : 'unconfirmed') . '</strong>. <a href="/members/email">Email settings</a></p>'
                    . '<p>Private content and paid access remain unavailable until independent age verification, content approval, and payment integrations have been completed.</p>'
                    . $subscriptionView
                    . '<p><a href="/members/library">View your private library</a></p>'
                    . '<p><a href="/members/creator">Creator interest application (private, no publishing)</a></p>'
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

    /**
     * A logged-in member may prepare text-only creator application metadata.
     * No creator role, upload, adult age assertion or publication is conferred.
     */
    private function creator(PDO $pdo,string $method,MemberAccounts $accounts): void
    {
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');
        if (!in_array($method,['GET','POST'],true)) { $this->denyMethod('GET, POST'); return; }
        $member=$this->member($accounts);
        if ($member===null) { $this->redirect('/members/login'); return; }
        $check=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
        $check->execute([CreatorSchema::VERSION]);
        if ((int)$check->fetchColumn()!==1) {
            $this->view(503,'Creator intake setup required',
                '<p>Creator applications are not configured. Ask the website administrator to run Site setup.</p>');
            return;
        }
        $repo=new CreatorApplications($pdo);
        $confirmed=(new EmailConfirmations($pdo))->verified((int)$member['id']);
        $current=$repo->forMember((int)$member['id']);
        if ($method==='POST') {
            if (!MemberSession::csrfValid($_POST['csrf']??null)) {
                $this->view(403,'Invalid token','<p>Reload and try again.</p>');return;
            }
            try {
                $raw=$_POST['expected_revision']??null;
                if (!is_string($raw) || !is_string($_POST['display_name']??null)
                    || !is_string($_POST['introduction']??null)) {
                    throw new RuntimeException('Invalid application revision.');
                }
                $version=$raw===''?null:filter_var($raw,FILTER_VALIDATE_INT,
                    ['options'=>['min_range'=>1]]);
                if ($version===false) throw new RuntimeException('Invalid application revision.');
                (new LoginRateLimiter($this->root.'/storage','creator-intake'))->consume(
                    (string)($_SERVER['REMOTE_ADDR']??'unknown'),(string)$member['email']);
                $repo->submit((int)$member['id'],$_POST['display_name'],
                    $_POST['introduction'],$version);
                $this->redirect('/members/creator?result=saved');return;
            } catch (RuntimeException $e) {
                $allowed=[
                    'Choose a creator display name of 2–80 bytes without control characters.',
                    'Describe your creator plans in 20–500 bytes without private documents.',
                    'Invalid application revision.','Confirm your member email before applying.',
                    'Application changed; reload and try again.',
                    'This application cannot be resubmitted.',
                ];
                $message=in_array($e->getMessage(),$allowed,true)
                    ? $e->getMessage():'Application could not be saved.';
                $this->view(422,'Creator application not saved',
                    '<p role="alert">'.self::e($message).'</p>'
                    .'<p><a href="/members/creator">Return to the private application</a></p>');
                return;
            }
        }
        $html='<p class="intro">This is a private creator interest form only. '
            .'Do not submit identification documents, photos, explicit content, contact details, '
            .'or another person\'s information. Email confirmation does not verify age or identity. '
            .'No upload, membership, publishing or earning privileges are activated.</p>';
        if (($_GET['result']??'')==='saved') $html.='<p role="status">Your private application was saved. It is not approved.</p>';
        if ($current!==null) {
            $html.='<section class="panel"><h2>Application status</h2>'
                .'<p>Status: <strong>'.self::e((string)$current['status']).'</strong></p>'
                .'<p>Review feedback: '.self::e((string)$current['review_note']).'</p>'
                .'</section>';
        }
        if (!$confirmed) {
            $html.='<section class="panel"><p>Confirm your member email address before applying. '
                .'This is only inbox verification and never substitutes for independent adult age checks.</p>'
                .'<p><a href="/members/email">Email confirmation settings</a></p></section>';
        } elseif ($current!==null && $current['status']==='rejected') {
            $html.='<section class="panel"><p>This application is closed and cannot be resubmitted on this account.'
                .' Contact the website administrator for a separate review.</p></section>';
        } else {
            $html.='<section class="panel"><h2>'
                .($current===null?'Submit creator interest':'Update creator interest')
                .'</h2><form method="post" action="/members/creator" class="install-form">'
                .'<input type="hidden" name="csrf" value="'.self::e(MemberSession::csrf()).'">'
                .'<input type="hidden" name="expected_revision" value="'
                .($current===null?'':(int)$current['revision']).'">'
                .'<label>Preferred creator display name'
                .'<input name="display_name" minlength="2" maxlength="80" required value="'
                .($current===null?'':self::e((string)$current['display_name'])).'"></label>'
                .'<label>Tell us about your planned creative work (20–500 bytes, no private documents)'
                .'<textarea name="introduction" rows="5" minlength="20" maxlength="500" required>'
                .($current===null?'':self::e((string)$current['introduction'])).'</textarea></label>'
                .'<button class="install-button" type="submit">'
                .($current===null?'Send private application':'Save and resubmit for review')
                .'</button></form></section>';
        }
        $this->view(200,'Creator interest',$html);
    }

    /** Password recovery is available without a login and never activates memberships. */
    private function recovery(PDO $pdo, string $path, string $method): void
    {
        if (!in_array($method,['GET','POST'],true)) { $this->denyMethod('GET, POST'); return; }
        $service = new PasswordRecovery($pdo);
        $formToken = MemberSession::csrf();
        if ($path === '/members/recover') {
            if ($method==='POST') {
                if (!MemberSession::csrfValid($_POST['csrf']??null)) {
                    $this->view(403,'Invalid token','<p>Reload the form and try again.</p>'); return;
                }
                $candidate=$_POST['email']??null;
                if (!is_string($candidate) || strlen($candidate)>190 || filter_var($candidate,FILTER_VALIDATE_EMAIL)===false) {
                    $this->view(422,'Invalid email','<p>Enter a valid email address.</p>'); return;
                }
                try {
                    (new LoginRateLimiter($this->root.'/storage','member-recovery'))->consume(
                        (string)($_SERVER['REMOTE_ADDR']??'unknown'),$candidate);
                    $cfg=EmailDelivery::loadConfig($this->root);
                    if ($cfg!==null) {
                        $message=$service->issueForEmail($candidate);
                        if ($message!==null) EmailDelivery::sendPasswordReset($message['email'],$message['token'],$cfg);
                    }
                } catch (Throwable) {
                    // Deliberately neutral: no account-disclosure, mail provider, or cooldown information.
                }
                $this->view(200,'Recovery requested','<p>If the address belongs to an eligible, confirmed account and email delivery is available, a reset link will be sent.</p>');
                return;
            }
            $this->view(200,'Reset your password','<p>Enter the email address on your account. Only confirmed addresses can receive recovery links.</p>'
                .'<section class="panel"><form method="post" action="/members/recover" class="install-form">'
                .'<input type="hidden" name="csrf" value="'.self::e($formToken).'">'
                .'<label for="recovery-email">Email</label><input id="recovery-email" name="email" type="email" autocomplete="email" maxlength="190" required>'
                .'<button class="install-button">Request reset link</button></form></section>');
            return;
        }
        $token=$method==='GET'?($_GET['token']??null):($_POST['token']??null);
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token)) {
            $this->view(400,'Invalid reset link','<p>This reset link is invalid or expired.</p>'); return;
        }
        if ($method==='GET') {
            // Email link scanners may fetch GET requests; NEVER consume a reset token on GET.
            $this->view(200,'Choose a new password','<p>Choose a new password for this account. Resetting a password will not verify your age or enable access.</p>'
                .'<section class="panel"><form method="post" action="/members/recover/reset" class="install-form">'
                .'<input type="hidden" name="csrf" value="'.self::e($formToken).'">'
                .'<input type="hidden" name="token" value="'.self::e($token).'">'
                .'<label for="new-password">New password</label><input id="new-password" type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required>'
                .'<label for="repeat-password">Repeat new password</label><input id="repeat-password" type="password" name="confirm_password" minlength="12" maxlength="72" autocomplete="new-password" required>'
                .'<button class="install-button">Change password</button></form></section>');
            return;
        }
        if (!MemberSession::csrfValid($_POST['csrf']??null)) {
            $this->view(403,'Invalid token','<p>Reload the reset link and try again.</p>'); return;
        }
        $password=$_POST['password']??null;
        $repeat=$_POST['confirm_password']??null;
        if (!is_string($password) || !is_string($repeat) || !hash_equals($password,$repeat)) {
            $this->view(422,'Password mismatch','<p>Passwords must match.</p>'); return;
        }
        try {
            if (!$service->reset($token,$password)) {
                $this->view(400,'Reset link expired','<p>Invalid or expired reset link. Request another if needed.</p>'); return;
            }
            // Immediately discard any already authenticated session in this browser.
            MemberSession::revoke();
            $this->view(200,'Password changed','<p>Your password has been changed. Existing member sessions have been invalidated. <a href="/members/login">Sign in again</a>.</p>');
        } catch (RuntimeException) {
            $this->view(422,'Password reset unavailable','<p>Choose a password of 12–72 bytes and retry.</p>');
        } catch (Throwable) {
            $this->view(503,'Password reset unavailable','<p>The reset could not be completed. Please try again later.</p>');
        }
    }

    private function report(PDO $pdo, string $method, MemberAccounts $accounts): void
    {
        if (!in_array($method,['GET','POST'],true)) { $this->denyMethod('GET, POST'); return; }
        $member=$this->member($accounts);
        if ($member===null) { $this->redirect('/members/login'); return; }
        $q=$pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
        $q->execute([ModerationSchema::VERSION]);
        if ((int)$q->fetchColumn()!==1) { $this->view(503,'Reporting unavailable','<p>Reporting is not yet set up.</p>'); return; }
        if ($method==='POST') {
            if (!MemberSession::csrfValid($_POST['csrf']??null)) { $this->view(403,'Invalid token','<p>Reload and retry.</p>'); return; }
            $id=filter_var($_POST['content_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $reason=$_POST['reason']??null;
            if ($id===false || !is_string($reason)) { $this->view(422,'Invalid report','<p>Enter a valid content ID and reason.</p>'); return; }
            try {
                (new ModerationRepository($pdo))->submitReport((int)$member['id'],(int)$id,$reason);
                $this->redirect('/members/report?result=received');
            } catch (\PDOException) {
                $this->view(503,'Reporting unavailable','<p>The report could not be saved. Please try again later.</p>');
            } catch (\RuntimeException $e) {
                $this->view(422,'Invalid report','<p>'.self::e($e->getMessage()).'</p>');
            }
            return;
        }
        $prefill=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $html='<p class="intro">Report suspected nonconsensual, underage, privacy-invasive or otherwise unsafe material. Reports immediately quarantine the referenced content pending review. Never include identification documents or other sensitive personal data in this form.</p>';
        if (($_GET['result']??'')==='received') $html.='<p role="status">Your report has been received if the content ID exists. Thank you.</p>';
        $html.='<section class="panel"><form method="post" action="/members/report" class="install-form">'
            .'<input type="hidden" name="csrf" value="'.self::e(MemberSession::csrf()).'">'
            .'<label for="report-id">Content ID</label><input id="report-id" name="content_id" type="number" min="1" required value="'.($prefill!==false?(int)$prefill:'').'">'
            .'<label for="report-reason">Reason (10–1000 characters)</label><textarea id="report-reason" name="reason" required minlength="10" maxlength="1000" rows="5"></textarea>'
            .'<button class="install-button" type="submit">Submit safety report</button></form></section>';
        $this->view(200,'Report content', $html);
    }

    private function emailConfirmation(PDO $pdo, string $method, MemberAccounts $accounts): void
    {
        if (!in_array($method,['GET','POST'],true)) { $this->denyMethod('GET, POST'); return; }
        $member=$this->member($accounts);
        if ($member===null) { $this->redirect('/members/login'); return; }
        $service=new EmailConfirmations($pdo);
        $confirmed=$service->verified((int)$member['id']);
        $message='';
        $configured=false;
        try { $cfg=EmailDelivery::loadConfig($this->root); $configured=$cfg!==null; }
        catch (Throwable) { $cfg=null; }
        if ($method==='POST') {
            if (!MemberSession::csrfValid($_POST['csrf']??null)) {
                $this->view(403,'Invalid token','<p>Reload and retry.</p>'); return;
            }
            if ($confirmed) { $message='This address has already been confirmed.'; }
            elseif (!$configured) { $message='Email delivery is not configured by the website administrator.'; }
            else {
                try {
                    $token=$service->issue((int)$member['id']);
                    $sent=EmailDelivery::send((string)$member['email'],$token,$cfg);
                    $message=$sent?'Confirmation email requested. Check your inbox.':'Delivery failed. Contact the website administrator.';
                } catch (\RuntimeException $e) {
                    $message=$e->getMessage()==='Please wait before requesting another confirmation.'
                        ? $e->getMessage() : 'Could not request a confirmation link.';
                } catch (Throwable) { $message='Email confirmation is temporarily unavailable.'; }
            }
        }
        $html='<p class="intro">Email confirmation proves inbox ownership only. It does not verify age, identity, performer consent, or payment eligibility.</p>'
            .'<p>Address: '.self::e((string)$member['email']).'</p>'
            .'<p>Status: <strong>'.($confirmed?'Confirmed':'Not confirmed').'</strong></p>';
        if ($message!=='') $html.='<p role="status">'.self::e($message).'</p>';
        if (!$confirmed && $configured) {
            $html.='<form method="post" action="/members/email" class="install-form">'
                .'<input type="hidden" name="csrf" value="'.self::e(MemberSession::csrf()).'">'
                .'<button class="install-button" type="submit">Send confirmation email</button></form>';
        } elseif (!$confirmed) $html.='<p>Email delivery is not yet configured for this website.</p>';
        $html.='<p><a href="/members">Return to member account</a></p>';
        $this->view(200,'Email confirmation',$html);
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
                        $newMemberId=$accounts->register($email,$password);
                        try {
                            $mailCfg=EmailDelivery::loadConfig($this->root);
                            if ($mailCfg!==null) {
                                $token=(new EmailConfirmations(Connection::open($this->root)))->issue($newMemberId);
                                EmailDelivery::send(strtolower(trim($email)),$token,$mailCfg);
                            }
                        } catch (Throwable) {
                            // Registration is still pending. Never disclose transport or token details publicly.
                        }
                        $this->view(200,'Registration received','<p>Your account is pending review. Registration alone never grants access to adult content. <a href="/members/login">Sign in</a>.</p>');
                        return;
                    }
                    $member = $accounts->verify($email, $password);
                    if ($member !== null) { MemberSession::login($member['id'],(new PasswordRecovery(Connection::open($this->root)))->generation($member['id'])); $this->redirect('/members'); return; }
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
            . '<p><a href="'.($register?'/members/login':'/join').'">'.($register?'Already registered? Sign in':'Create an account').'</a></p>'
            . ($register?'':'<p><a href="/members/recover">Forgot your password?</a></p>');
        $this->view(http_response_code()===400?400:200,$title,$html);
    }

    /** @return array{id:int,email:string,status:string}|null */
    private function member(MemberAccounts $accounts): ?array
    {
        $id=MemberSession::id();
        if ($id === null) return null;
        $member=$accounts->byId($id);
        if ($member===null || MemberSession::generation() !== (new PasswordRecovery(Connection::open($this->root)))->generation($id)) {
            MemberSession::revoke();
            return null;
        }
        return $member;
    }

    private function view(int $status, string $title, string $content): void
    {
        http_response_code($status);
        echo Layout::member($title, $content);
    }

    private function denyMethod(string $allowed): void { header('Allow: '.$allowed);$this->view(405,'Method not allowed','<p>Unsupported method.</p>'); }
    private function redirect(string $path): void {header('Location: '.$path,true,303);}
    private static function e(string $v): string {return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
}
