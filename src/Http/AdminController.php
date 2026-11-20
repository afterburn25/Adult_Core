<?php

declare(strict_types=1);

namespace AdultCore\Http;

use AdultCore\Auth\AdminAccounts;
use AdultCore\Auth\AdminSession;
use AdultCore\Auth\LoginRateLimiter;
use AdultCore\Auth\RolePolicy;
use AdultCore\Database\Connection;
use AdultCore\Capability\HostInspector;
use AdultCore\Package\PackageStore;
use AdultCore\UI\Layout;
use AdultCore\UI\Dashboard;
use PDO;
use Throwable;

/** M3 secure login and read-only admin dashboard, routed only after InstallationGate::READY. */
final class AdminController
{
    public function __construct(private readonly string $root)
    {
    }

    public function handle(string $path, string $method): void
    {
        $https = in_array(strtolower((string) ($_SERVER['HTTPS'] ?? '')), ['on', '1'], true);
        $loopback = in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
        if (!$https && !$loopback) {
            $this->error(403, 'HTTPS required', 'Administrator access requires HTTPS.');
            return;
        }
        try {
            AdminSession::start($this->root, $https);
        } catch (Throwable) {
            $this->error(503, 'Session storage unavailable', 'Private session storage could not be initialized.');
            return;
        }

        if ($path === '/admin/login') {
            if ($method !== 'GET' && $method !== 'POST') {
                $this->methodNotAllowed('GET, POST');
                return;
            }
            $this->login($method);
            return;
        }

        if ($path === '/admin/logout') {
            if ($method !== 'POST') {
                $this->methodNotAllowed('POST');
                return;
            }
            if (!AdminSession::csrfValid($_POST['csrf'] ?? null)) {
                $this->error(403, 'Invalid security token', 'Your session token is invalid.');
                return;
            }
            AdminSession::logout();
            $this->redirect('/admin/login');
            return;
        }

        $admin = $this->currentAdmin();
        if ($admin === null) {
            $this->redirect('/admin/login', 302);
            return;
        }
        if ($path === '/admin/design' || $path === '/admin/design/upload' || str_starts_with($path, '/admin/design/asset/')) {
            (new DesignController($this->root))->handle($path, $method, $admin);
            return;
        }
        if ($path === '/admin/moderation') {
            (new ModerationController($this->root))->handle($method, $admin);
            return;
        }
        if (in_array($path, ['/admin/setup', '/admin/pages', '/admin/page', '/admin/members'], true)) {
            (new SiteAdminController($this->root))->handle($path, $method, $admin);
            return;
        }
        if ($path === '/admin/packages') {
            $this->packages($method, $admin);
            return;
        }
        if ($path !== '/admin') {
            $this->error(404, 'Page not found', 'This administrator route does not exist.');
            return;
        }
        if ($method !== 'GET') {
            $this->methodNotAllowed('GET');
            return;
        }
        if (!RolePolicy::allows($admin['role'], 'admin.dashboard.view')) {
            $this->error(403, 'Access denied', 'You are not permitted to view this dashboard.');
            return;
        }
        try {
            $pdo = Connection::open($this->root);
            $siteName = (string) ($pdo->query("SELECT setting_value FROM ac_settings WHERE setting_key = 'site_name'")->fetchColumn() ?: 'Adult Core');
            $edition = (string) ($pdo->query('SELECT edition FROM ac_installations WHERE id = 1')->fetchColumn() ?: 'unknown');
            $members = (int) $pdo->query('SELECT COUNT(*) FROM ac_users')->fetchColumn();
            $this->page('Dashboard', self::dashboardContent($siteName, $edition, $members, $admin));
        } catch (Throwable) {
            $this->error(503, 'Database unavailable', 'Administrator dashboard could not load. Please check database connectivity.');
        }
    }

