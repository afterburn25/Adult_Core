<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Release\SourceArchive;
use AdultCore\Release\SourceDelta;

$checks = 0;
$check = static function (bool $yes, string $label) use (&$checks): void {
    if (!$yes) throw new RuntimeException('Signed update preview: '.$label);
    ++$checks;
};
$reject = static function (callable $action, string $label) use ($check): void {
    try { $action(); } catch (RuntimeException) { $check(true, $label); return; }
    $check(false, $label);
};
$source = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/ac-source-preview-' . bin2hex(random_bytes(8));
if (!mkdir($tmp, 0700)) throw new RuntimeException('Cannot create test fixture.');
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') $remove($path.'/'.$entry);
        }
        rmdir($path);
    }
};
try {
    $zipPath = $tmp . '/candidate.zip';
    $build = escapeshellarg(PHP_BINARY).' '.escapeshellarg($source.'/scripts/build-release.php')
        .' '.escapeshellarg($zipPath).' 2>&1';
    exec($build, $buildLines, $status);
    $check($status === 0 && is_file($zipPath), 'Real unified source ZIP built');
    $key = openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
    if ($key === false) throw new RuntimeException('Test RSA key unavailable.');
    $public = openssl_pkey_get_details($key)['key'];
    $manifest = SourceArchive::manifestJson($zipPath);
    openssl_sign($manifest, $signatureBytes, $key, OPENSSL_ALGO_SHA256);
    $signature = base64_encode($signatureBytes);
    $root = $tmp . '/installed';
    mkdir($root);
    mkdir($root . '/public');
    mkdir($root . '/src');
    mkdir($root . '/src/Support');
    file_put_contents($root . '/VERSION', "0.8.11-old-dev\n");
    copy($source . '/public/index.php', $root . '/public/index.php');
    copy($source . '/src/Support/Autoload.php', $root . '/src/Support/Autoload.php');
    file_put_contents($root . '/private-customer-record', 'never-in-report');
    $before = file_get_contents($root . '/VERSION');
    $preview = SourceDelta::preview($root, $zipPath, $manifest, $signature, $public);
    $check($preview['read_only'] && !$preview['complete_update_plan'] && !$preview['deletions_assessed'],
        'No write/deletion/migration authorization in report');
    $check($preview['installed_version'] === '0.8.11-old-dev'
        && $preview['candidate_version'] === trim((string)file_get_contents($source.'/VERSION')),
        'Installed and candidate versions are distinct');
    $check(in_array('VERSION', $preview['would_replace'], true)
        && in_array('public/.htaccess', $preview['would_add'], true)
        && $preview['unchanged_count'] >= 2,
        'Actual ZIP inventory reports additions, modifications and unchanged source');
    $json = json_encode($preview, JSON_THROW_ON_ERROR);
    $check(!str_contains($json, 'never-in-report')
        && !str_contains($json, 'private-customer-record')
        && !str_contains($json, 'license.php'),
        'Unlisted customer files and secrets are never inspected or reported');
    $check(file_get_contents($root.'/VERSION') === $before
        && file_get_contents($root.'/private-customer-record') === 'never-in-report',
        'Preview leaves files exactly untouched');
    $reject(static fn() => SourceDelta::preview($root, $zipPath, $manifest, base64_encode(random_bytes(256)), $public),
        'Forged signature denied before any local comparison');
    $reject(static fn() => SourceDelta::preview($root.'/public', $zipPath, $manifest, $signature, $public),
        'Public document root is not an application root');
    $external = $tmp . '/external.php';
    file_put_contents($external, '<?php');
    if (function_exists('symlink') && symlink($external, $root.'/public/.htaccess')) {
        $reject(static fn() => SourceDelta::preview($root, $zipPath, $manifest, $signature, $public),
            'Never follow source symlink to outside file');
        unlink($root.'/public/.htaccess');
    }
    $dir = $tmp . '/outside';
    mkdir($dir);
    mkdir($root.'/config');
    if (function_exists('symlink') && symlink($dir, $root.'/config/editions')) {
        $reject(static fn() => SourceDelta::preview($root, $zipPath, $manifest, $signature, $public),
            'Never follow source symlink to outside directory');
        unlink($root.'/config/editions');
    }
    $manifestPath = $tmp.'/candidate.manifest.json';
    $sigPath = $tmp.'/candidate.manifest.sig';
    $pubPath = $tmp.'/public.pem';
    file_put_contents($manifestPath, $manifest);
    file_put_contents($sigPath, $signature);
    file_put_contents($pubPath, $public);
    $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($source.'/scripts/preview-source-update.php').' '
        .implode(' ', array_map('escapeshellarg', [$zipPath,$manifestPath,$sigPath,$pubPath,$root,'--json'])).' 2>&1';
    $lines = [];
    exec($cmd, $lines, $exitCode);
    $cliReport = json_decode(implode("\n", $lines), true);
    $check($exitCode === 0 && is_array($cliReport)
        && $cliReport['schema'] === 'adult-core-source-delta-v1'
        && count($cliReport['would_add']) === count($preview['would_add']),
        'CLI emits machine-readable signed, read-only source comparison');
    echo "Passed {$checks} signed source preview checks.\n";
} finally {
    $remove($tmp);
}
