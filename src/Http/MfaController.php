<?php

declare(strict_types=1);

namespace AdultCore\Http;

use AdultCore\Auth\AdminAccounts;
use AdultCore\Auth\AdminMfa;
use AdultCore\Auth\AdminSession;
use AdultCore\Auth\LoginRateLimiter;
use AdultCore\Auth\RolePolicy;
use AdultCore\Database\Connection;
use AdultCore\Licensing\LicenseManager;
use AdultCore\UI\Layout;
use RuntimeException;
use Throwable;

/** Two-step sign-in plus owner-reauthenticated authenticator enrollment. */
final class MfaController
{
    public function __construct(private readonly string $root) {}

    public function challenge(string $method): void
    {
        if (!in_array($method, ['GET','POST'], true)) { header('Allow: GET, POST'); http_response_code(405); return; }
        $id = AdminSession::pendingMfaId();
        if ($id === null) { header('Location: /admin/login',true,302); return; }
        $error = null;
        if ($method === 'POST') {
            if (!AdminSession::csrfValid($_POST['csrf'] ?? null)) { http_response_code(403); $error='Invalid security token.'; }
            else {
                $factor = $_POST['factor'] ?? null;
                if (!is_string($factor) || strlen($factor) > 64) { $error='Invalid authenticator or recovery code.'; }
                else {
                    try {
                        (new LoginRateLimiter($this->root.'/storage','admin-mfa'))->consume(
                            (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 'mfa-user-'.$id
                        );
                        $pdo=Connection::open($this->root);
                        $admin=(new AdminAccounts($pdo))->authorizedById($id);
                        if ($admin !== null && (new AdminMfa($pdo,$this->root))->enabledFor($id)
                            && (new AdminMfa($pdo,$this->root))->consume($id,trim($factor))) {
                            AdminSession::authenticate($id,null,true);
                            $route=(new LicenseManager($this->root))->status()==='valid'?'/admin':'/admin/license';
                            header('Location: '.$route,true,303);return;
                        }
                        $error='Invalid authenticator or recovery code.';
                    } catch (Throwable) {
                        $error='Verification unavailable or rate limited. Please try later.';
                    }
                }
                http_response_code(401);
            }
        }
        $html='<div class="admin-login"><p class="eyebrow">ADDITIONAL VERIFICATION</p><h1>Authenticator verification</h1>'
            .'<p class="intro">Enter the six-digit code from your authenticator app, or one unused recovery code.</p>'
            .($error===null?'':'<div class="alert-error" role="alert">'.self::e($error).'</div>')
            .'<section class="panel"><form class="install-form" method="post" action="/admin/mfa" autocomplete="off">'
            .'<input type="hidden" name="csrf" value="'.self::e(AdminSession::csrf()).'">'
            .'<label for="mfa-factor">Authenticator or recovery code</label><input id="mfa-factor" name="factor" required maxlength="64" autocomplete="one-time-code" inputmode="text">'
            .'<button type="submit" class="install-button">Complete sign in</button></form>'
            .'<p class="muted">Recovery codes are single-use. If you lose your phone and codes, contact the server owner for a verified offline recovery process.</p></section></div>';
        echo Layout::guest('Verify administrator login',$html);
    }

    /** @param array{id:int,email:string,role:string} $admin */
    public function security(string $method,array $admin): void
    {
        if (!RolePolicy::allows($admin['role'],'site.settings.manage')) { http_response_code(403); return; }
        if (!in_array($method,['GET','POST'],true)) { header('Allow: GET, POST');http_response_code(405);return; }
        $error=null;$codes=[];
        try {
            $pdo=Connection::open($this->root);
            $mfa=new AdminMfa($pdo,$this->root);
            $ready=$mfa->ready();
            $enabled=$mfa->enabledFor($admin['id']);
            if ($method==='POST') {
                if (!AdminSession::csrfValid($_POST['csrf'] ?? null)) {
                    http_response_code(403); $error='Invalid security token.';
                } else {
                    try {
                        $action=$_POST['action'] ?? null;
                        if (!is_string($action) || !in_array($action,['begin','confirm','disable'],true)) {
                            throw new RuntimeException('Invalid security action.');
                        }
                        (new LoginRateLimiter($this->root.'/storage','admin-security'))->consume(
                            (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 'mfa-settings-'.$admin['id']
                        );
                        if ($action === 'begin' && !$enabled && $ready) {
                            $this->reauthenticate($pdo,$admin,$_POST['password'] ?? null);
                            AdminSession::setEnrollmentSecret($admin['id'],$mfa->newSecret());
                            header('Location: /admin/security',true,303);return;
                        }
                        if ($action === 'confirm' && !$enabled && $ready) {
                            $secret=AdminSession::enrollmentSecret($admin['id']);
                            $factor=$_POST['factor'] ?? null;
                            if ($secret===null || !is_string($factor)) throw new RuntimeException('Enrollment expired. Start again.');
                            $codes=$mfa->enable($admin['id'],$secret,trim($factor));
                            AdminSession::authenticate($admin['id'],null,true);
                            $enabled=true;
                        } elseif ($action === 'disable' && $enabled) {
                            $this->reauthenticate($pdo,$admin,$_POST['password'] ?? null);
                            $factor=$_POST['factor'] ?? null;
                            if (!is_string($factor) || !$mfa->consume($admin['id'],trim($factor))) {
                                throw new RuntimeException('Authenticator or recovery code was not accepted.');
                            }
                            $mfa->disable($admin['id']);
                            AdminSession::authenticate($admin['id'],null,false);
                            header('Location: /admin/security?disabled=1',true,303);return;
                        } else {
                            throw new RuntimeException('Requested MFA operation is unavailable.');
                        }
                    } catch (Throwable) {
                        http_response_code(422);
                        $error='The requested security change could not be verified. Check your password, code, and setup state.';
                    }
                }
            }
            $pending=AdminSession::enrollmentSecret($admin['id']);
            $html='<p class="eyebrow">ACCOUNT SECURITY</p><h1>Two-factor authentication</h1>'
                .'<p class="intro">Protect administrator sign-in using a standard TOTP authenticator app. Your private key never leaves this installation.</p>'
                .($error===null?'':'<div class="alert-error" role="alert">'.self::e($error).'</div>')
                .'<section class="panel"><h2>'.($enabled?'Authenticator enabled':'Authenticator not enabled').'</h2>';
            if (!$ready) {
                $html.='<p>Database migration required. Back up the database and apply <a href="/admin/setup">site setup</a> before enrolling.</p>';
            } elseif ($codes!==[]) {
                $html.='<div role="status"><h3>Save these 10 recovery codes now</h3><p>Each code works once. This page is the only time they will be shown; store them offline, separate from the password.</p>'
                    .'<pre class="mfa-recovery-codes">'.self::e(implode("\n",$codes)).'</pre></div>';
            } elseif ($enabled) {
                $remaining=$mfa->remainingRecoveryCodes($admin['id']);
                $html.='<p><strong>Remaining recovery codes:</strong> '.(int)$remaining.'</p><p class="muted">Disabling MFA requires your password AND a current authenticator or unused recovery code. Keep MFA enabled unless replacing your device.</p>'
                    .'<form class="install-form" method="post" action="/admin/security" autocomplete="off">'
                    .'<input type="hidden" name="csrf" value="'.self::e(AdminSession::csrf()).'"><input type="hidden" name="action" value="disable">'
                    .'<label for="disable-password">Current administrator password</label><input type="password" name="password" id="disable-password" maxlength="72" required autocomplete="current-password">'
                    .'<label for="disable-factor">Authenticator or recovery code</label><input id="disable-factor" name="factor" maxlength="64" required autocomplete="one-time-code">'
                    .'<button class="btn btn-outline" type="submit">Disable two-factor authentication</button></form>';
            } elseif ($pending!==null) {
                $html.='<p>In your authenticator app, add a time-based account with the following private setup key (SHA-1, 6 digits, 30 seconds). Do not share it.</p>'
                    .'<p class="mfa-setup-secret"><code>'.self::e($pending).'</code></p>'
                    .'<form class="install-form" method="post" action="/admin/security" autocomplete="off">'
                    .'<input type="hidden" name="csrf" value="'.self::e(AdminSession::csrf()).'"><input type="hidden" name="action" value="confirm">'
                    .'<label for="confirm-factor">Enter a current six-digit authenticator code</label><input name="factor" id="confirm-factor" maxlength="6" required inputmode="numeric" autocomplete="one-time-code">'
                    .'<button class="install-button" type="submit">Confirm and enable MFA</button></form>';
            } else {
                $html.='<p>For security, re-enter your current password to begin setup. Enrollment expires after 10 minutes.</p>'
                    .'<form class="install-form" method="post" action="/admin/security" autocomplete="off">'
                    .'<input type="hidden" name="csrf" value="'.self::e(AdminSession::csrf()).'"><input type="hidden" name="action" value="begin">'
                    .'<label for="enroll-password">Current administrator password</label><input type="password" name="password" id="enroll-password" maxlength="72" required autocomplete="current-password">'
                    .'<button class="install-button" type="submit">Set up authenticator</button></form>';
            }
            $html.='</section>';
            if (($_GET['disabled'] ?? '')==='1') $html.='<p role="status">Authenticator disabled.</p>';
            echo Layout::admin('Account security',$html,'/admin/security',AdminSession::csrf(),(new LicenseManager($this->root))->config()['edition']??'unknown');
        } catch (Throwable) {
            http_response_code(503);
            echo Layout::admin('Security unavailable','<section class="panel"><h1>Security settings unavailable</h1><p>Restore the database or private application key before continuing.</p></section>',
                '/admin/security',AdminSession::csrf());
        }
    }

    private function reauthenticate(\PDO $pdo,array $admin,mixed $password): void
    {
        if (!is_string($password) || strlen($password)<1 || strlen($password)>72
            || ((new AdminAccounts($pdo))->verifyCredentials($admin['email'],$password)['id'] ?? null) !== $admin['id']) {
            throw new RuntimeException('Password reauthentication failed.');
        }
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8');
    }
}