    private function login(string $method): void
    {
        $admin = $this->currentAdmin();
        if ($admin !== null) {
            $this->redirect('/admin');
            return;
        }
        $error = null;
        if ($method === 'POST') {
            if (!AdminSession::csrfValid($_POST['csrf'] ?? null)) {
                $this->error(403, 'Invalid security token', 'Reload the login form and try again.');
                return;
            }
            $email = $_POST['email'] ?? null;
            $password = $_POST['password'] ?? null;
            if (!is_string($email) || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)
                || !is_string($password) || strlen($password) > 72 || strlen($password) === 0) {
                $error = 'Invalid email or password.';
            } else {
                try {
                    (new LoginRateLimiter($this->root . '/storage'))->consume(
                        (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), $email,
                    );
                    $account = (new AdminAccounts(Connection::open($this->root)))->verifyCredentials($email, $password);
                    if ($account === null) {
                        $error = 'Invalid email or password.';
                    } else {
                        AdminSession::authenticate($account['id']);
                        $this->redirect('/admin');
                        return;
                    }
                } catch (\RuntimeException $e) {
                    $error = $e->getMessage() === 'Too many login attempts. Please try again later.'
                        ? 'Too many login attempts. Please try again later.'
                        : 'Login is temporarily unavailable. Please try again later.';
                } catch (Throwable) {
                    $error = 'Login is temporarily unavailable. Please try again later.';
                }
            }
            http_response_code(401);
        }
        $csrf = AdminSession::csrf();
        $alert = $error === null ? '' : '<div class="alert-error" role="alert">' . self::escape($error) . '</div>';
        $content = '<div class="admin-login"><p class="eyebrow">PRIVATE ADMINISTRATION</p><h1>Sign in</h1>'
            . '<p class="intro">Use the administrator account created during installation.</p>'
            . $alert . '<section class="panel"><form method="post" action="/admin/login" class="install-form">'
            . '<input type="hidden" name="csrf" value="' . self::escape($csrf) . '">'
            . '<label for="admin-email">Email address</label><input id="admin-email" name="email" type="email" autocomplete="username" required maxlength="190">'
            . '<label for="admin-password">Password</label><input id="admin-password" name="password" type="password" autocomplete="current-password" required maxlength="72">'
            . '<button class="install-button" type="submit">Sign in</button></form></section></div>';
        $this->page('Administrator login', $content);
    }

    /** @return array{id:int,email:string,role:string}|null */
    private function currentAdmin(): ?array
    {
        $id = AdminSession::authenticatedId();
        if ($id === null) {
            return null;
        }
        try {
            $admin = (new AdminAccounts(Connection::open($this->root)))->authorizedById($id);
        } catch (Throwable) {
            // Database failures must never produce an authenticated response.
            return null;
        }
        if ($admin === null) {
            AdminSession::revoke();
        }
        return $admin;
    }

    /** @param array{id:int,email:string,role:string} $admin */
    private static function dashboardContent(string $siteName, string $edition, int $members, array $admin): string
    {
        return Dashboard::render($siteName, $edition, $members, $admin);
    }

    /** @param array{id:int,email:string,role:string} $admin */
    private function packages(string $method, array $admin): void
    {
        if (!RolePolicy::allows($admin['role'], 'modules.manage')) {
            $this->error(403, 'Access denied', 'You are not permitted to manage extensions.');
            return;
        }
        if (!in_array($method, ['GET', 'POST'], true)) {
            $this->methodNotAllowed('GET, POST');
            return;
        }
        $error = null;
        $success = (($_GET['result'] ?? '') === 'saved') ? 'Package action completed successfully.' : null;
        try {
            $installedState = json_decode((string) file_get_contents($this->root . '/storage/install-state.json'), true, 8, JSON_THROW_ON_ERROR);
            $edition = $installedState['edition'] ?? null;
            if (!in_array($edition, ['lite', 'pro'], true)) {
                throw new \RuntimeException('Installed edition could not be verified.');
            }
            $host = new HostInspector($this->root);
            $detected = $host->inspect($_SERVER['DOCUMENT_ROOT'] ?? null);
            $capabilities = $detected['capabilities'];
            $store = new PackageStore($this->root);
            if ($method === 'POST') {
                if (!AdminSession::csrfValid($_POST['csrf'] ?? null)) {
                    $this->error(403, 'Invalid security token', 'Reload the package manager and try again.');
                    return;
                }
                try {
                    $action = $_POST['action'] ?? null;
                    if ($action === 'install') {
                        $file = $_FILES['package'] ?? null;
                        if (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK
                            || !is_string($file['tmp_name'] ?? null)
                            || !is_uploaded_file($file['tmp_name'])) {
                            throw new \RuntimeException('Choose a ZIP package within the upload limit.');
                        }
                        $store->install($file['tmp_name'], $edition, $capabilities);
                    } elseif ($action === 'enable' || $action === 'disable') {
                        $type = $_POST['type'] ?? null;
                        $id = $_POST['id'] ?? null;
                        if (!is_string($type) || !is_string($id)) {
                            throw new \RuntimeException('Invalid package action.');
                        }
                        $store->changeStatus($type, $id, $action === 'enable', $edition, $capabilities);
                    } else {
                        throw new \RuntimeException('Invalid package action.');
                    }
                    $this->redirect('/admin/packages?result=saved');
                    return;
                } catch (\RuntimeException $e) {
                    $error = $e->getMessage();
                    http_response_code(422);
                } catch (Throwable) {
                    $error = 'Package action could not be completed. Check the server requirements.';
                    http_response_code(422);
                }
            }
            $packages = $store->listPackages($edition, $capabilities);
            $this->page('Add-ons and themes', self::packagesContent($edition, $packages, $error, $success));
        } catch (Throwable) {
            $this->error(503, 'Package manager unavailable', 'Could not access the private package store.');
        }
    }

    /** @param list<array{id:string,type:string,name:string,version:string,enabled:bool,publisher:string,blocked:list<string>}> $packages */
    private static function packagesContent(string $edition, array $packages, ?string $error, ?string $success): string
    {
        $csrf = self::escape(AdminSession::csrf());
        $notice = $error === null ? '' : '<div class="alert-error" role="alert">' . self::escape($error) . '</div>';
        if ($success !== null) {
            $notice .= '<div class="panel">' . self::escape($success) . '</div>';
        }
        $out = '<div class="admin-heading"><div><p class="eyebrow">ADMINISTRATION</p><h1>Add-ons and themes</h1>'
            . '<p class="muted">' . self::escape(strtoupper($edition)) . ' edition · Publisher-signed data packages only</p></div>'
            . '<a class="button-secondary" href="/admin">Dashboard</a></div>' . $notice
            . '<section class="panel"><h2>Install signed ZIP</h2><p>Before uploading, configure a trusted publisher public key '
            . 'in the private config/trusted-publishers directory. Uploaded packages are not executed automatically.</p>'
            . '<form method="post" action="/admin/packages" enctype="multipart/form-data" class="install-form">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '">'
            . '<input type="hidden" name="action" value="install">'
            . '<label for="extension-upload">Package ZIP (max 8 MiB)</label>'
            . '<input id="extension-upload" type="file" name="package" accept=".zip,application/zip" required>'
            . '<button class="install-button" type="submit">Verify and install</button></form></section>'
            . '<section class="panel packages-list"><h2>Installed packages</h2>';
        if ($packages === []) {
            $out .= '<p>No packages installed yet. The example manifests in modules/ are demonstrations, not installed extensions.</p>';
        }
        foreach ($packages as $package) {
            $status = $package['enabled'] ? 'Enabled' : 'Disabled';
            $out .= '<div class="module"><div class="module-head"><strong>' . self::escape($package['name']) . '</strong>'
                . '<span class="pill ' . ($package['enabled'] ? 'pill-yes' : 'pill-no') . '">' . $status . '</span></div>'
                . '<small>' . self::escape($package['type']) . ' · ' . self::escape($package['id'])
                . ' · v' . self::escape($package['version']) . ' · ' . self::escape($package['publisher']) . '</small>';
            foreach ($package['blocked'] as $reason) {
                $out .= '<p class="muted">Unavailable: ' . self::escape($reason) . '</p>';
            }
            $out .= '<form method="post" action="/admin/packages" class="package-action">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '">'
                . '<input type="hidden" name="type" value="' . self::escape($package['type']) . '">'
                . '<input type="hidden" name="id" value="' . self::escape($package['id']) . '">'
                . '<input type="hidden" name="action" value="' . ($package['enabled'] ? 'disable' : 'enable') . '">'
                . '<button class="button-secondary" type="submit"'
                . (!$package['enabled'] && $package['blocked'] !== [] ? ' disabled' : '') . '>'
                . ($package['enabled'] ? 'Disable' : 'Enable') . '</button></form></div>';
        }
        return $out . '</section><section class="notice"><p>Activation records are implemented; a runtime extension API and '
            . 'theme renderer are not implemented yet. No uploaded PHP or JavaScript is executed.</p></section>';
    }

    private function error(int $status, string $heading, string $message): void
    {
        http_response_code($status);
        $this->page($heading, '<section class="notice"><h1>' . self::escape($heading) . '</h1><p>' . self::escape($message) . '</p></section>');
    }

    private function methodNotAllowed(string $allowed): void
    {
        header('Allow: ' . $allowed);
        $this->error(405, 'Method not allowed', 'This request method is not supported.');
    }

    private function redirect(string $path, int $code = 303): void
    {
        header('Location: ' . $path, true, $code);
    }

    private function page(string $title, string $content): void
    {
        // Guest/error pages must never assume an authenticated administrator.
        if (AdminSession::authenticatedId() === null) {
            echo Layout::guest($title, $content);
            return;
        }
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/admin'), PHP_URL_PATH);
        echo Layout::admin($title, $content, is_string($path) ? $path : '/admin', AdminSession::csrf());
    }

    private static function escape(string $input): string
    {
        return htmlspecialchars($input, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
