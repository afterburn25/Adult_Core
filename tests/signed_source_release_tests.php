<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Release\SourceArchive;

if (!class_exists(ZipArchive::class)) {
    throw new RuntimeException('Offline signed-source tests require ext-zip.');
}
$checks = 0;
$check = static function (bool $value, string $message) use (&$checks): void {
    if (!$value) throw new RuntimeException('Signed source test: ' . $message);
    ++$checks;
};
$reject = static function (callable $action, string $message) use ($check): void {
    try {
        $action();
    } catch (RuntimeException) {
        $check(true, $message);
        return;
    }
    $check(false, $message);
};
$dir = sys_get_temp_dir() . '/ac-source-sign-' . bin2hex(random_bytes(8));
if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot prepare test directory.');
$zipPath = $dir . '/source.zip';
$required = [
    'VERSION' => "0.8.12-host-https-audit-dev\n",
    'public/index.php' => '<?php echo "safe";',
    'public/.htaccess' => 'Options -Indexes',
    'install/index.php' => '<?php echo "installer";',
    'config/editions/lite.json' => '{"id":"lite"}',
    'config/editions/pro.json' => '{"id":"pro"}',
    'src/Support/Autoload.php' => '<?php',
];
$makeZip = static function (array $entries) use ($zipPath): void {
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot build test source ZIP.');
    }
    foreach ($entries as $path => $data) {
        if (!$zip->addFromString($path, $data)) throw new RuntimeException('Cannot add test entry.');
    }
    $zip->close();
};
$keys = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
if ($keys === false) throw new RuntimeException('Cannot create test signing key.');
$details = openssl_pkey_get_details($keys);
$public = $details['key'];
$sign = static function (string $json) use ($keys): string {
    if (!openssl_sign($json, $signature, $keys, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Cannot sign test manifest.');
    }
    return base64_encode($signature);
};
try {
    $makeZip($required);
    $json = SourceArchive::manifestJson($zipPath);
    $signature = $sign($json);
    $check(SourceArchive::verify($zipPath, $json, $signature, $public) === '0.8.12-host-https-audit-dev',
        'Trusted signature verifies every file of a complete Lite/Pro source ZIP');
    $check(count(json_decode($json, true, 12, JSON_THROW_ON_ERROR)['files']) === count($required),
        'Manifest includes the complete archive, not just selected files');
    $reject(static fn() => SourceArchive::verify($zipPath, $json . ' ', $signature, $public),
        'Changed signed manifest bytes rejected');
    $reject(static fn() => SourceArchive::verify($zipPath, $json, base64_encode(random_bytes(256)), $public),
        'Forged detached signature rejected');
    $makeZip($required + ['public/extra.txt' => 'unlisted']);
    $reject(static fn() => SourceArchive::verify($zipPath, $json, $signature, $public),
        'Added archive file rejected despite valid signed original contents');
    $changed = $required;
    $changed['public/index.php'] = '<?php echo "tampered";';
    $makeZip($changed);
    $reject(static fn() => SourceArchive::verify($zipPath, $json, $signature, $public),
        'Modified PHP content rejected');
    $changed = $required;
    $changed['VERSION'] = "0.8.13-dev\n";
    $makeZip($changed);
    $reject(static fn() => SourceArchive::verify($zipPath, $json, $signature, $public),
        'Mismatched release version rejected');
    $changed = $required;
    unset($changed['public/.htaccess']);
    $makeZip($changed);
    $reject(static fn() => SourceArchive::inventory($zipPath),
        'Missing public webserver safety file rejected');
    foreach ([
        '../config/app-key.php',
        'config/app-key.php',
        'storage/license-cache.json',
        'public/./index.php',
        'public/../index.php',
        'public/index.php/',
        'src/Support/private/file.php',
    ] as $path) {
        $makeZip($required + [$path => 'bad']);
        $reject(static fn() => SourceArchive::inventory($zipPath), 'Dangerous ZIP entry rejected: ' . $path);
    }
    $makeZip($required + ['PUBLIC/INDEX.PHP' => 'collision']);
    $reject(static fn() => SourceArchive::inventory($zipPath),
        'Case-insensitive duplicate entry rejected');
    $makeZip($required);
    $zip = new ZipArchive();
    $zip->open($zipPath);
    $index = $zip->locateName('public/index.php');
    $zip->setExternalAttributesIndex($index, ZipArchive::OPSYS_UNIX, 0120777 << 16);
    $zip->close();
    $reject(static fn() => SourceArchive::inventory($zipPath),
        'Unix symlink file type rejected');
    $makeZip($required);
    $privatePath = $dir . '/private.pem';
    $publicPath = $dir . '/trusted-public.pem';
    openssl_pkey_export($keys, $privateText);
    file_put_contents($privatePath, $privateText);
    file_put_contents($publicPath, $public);
    $manifestPath = $dir . '/source.manifest.json';
    $signaturePath = $dir . '/source.manifest.sig';
    $run = static function (array $args) use ($dir): int {
        $cmd = implode(' ', array_map('escapeshellarg', array_merge([PHP_BINARY], $args)));
        exec($cmd . ' 2>&1', $ignored, $code);
        return $code;
    };
    $root = dirname(__DIR__);
    $check($run([$root . '/scripts/sign-source-release.php',
        $zipPath, $privatePath, $manifestPath, $signaturePath]) === 0,
        'Offline signer generates new detached manifest and signature');
    $check($run([$root . '/scripts/verify-source-release.php',
        $zipPath, $manifestPath, $signaturePath, $publicPath]) === 0,
        'Offline CLI verifier accepts authentic source archive');
    $check($run([$root . '/scripts/sign-source-release.php',
        $zipPath, $privatePath, $manifestPath, $signaturePath]) !== 0,
        'Signer refuses overwriting an existing detached signature');
    file_put_contents($publicPath, openssl_pkey_get_details(
        openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048])
    )['key']);
    $check($run([$root . '/scripts/verify-source-release.php',
        $zipPath, $manifestPath, $signaturePath, $publicPath]) !== 0,
        'Untrusted public key cannot verify source archive');
    // Verify the real distribution builder produces a signable, complete
    // one-ZIP Lite/Pro artifact, not merely our small synthetic fixture.
    $fullZip = $dir . '/complete-source.zip';
    $buildCmd = escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg($root . '/scripts/build-release.php') . ' '
        . escapeshellarg($fullZip) . ' 2>&1';
    exec($buildCmd, $buildLines, $buildCode);
    $check($buildCode === 0 && is_file($fullZip), 'Complete source ZIP builds successfully');
    $fullInventory = SourceArchive::inventory($fullZip);
    $check(count($fullInventory['files']) > 150
        && $fullInventory['version'] === trim((string) file_get_contents($root . '/VERSION')),
        'Real source ZIP has expected project version and complete file inventory');
    $fullManifest = SourceArchive::manifestJson($fullZip);
    $check(SourceArchive::verify($fullZip, $fullManifest, $sign($fullManifest), $public)
        === $fullInventory['version'],
        'Detached signature authenticates all files from the actual distribution builder');
    $check(SourceArchive::MAX_UNPACKED_BYTES >= SourceArchive::MAX_ENTRY_BYTES,
        'Bounded streaming ZIP inventory limits remain defined');
    echo "Passed {$checks} signed source archive verification checks.\n";
} finally {
    foreach (glob($dir . '/*') ?: [] as $path) {
        if (is_file($path)) unlink($path);
    }
    rmdir($dir);
}
