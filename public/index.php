<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Installer\InstallationGate;
use AdultCore\Http\AdminController;
use AdultCore\Release\SourceHttpRequestLock;

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");

$root = dirname(__DIR__);
// Hold the shared lock through the entire dynamic HTTP request. A pending
// exclusive maintenance operation responds 503 before any admin/site routing.
try {
    $activeHttpRequestLock = SourceHttpRequestLock::enter($root);
} catch (\RuntimeException) {
    http_response_code(503);
    header('Retry-After: 30');
    $heading = 'Source maintenance in progress';
    $message = 'The website is temporarily unavailable while its server operator prepares maintenance. Please try again shortly.';
    require $root . '/src/Installer/LockedScreen.php';
    exit;
}
$gate = new InstallationGate($root);
$state = $gate->status();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? rtrim($path, '/') : '/';
$path = $path === '' ? '/' : $path;

if ($state === InstallationGate::SETUP_REQUIRED) {
    if ($path === '/install') {
        // The install directory is not web-exposed. Only the front controller
        // may include the installer page, and only before completion.
        require $root . '/install/index.php';
        exit;
    }
    header('Location: /install/', true, 302);
    exit;
}

// This gate is evaluated BEFORE any application/admin route, including /install.
if ($state !== InstallationGate::READY) {
    http_response_code(503);
    header('Retry-After: '.(in_array($state, [InstallationGate::BACKUP_MAINTENANCE, InstallationGate::SOURCE_UPDATE_MAINTENANCE], true) ? '300' : '3600'));
    $messages = [
        InstallationGate::CLEANUP_REQUIRED => [
            'Installation cleanup required',
            'Installation has been marked complete, but the install/ directory still exists. Delete the entire install/ directory from the project root using your hosting File Manager or SSH. The site and /admin remain locked until it is gone.',
        ],
        InstallationGate::INSTALLER_MISSING => [
            'Installer missing',
            'Installation is not complete and install/ is missing. Restore the install/ directory from the matching release package. The site and /admin remain locked.',
        ],
        InstallationGate::SOURCE_UPDATE_MAINTENANCE => [
            'Source update maintenance',
            'The independently hosted website is temporarily unavailable while a server operator performs source maintenance. Existing customer data remains private. Contact the operator if maintenance persists.',
        ],
        InstallationGate::BACKUP_MAINTENANCE => [
            'Backup maintenance',
            'The website is temporarily unavailable while an administrator creates a secure backup. Try again after maintenance is complete.',
        ],
        InstallationGate::EDITION_UPGRADE_PENDING => [
            'Edition upgrade recovery required',
            'A Lite-to-Pro upgrade was interrupted. The website is locked to protect existing data. Use SSH or the hosting terminal to run php scripts/recover-edition-upgrade.php --rollback, then review the verified Lite installation before retrying.',
        ],
        InstallationGate::LICENSE_UPDATE_PENDING => [
            'License update recovery required',
            'A license replacement was interrupted. All website and administrator access is locked until an operator runs php scripts/recover-license-update.php --rollback from the server terminal and reviews the restored license.',
        ],
        InstallationGate::INVALID_STATE => [
            'Installation state invalid',
            'Installation state or private configuration is missing, unsigned or invalid. Access remains locked. Restore a trusted backup and do not delete files to bypass installation.',
        ],
    ];
    [$heading, $message] = $messages[$state] ?? ['Unavailable', 'The site is not ready.'];
    require $root . '/src/Installer/LockedScreen.php';
    exit;
}

if ($path === '/install') {
    http_response_code(404);
    $heading = 'Installer removed';
    $message = 'The installer is no longer available.';
    require $root . '/src/Installer/LockedScreen.php';
    exit;
}

// Cajun Web Pro is the single license authority for Adult_Core. An installed
// site with a missing, revoked, wrong-domain or stale-unverified license stays
// inaccessible. Only authenticated license recovery and admin sign-in remain.
$licenseStatus = (new \AdultCore\Licensing\LicenseManager($root))->status();
if ($licenseStatus !== 'valid' && !in_array($path, ['/admin/login', '/admin/logout', '/admin/mfa', '/admin/license', '/admin/upgrade'], true)) {
    http_response_code(503);
    header('Retry-After: 1800');
    $heading = 'Adult_Core license authorization required';
    $message = 'The Cajun Web Pro license for this installation is missing, deactivated, registered for another hostname, or could not be validated within the permitted temporary-outage window. All site and workspace features stay locked. A site administrator can sign in and update the license at /admin/license.';
    require $root . '/src/Installer/LockedScreen.php';
    exit;
}

