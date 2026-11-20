<?php

declare(strict_types=1);

/**
 * PHP development server router. Apache/cPanel uses public/.htaccess instead.
 * Only explicitly allowlisted, real public static assets bypass index.php.
 * In particular, dynamic /theme.css, /design.css, and /design/asset/{id}
 * MUST run through the installation gate and relevant authorization checks.
 */
$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if (is_string($path)
    && preg_match('#^/assets/[A-Za-z0-9._-]+\.(?:css|js|png|webp|jpe?g|ico)$#D', $path) === 1
    && is_file(__DIR__ . $path)
    && !is_link(__DIR__ . $path)) {
    return false;
}
require __DIR__ . '/index.php';
