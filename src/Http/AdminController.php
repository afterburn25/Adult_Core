<?php

declare(strict_types=1);

namespace AdultCore\Http;

use AdultCore\Auth\AdminAccounts;
use AdultCore\Auth\AdminMfa;
use AdultCore\Auth\AdminSession;
use AdultCore\Auth\LoginRateLimiter;
use AdultCore\Auth\RolePolicy;
use AdultCore\Database\Connection;
use AdultCore\Capability\HostInspector;
use AdultCore\Package\PackageStore;
use AdultCore\Licensing\LicenseManager;
use AdultCore\Licensing\EditionUpgrade;
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

        // This route accepts only short-lived password-verified challenge sessions.
        if ($path === '/admin/mfa') {
            (new MfaController($this->root))->challenge($method);
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
        if ($path === '/admin/security') {
            (new MfaController($this->root))->security($method, $admin);
            return;
        }
        // License repair remains available to a signed-in administrator even
        // when the rest of the site is blocked by the licensing gate.
        if ($path === '/admin/license') {
            $this->licensePage($method, $admin);
            return;
        }
        if ($path === '/admin/upgrade') {
            $this->upgradePage($method, $admin);
            return;
        }
        if ($path === '/admin/design' || $path === '/admin/design/upload' || str_starts_with($path, '/admin/design/asset/')) {
            (new DesignController($this->root))->handle($path, $method, $admin);
            return;
        }
        if (str_starts_with($path, '/admin/moderation/image/')) {
            (new ModerationController($this->root))->previewImage($path, $method, $admin);
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

    /** Only an existing admin can repair or replace the private license key. */
    private function licensePage(string $method, array $admin): void
    {
        if (!RolePolicy::allows($admin['role'], 'admin.dashboard.view')) {
            $this->error(403, 'Access denied', 'Administrator permission is required.');
            return;
        }
        if (!in_array($method, ['GET', 'POST'], true)) {
            $this->methodNotAllowed('GET, POST');
            return;
        }
        $manager = new LicenseManager($this->root);
        $error = null;
        $checked = null;
        $issuerVerdict = null;
        if ($method === 'POST') {
            if (!AdminSession::csrfValid($_POST['csrf'] ?? null)) {
                $this->error(403, 'Invalid security token', 'Reload the licensing form and try again.');
                return;
            }
            // Reading a dashboard must not authorize entitlement mutations.
            if (!RolePolicy::allows($admin['role'], 'site.settings.manage')) {
                $this->error(403, 'Access denied', 'Site management permission is required.');
                return;
            }
            try {
                $action = $_POST['license_action'] ?? 'replace';
                if (!is_string($action) || !in_array($action, ['replace', 'check'], true)) {
                    throw new \InvalidArgumentException('Choose a valid license action.');
                }
                // Limit both manual checks and license guesses. CWP also limits API calls by IP.
                (new \AdultCore\Installer\InstallRateLimiter($this->root . '/storage'))->consume(
                    'license:' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
                );
                if ($action === 'check') {
                    $checked = $manager->status(null, true);
                    $issuerVerdict = $manager->lastAuthorityResponse();
                } else {
                    $key = $_POST['license_key'] ?? null;
                    $domain = $_POST['license_domain'] ?? null;
                    if (!is_string($key) || !is_string($domain)) {
                        throw new \InvalidArgumentException('Enter your key and registered domain.');
                    }
                    $manager->replaceLicense($key, $domain);
                    $this->redirect('/admin/license?updated=1');
                    return;
                }
            } catch (\InvalidArgumentException | \RuntimeException $exception) {
                $error = $exception->getMessage();
                http_response_code(422);
            } catch (Throwable) {
                $error = 'License validation is temporarily unavailable. Try again later.';
                http_response_code(503);
            }
        }
        $saved = $manager->config();
        $state = $checked ?? $manager->status();
        $domain = $saved['domain'] ?? '';
        $content = '<p class="eyebrow">CAJUN WEB PRO · PRODUCT LICENSING</p><h1>Adult_Core license</h1>'
            . '<p class="intro">Activation is required to install and use Adult_Core. Licenses are issued and administered by Cajun Web Pro. '
            . 'Your key is stored privately and never displayed in this workspace.</p>'
            . '<section class="panel"><p><strong>Current status:</strong> ' . self::escape($state) . '</p>'
            . ($checked === null ? '' : '<p role="status" class="muted"><strong>Fresh validation:</strong> ' . self::escape(match ($issuerVerdict) {
                'valid' => 'Cajun Web Pro confirmed this license is active.',
                'denied' => 'Cajun Web Pro rejected this license; access is disabled.',
                'transient' => 'Cajun Web Pro could not be reached. Any remaining access is temporary signed outage grace, not a new approval.',
                default => 'No new issuer verdict was obtained. Check the registered domain or try again.',
            }) . '</p>')
            . '<p><strong>Licensed edition:</strong> ' . self::escape(strtoupper((string)($saved['edition'] ?? 'Unknown'))) . '</p>'
            . '<p><strong>Billing term:</strong> ' . self::escape(($saved['term'] ?? '') === 'month' ? 'Monthly subscription' : 'One-time / unconfigured') . '</p>'
            . (($saved['term'] ?? '') === 'month'
                ? '<p class="muted"><strong>Subscription billing:</strong> Renewals, the paid-through date, cancellation and failed-payment recovery are managed exclusively by Cajun Web Pro. This application receives only an authorized/denied verdict; it does not know your next charge date.</p>'
                : '')
            . '<p><strong>Product SKU:</strong> ' . self::escape((string)($saved['sku'] ?? 'not configured')) . '</p>'
. '<p><strong>Registered hostname:</strong> ' . self::escape($domain === '' ? 'not configured' : $domain) . '</p>'
            . '<p class="muted">The status is cached for up to one hour. Check now contacts Cajun Web Pro without displaying your key; a temporary outage is not proof of revocation.</p>'
            . '<form method="post" action="/admin/license"><input type="hidden" name="csrf" value="' . self::escape(AdminSession::csrf()) . '">'
            . '<input type="hidden" name="license_action" value="check"><button type="submit" class="btn btn-outline">Check license now</button></form>'
            . '</section>'
            . ($error !== null ? '<div class="alert-error" role="alert">' . self::escape($error) . '</div>' : '')
            . '<section class="panel"><h2>Activate or replace license</h2><form method="post" action="/admin/license" class="install-form" autocomplete="off">'
            . '<input type="hidden" name="csrf" value="' . self::escape(AdminSession::csrf()) . '">'
            . '<input type="hidden" name="license_action" value="replace">'
            . '<label for="license-key">License key</label><input id="license-key" name="license_key" type="password" required autocomplete="off" maxlength="255">'
            . '<label for="license-domain">Registered domain</label><input id="license-domain" name="license_domain" required maxlength="253" value="' . self::escape($domain) . '">'
            . '<button type="submit" class="install-button">Validate and save license</button></form>'
            . '<p class="muted">Replacing a key here cannot change Lite into Pro. To preserve your existing website, use the <a href="/admin/upgrade">verified Lite-to-Pro upgrade</a> with a purchased Pro license. <strong>Replacing or changing the installed key does not cancel an existing monthly subscription or stop recurring charges.</strong> Manage billing and cancellation directly at <a href="https://cajunwebpro.com" target="_blank" rel="noopener noreferrer">Cajun Web Pro</a> (temporary link to the main site).</p></section>';
        $this->page('License activation', $content);
    }

    /** Same-installation edition change, never a fresh installer or new database. */
    private function upgradePage(string $method, array $admin): void
    {
        if (!RolePolicy::allows($admin['role'], 'site.settings.manage')) {
            $this->error(403, 'Access denied', 'Administrator settings permission is required.');
            return;
        }
        if (!in_array($method, ['GET','POST'], true)) {
            $this->methodNotAllowed('GET, POST');
            return;
        }
        $manager = new LicenseManager($this->root);
        $licensed = $manager->config();
        $upgrade = new EditionUpgrade($this->root);
        $error = null;
        if ($method === 'POST') {
            if (!AdminSession::csrfValid($_POST['csrf'] ?? null)) {
                $this->error(403, 'Invalid security token', 'Reload the upgrade form and try again.');
                return;
            }
            try {
                if ($licensed === null || $licensed['edition'] !== 'lite') {
                    throw new \RuntimeException('This installation is not licensed as Lite.');
                }
                if (($_POST['backup_confirm'] ?? null) !== '1'
                    || ($_POST['upgrade_confirm'] ?? null) !== 'UPGRADE') {
                    throw new \RuntimeException('Confirm your offline backup and the upgrade action before continuing.');
                }
                $candidate = $_POST['pro_license_key'] ?? null;
                $password = $_POST['admin_password'] ?? null;
                if (!is_string($candidate) || !is_string($password) || strlen($password) > 72) {
                    throw new \InvalidArgumentException('Provide the Pro license key and current administrator password.');
                }
                (new \AdultCore\Installer\InstallRateLimiter($this->root . '/storage'))->consume(
                    'edition:' . (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
                );
                // Step-up credential check. An open administrator session is not
                // sufficient to mutate the edition and private license secrets.
                $identity = (new AdminAccounts(Connection::open($this->root)))->verifyCredentials($admin['email'], $password);
                if ($identity === null || $identity['id'] !== $admin['id']) {
                    throw new \RuntimeException('Administrator password verification failed.');
                }
                $upgrade->upgrade($candidate, $licensed['domain']);
                $this->redirect('/admin/license?upgraded=1');
                return;
            } catch (\InvalidArgumentException | \RuntimeException $exception) {
                // Vendor keys, passwords and external transport failures must not be echoed.
                $error = $exception->getMessage();
                http_response_code(422);
            } catch (Throwable) {
                $error = 'Edition upgrade unavailable. No license key was displayed.';
                http_response_code(503);
            }
        }
        $missing=[];
        try { $missing = $upgrade->prerequisites(); }
        catch (Throwable) { $missing = ['Host capability inspection unavailable']; }
        $pro = ($licensed['edition'] ?? null) === 'pro';
        $content = '<p class="eyebrow">EDITION MANAGEMENT</p><h1>Upgrade Lite to Pro</h1>'
            . '<p class="intro">Keep this installation and its database, pages, members, designs, and uploads. '
            . 'Your Pro license determines the upgraded edition; upgrading does not issue a license or charge a payment.</p>'
            . '<section class="panel"><h2>Upgrade readiness</h2>'
            . '<p><strong>Current edition:</strong> ' . self::escape(strtoupper((string)($licensed['edition'] ?? 'Unknown'))) . '</p>'
            . '<p><strong>Registered domain:</strong> ' . self::escape((string)($licensed['domain'] ?? 'Unavailable')) . '</p>'
            . '<p><strong>Pro hosting requirements:</strong> ' . ($missing === [] ? 'Verified by this host check.'
                : 'Not ready — ' . self::escape(implode(', ', $missing))) . '</p>'
            . '<p class="muted">A real Pro agent and persistent process support are required. No automatic downgrade or host transfer occurs.</p></section>'
            . ($error !== null ? '<div class="alert-error" role="alert">' . self::escape($error) . '</div>' : '')
            . ($pro ? '<section class="panel"><h2>Already upgraded</h2><p>The current edition is Pro. No migration is needed.</p></section>'
                : '<section class="panel"><h2>Activate a purchased Pro license</h2>'
                . '<p>Before upgrading, take and test a full off-server backup of your files and database. Allow a maintenance window. '
                . 'Pro subscriptions are issued and renewed by Cajun Web Pro.</p>'
                . '<form method="post" action="/admin/upgrade" class="install-form" autocomplete="off">'
                . '<input type="hidden" name="csrf" value="' . self::escape(AdminSession::csrf()) . '">'
                . '<label for="pro-key">New Pro license key</label><input id="pro-key" type="password" name="pro_license_key" required maxlength="255" autocomplete="off">'
                . '<label for="upgrade-password">Current administrator password</label><input id="upgrade-password" type="password" name="admin_password" required maxlength="72" autocomplete="current-password">'
                . '<label><input type="checkbox" name="backup_confirm" value="1" required> I have a verified off-server backup of the existing installation.</label>'
                . '<label for="upgrade-confirm">Type UPGRADE to confirm</label><input id="upgrade-confirm" name="upgrade_confirm" required maxlength="7" autocomplete="off">'
                . '<button class="install-button" type="submit"' . ($missing !== [] ? ' disabled' : '') . '>Verify license and upgrade this installation</button>'
                . '</form><p class="muted">Get a Pro license at <a href="https://cajunwebpro.com" target="_blank" rel="noopener noreferrer">Cajun Web Pro</a> '
                . '(purchase-page placeholder). The site will fail closed if an interrupted upgrade requires CLI recovery.</p></section>');
        $this->page('Upgrade to Pro', $content);
    }

    private function login(string $method): void
    {
        $admin = $this->currentAdmin();
        if ($admin !== null) {
            $this->redirect((new LicenseManager($this->root))->status() === 'valid' ? '/admin' : '/admin/license');
            return;
        }
        if (AdminSession::pendingMfaId() !== null) {
            $this->redirect('/admin/mfa', 302);
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
                    $pdo = Connection::open($this->root);
                    $account = (new AdminAccounts($pdo))->verifyCredentials($email, $password);
                    if ($account === null) {
                        $error = 'Invalid email or password.';
                    } else {
                        if ((new AdminMfa($pdo, $this->root))->enabledFor($account['id'])) {
                            AdminSession::beginMfa($account['id']);
                            $this->redirect('/admin/mfa');
                            return;
                        }
                        AdminSession::authenticate($account['id']);
                        $this->redirect((new LicenseManager($this->root))->status() === 'valid' ? '/admin' : '/admin/license');
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
            $pdo = Connection::open($this->root);
            $admin = (new AdminAccounts($pdo))->authorizedById($id);
            if ($admin !== null && (new AdminMfa($pdo, $this->root))->enabledFor($id)
                && !AdminSession::mfaVerified()) {
                AdminSession::revoke();
                return null;
            }
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
                    if ($action === 'install' || $action === 'preview_update') {
                        $file = $_FILES['package'] ?? null;
                        if (!is_array($file) || ($file['error'] ?? null) !== UPLOAD_ERR_OK
                            || !is_string($file['tmp_name'] ?? null)
                            || !is_uploaded_file($file['tmp_name'])) {
                            throw new \RuntimeException('Choose a ZIP package within the upload limit.');
                        }
                        if ($action === 'install') {
                            $store->install($file['tmp_name'], $edition, $capabilities);
                        } else {
                            $plan = $store->previewUpdate($file['tmp_name'], $edition, $capabilities);
                            $success = 'Signed update preflight only: '
                                . $plan['type'] . ' ' . $plan['id'] . ' '
                                . $plan['current_version'] . ' → ' . $plan['proposed_version']
                                . '. Nothing was installed or changed.';
                        }
                    } elseif ($action === 'enable' || $action === 'disable') {
                        $type = $_POST['type'] ?? null;
                        $id = $_POST['id'] ?? null;
                        if (!is_string($type) || !is_string($id)) {
                            throw new \RuntimeException('Invalid package action.');
                        }
                        $store->changeStatus($type, $id, $action === 'enable', $edition, $capabilities, is_string($_POST['module_license_key'] ?? null) ? $_POST['module_license_key'] : null);
                    } else {
                        throw new \RuntimeException('Invalid package action.');
                    }
                    if ($action !== 'preview_update') {
                        $this->redirect('/admin/packages?result=saved');
                        return;
                    }
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
            . '<section class="panel"><h2>Check signed update compatibility</h2>'
            . '<p>This checks a newer signed data-only ZIP against a disabled installed package. '
            . 'It does not apply, replace or execute the update.</p>'
            . '<form method="post" action="/admin/packages" enctype="multipart/form-data" class="install-form">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '">'
            . '<input type="hidden" name="action" value="preview_update">'
            . '<label for="package-update-preview">Signed update ZIP (max 8 MiB)</label>'
            . '<input id="package-update-preview" type="file" name="package" accept=".zip,application/zip" required>'
            . '<button class="button-secondary" type="submit">Check compatibility without installing</button></form></section>'
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
                . (!$package['enabled'] && is_string($package['license_sku'] ?? null)
                    ? '<label for="package-license-' . self::escape($package['id']) . '">Module license key (' . self::escape($package['license_sku']) . ')</label>'
                        . '<input type="password" id="package-license-' . self::escape($package['id']) . '" name="module_license_key" autocomplete="off" placeholder="Enter paid module key">' : '')
                . '<button class="button-secondary" type="submit"'
                . (!$package['enabled'] && array_filter($package['blocked'], static fn(string $reason):bool => $reason !== 'Paid module license is not authorized.') !== [] ? ' disabled' : '') . '>'
                . ($package['enabled'] ? 'Disable' : 'Enable') . '</button></form>';
            // A paid module may remain marked enabled after a crash during
            // a two-file license activation. Its runtime is fail-closed, but
            // operators still need a CSRF-protected way to submit a newly
            // verified key without first changing the package enable flag.
            if ($package['enabled'] && is_string($package['license_sku'] ?? null)
                && in_array('Paid module license is not authorized.', $package['blocked'], true)) {
                $out .= '<form method="post" action="/admin/packages" class="package-action">'
                    . '<input type="hidden" name="csrf" value="' . $csrf . '">'
                    . '<input type="hidden" name="type" value="module">'
                    . '<input type="hidden" name="id" value="' . self::escape($package['id']) . '">'
                    . '<input type="hidden" name="action" value="enable">'
                    . '<label for="repair-module-' . self::escape($package['id']) . '">Revalidate paid module key</label>'
                    . '<input type="password" id="repair-module-' . self::escape($package['id'])
                    . '" name="module_license_key" autocomplete="off" required placeholder="Enter authorized add-on key">'
                    . '<button class="button-secondary" type="submit">Repair paid module license</button></form>';
            }
            $out .= '</div>';
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
        echo Layout::admin($title, $content, is_string($path) ? $path : '/admin', AdminSession::csrf(), (new LicenseManager($this->root))->config()['edition'] ?? 'unknown');
    }

    private static function escape(string $input): string
    {
        return htmlspecialchars($input, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
