<?php

declare(strict_types=1);

// Included ONLY by public/index.php while InstallationGate::SETUP_REQUIRED.
use AdultCore\Capability\HostInspector;
use AdultCore\Edition\EditionSelector;
use AdultCore\Installer\InstallInput;
use AdultCore\Installer\InstallRateLimiter;
use AdultCore\Installer\InstallService;
use AdultCore\Module\ModuleCompatibility;
use AdultCore\Support\ManifestLoader;

function html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$root = dirname(__DIR__);
$host = new HostInspector($root);
$report = $host->inspect($_SERVER['DOCUMENT_ROOT'] ?? null);
$choice = (new EditionSelector($root . '/config/editions'))->select($report['capabilities']);
$edition = $choice['edition'];
$modules = [];
$compatibility = new ModuleCompatibility();
foreach (glob($root . '/modules/*/module.json') ?: [] as $manifestPath) {
    try {
        $module = ManifestLoader::load($manifestPath);
        $module['reasons'] = $compatibility->blockedReasons($module, $edition, $report['capabilities']);
        $modules[] = $module;
    } catch (Throwable) {
        $modules[] = ['id' => basename(dirname($manifestPath)), 'name' => 'Invalid module', 'reasons' => ['Manifest is invalid.']];
    }
}

$remote = $_SERVER['REMOTE_ADDR'] ?? '';
$localhost = in_array($remote, ['127.0.0.1', '::1'], true);
$https = isset($_SERVER['HTTPS']) && in_array(strtolower((string) $_SERVER['HTTPS']), ['on', '1'], true);
$secureRequest = $https || $localhost;
$errorMessage = null;
$success = false;