// Built-in layout palette and explicitly public-safe decorative assets only.
if ($path === '/design.css' || preg_match('#^/design/asset/([1-9][0-9]{0,18})$#D', $path, $designMatch) === 1) {
    if (!in_array(($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET','HEAD'], true)) {
        header('Allow: GET, HEAD'); http_response_code(405); exit;
    }
    try {
        $pdo = \AdultCore\Database\Connection::open($root);
        $q = $pdo->prepare('SELECT COUNT(*) FROM ac_schema_migrations WHERE version=?');
        $q->execute([\AdultCore\Design\DesignSchema::VERSION]);
        if ((int)$q->fetchColumn() === 0) { http_response_code(404); exit; }
        $repo = new \AdultCore\Design\DesignRepository($pdo);
        if ($path === '/design.css') {
            $current = $repo->current();
            $accent = (string)($current['accent_color'] ?? '#d9ae75');
            if (preg_match('/^#[0-9a-fA-F]{6}$/D', $accent) !== 1) { http_response_code(404); exit; }
            $placement = \AdultCore\Design\CutoutPlacement::defaults();
            try {
                $placement = \AdultCore\Design\CutoutPlacement::validate(
                    (string)($current['cutout_x'] ?? ''),
                    (string)($current['cutout_y'] ?? ''),
                    (string)($current['cutout_width'] ?? '')
                );
            } catch (RuntimeException) { /* Retain safe defaults if an older record is malformed. */ }
            header('Content-Type: text/css; charset=UTF-8');
            header('Cache-Control: no-store');
            echo '.public-site{--design-accent:' . $accent
                . ';--cutout-x:' . $placement['cutout_x'] . '%'
                . ';--cutout-y:' . $placement['cutout_y'] . '%'
                . ';--cutout-width:' . $placement['cutout_width'] . '%}';
        } else {
            $asset = $repo->publicAsset((int)$designMatch[1]);
            if ($asset === null) { http_response_code(404); exit; }
            (new \AdultCore\Design\DesignImages($root,$pdo))->serve($asset);
        }
    } catch (Throwable) { http_response_code(503); }
    exit;
}

// Active themes expose only a signed CSS asset; other package files stay private.
if ($path === '/theme.css') {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        http_response_code(405);
        exit;
    }
    try {
        $installed = json_decode((string) file_get_contents($root . '/storage/install-state.json'), true, 8, JSON_THROW_ON_ERROR);
        $edition = $installed['edition'] ?? null;
        if (!in_array($edition, ['lite', 'pro'], true)) {
            throw new RuntimeException('Unknown edition.');
        }
        $capabilities = (new \AdultCore\Capability\HostInspector($root))->inspect($_SERVER['DOCUMENT_ROOT'] ?? null)['capabilities'];
        $css = (new \AdultCore\Theme\ThemeAssets($root))->css($edition, $capabilities);
        if ($css === null) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: text/css; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo $css;
    } catch (Throwable) {
        http_response_code(503);
    }
    exit;
}

// All administrator routes are served through a single authenticated controller.
// The cleanup/installation gate above is never bypassed.
if ($path === '/admin' || str_starts_with($path, '/admin/')) {
    (new AdminController($root))->handle($path, (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    exit;
}

// Provider assertions never bypass the installation gate and are disabled by default.
if ($path === '/webhooks/verification') {
    (new \AdultCore\Http\VerificationWebhookController($root))->handle((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    exit;
}

// Authorized image delivery only; no direct public storage URLs.
if (str_starts_with($path, '/media/')) {
    (new \AdultCore\Http\PrivateMediaController($root))->handle($path, (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    exit;
}

// Public member entry points are session-isolated from administrator login.
if (in_array($path, ['/join', '/members', '/members/library', '/members/login', '/members/logout', '/members/report', '/members/creator', '/members/email', '/members/email/confirm', '/members/recover', '/members/recover/reset', '/members/password', '/members/sessions', '/members/profile'], true)
    || str_starts_with($path, '/members/library/')) {
    (new \AdultCore\Http\MemberController($root))->handle($path, (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    exit;
}

// Only published plain-text pages are public. No private media routes exist yet.
if ($path === '/' || str_starts_with($path, '/p/')) {
    (new \AdultCore\Website\PublicSite($root))->handle($path, (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    exit;
}

http_response_code(404);
$heading = 'Page not found';
$message = 'This route is not available.';
require $root . '/src/Installer/LockedScreen.php';
