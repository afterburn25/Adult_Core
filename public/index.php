<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Installer\InstallationGate;
use AdultCore\Http\AdminController;

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");

$root = dirname(__DIR__);
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
    header('Retry-After: 3600');
    $messages = [
        InstallationGate::CLEANUP_REQUIRED => [
            'Installation cleanup required',
            'Installation has been marked complete, but the install/ directory still exists. Delete the entire install/ directory from the project root using your hosting File Manager or SSH. The site and /admin remain locked until it is gone.',
        ],
        InstallationGate::INSTALLER_MISSING => [
            'Installer missing',
            'Installation is not complete and install/ is missing. Restore the install/ directory from the matching release package. The site and /admin remain locked.',
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

// Public site/membership features are NOT ready yet.
if ($path === '/') {
    http_response_code(503);
    header('Retry-After: 3600');
    $heading = 'Site runtime not implemented yet';
    $message = 'The administration dashboard now supports login, but member registration, private content, payment and compliance features are not available. Do not launch this as a public website.';
    require $root . '/src/Installer/LockedScreen.php';
    exit;
}

http_response_code(404);
$heading = 'Page not found';
$message = 'This route is not available.';
require $root . '/src/Installer/LockedScreen.php';