if (!extension_loaded('session')) {
    $errorMessage = 'PHP sessions are required for the installer.';
} else {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('adultcore_install');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $https,
        'samesite' => 'Strict',
        'path' => '/install/',
    ]);
    if (!session_start()) {
        $errorMessage = 'Cannot initialize installer session.';
    } else {
        if (!is_string($_SESSION['csrf'] ?? null)) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $submittedToken = $_POST['csrf'] ?? null;
            if (!$secureRequest) {
                $errorMessage = 'HTTPS is required to submit database and administrator credentials.';
            } elseif (!is_string($submittedToken) || !hash_equals($_SESSION['csrf'], $submittedToken)) {
                $errorMessage = 'Installer session expired or security token invalid. Reload and try again.';
            } elseif ($edition === null) {
                $errorMessage = 'The server does not meet the Lite requirements.';
            } else {
                try {
                    (new InstallRateLimiter($root . '/storage'))->consume(is_string($remote) ? $remote : 'unknown');
                    $input = InstallInput::validate($_POST);
                    (new InstallService($root))->install($input, $edition);
                    unset($_SESSION['csrf']);
                    session_destroy();
                    $success = true;
                    header('Location: /install/', true, 303);
                    exit;
                } catch (InvalidArgumentException $exception) {
                    $errorMessage = $exception->getMessage();
                } catch (RuntimeException $exception) {
                    // Safe, constant errors for known operator actions; no secrets.
                    $message = $exception->getMessage();
                    if (str_starts_with($message, 'Too many installation attempts')
                        || str_starts_with($message, 'Database already contains Adult Core tables')
                        || str_starts_with($message, 'Installation already running')
                        || str_starts_with($message, 'Installation files already exist')) {
                        $errorMessage = $message;
                    } else {
                        $errorMessage = 'Installation failed. Review database access, empty schema and server permissions.';
                    }
                    (new InstallService($root))->audit('install_failed_' . basename(str_replace('\\', '/', get_class($exception))), $edition);
                } catch (Throwable $exception) {
                    // Do not expose PDO exception text (could include host, username or SQL).
                    $errorMessage = 'Installation failed. Check the database credentials, permissions and PHP PDO MySQL support.';
                    (new InstallService($root))->audit('install_failed_' . basename(str_replace('\\', '/', get_class($exception))), $edition);
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Adult Core | Installation Wizard</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<div class="shell">
    <header class="topbar"><div class="mark">AC</div><span>Adult Core</span><span class="version">Milestone 2 · Installation Wizard</span></header>
    <main>
        <p class="eyebrow">SELF-HOSTED · ONE CORE · TWO EDITIONS</p>
        <h1>Install Adult Core</h1>
        <p class="intro">We check your hosting capabilities, select the supported edition and securely configure a fresh MySQL database. The website and admin remain locked until you delete the <code>install/</code> folder after setup.</p>
        <section class="edition">
            <div>
                <div class="muted small">AUTOMATIC EDITION SELECTION</div>
                <h2><?= $edition === null ? 'Installation blocked' : html(strtoupper($edition)) . ' Edition' ?></h2>
                <p><?= $edition === 'pro'
                    ? 'Verified local Pro agent supports the advanced edition.'
                    : ($edition === 'lite' ? 'Shared-hosting requirements passed. Additional VPS features are not available.' : 'Fix the minimum requirements before proceeding.') ?></p>
            </div>
            <div class="pill <?= $edition === null ? 'pill-no' : 'pill-yes' ?>"><?= $edition === null ? 'BLOCKED' : 'ELIGIBLE' ?></div>
        </section>
        <?php if ($errorMessage !== null): ?>
            <section class="alert-error" role="alert"><strong>Installation not completed</strong><p><?= html($errorMessage) ?></p></section>
        <?php endif; ?>
        <div class="columns">
            <section class="panel">
                <h2>1. Server requirements</h2>
                <p class="muted">Determined by capabilities, not hosting company or server name.</p>
                <div class="checklist">
                    <?php foreach ($report['checks'] as $check): ?>
                        <div class="check"><span class="symbol <?= $check['pass'] ? 'good' : 'bad' ?>"><?= $check['pass'] ? '✓' : '×' ?></span><div><strong><?= html($check['name']) ?></strong><small><?= html($check['detail']) ?></small></div></div>
                    <?php endforeach; ?>
                    <div class="check"><span class="symbol <?= $secureRequest ? 'good' : 'bad' ?>"><?= $secureRequest ? '✓' : '×' ?></span><div><strong>Secure installation connection</strong><small>HTTPS required for real domains (localhost allowed for testing).</small></div></div>
                </div>
            </section>
            <section class="panel">
                <h2>2. Configure a fresh website</h2>
                <p class="muted">Create a new empty MySQL database and a user with access to it in your hosting control panel first. Do not use an existing Adult Core database.</p>
                <?php if ($edition !== null && $secureRequest && $errorMessage !== 'Cannot initialize installer session.'): ?>
                    <form method="post" action="/install/" autocomplete="off" class="install-form">
                        <input type="hidden" name="csrf" value="<?= html((string) ($_SESSION['csrf'] ?? '')) ?>">
                        <label for="site_name">Website name</label>
                        <input id="site_name" name="site_name" type="text" minlength="2" maxlength="120" required value="<?= html(is_string($_POST['site_name'] ?? null) ? $_POST['site_name'] : '') ?>" placeholder="My Private Membership Site">
                        <div class="form-grid"><div><label for="db_host">Database host</label><input id="db_host" name="db_host" type="text" value="localhost" required></div><div><label for="db_port">Port</label><input id="db_port" name="db_port" type="number" min="1" max="65535" value="3306" required></div></div>
                        <label for="db_name">Database name</label><input id="db_name" name="db_name" type="text" required placeholder="cpanel_dbname">
                        <label for="db_user">Database username</label><input id="db_user" name="db_user" type="text" required placeholder="cpanel_dbuser">
                        <label for="db_password">Database password</label><input id="db_password" name="db_password" type="password" required autocomplete="new-password">
                        <div class="form-divider"></div>
                        <label for="admin_email">Administrator email</label><input id="admin_email" name="admin_email" type="email" maxlength="190" required value="<?= html(is_string($_POST['admin_email'] ?? null) ? $_POST['admin_email'] : '') ?>">
                        <label for="admin_password">Administrator password (12–72 characters)</label><input id="admin_password" name="admin_password" type="password" minlength="12" maxlength="72" required autocomplete="new-password">
                        <label for="admin_password_confirm">Confirm administrator password</label><input id="admin_password_confirm" name="admin_password_confirm" type="password" minlength="12" maxlength="72" required autocomplete="new-password">
                        <button class="install-button" type="submit">Install <?= html(strtoupper($edition)) ?> Edition</button>
                        <p class="muted form-note">There is no automatic overwrite or reset. A successful setup locks the site until the <code>install/</code> directory is manually deleted.</p>
                    </form>
                <?php else: ?>
                    <p class="muted">Installation is disabled until server and HTTPS requirements pass.</p>
                <?php endif; ?>
            </section>
        </div>
        <section class="notice">
            <h2>3. Add-on compatibility preview</h2>
            <div class="columns">
                <?php foreach ($modules as $module): ?>
                    <?php $allowed = $module['reasons'] === []; ?>
                    <div class="module"><div class="module-head"><strong><?= html((string) ($module['name'] ?? 'Unknown')) ?></strong><span class="pill <?= $allowed ? 'pill-yes' : 'pill-no' ?>"><?= $allowed ? 'COMPATIBLE' : 'BLOCKED' ?></span></div>
                        <?php if (!$allowed): ?><ul><?php foreach ($module['reasons'] as $reason): ?><li><?= html($reason) ?></li><?php endforeach; ?></ul><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <p>This is an installer milestone, not a live membership website. A working site/admin runtime and production payment/content moderation features are still to come.</p>
        </section>
    </main>
    <footer>Adult Core · Milestone 2 · Installation code outside the public document root</footer>
</div>
</body>
</html>
