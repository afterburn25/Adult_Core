<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Package\PackageStore;
use AdultCore\Package\PackageVerifier;

$passed = 0;
$failed = [];
function verifyPackageTest(string $name, bool $value): void
{
    global $passed, $failed;
    if ($value) {
        ++$passed;
    } else {
        $failed[] = $name;
    }
}
function expectRefusal(string $name, callable $action): void
{
    try {
        $action();
        verifyPackageTest($name, false);
    } catch (RuntimeException) {
        verifyPackageTest($name, true);
    }
}
function packageFixture(string $path, array $manifest, array $contents, string $privateKey, array $additional = []): void
{
    @unlink($path);
    $manifest['files'] = [];
    foreach ($contents as $name => $value) {
        $manifest['files'][] = ['path' => $name, 'sha256' => hash('sha256', $value)];
    }
    $raw = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    openssl_sign($raw, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    $zip = new PharData($path, 0, null, Phar::ZIP);
    $zip['package.json'] = $raw;
    $zip['package.sig'] = base64_encode($signature);
    foreach ($contents as $name => $value) {
        $zip['payload/' . $name] = $value;
    }
    foreach ($additional as $name => $value) {
        $zip[$name] = $value;
    }
    unset($zip);
}
function manifestFixture(string $id, string $type = 'module', array $editions = ['lite', 'pro'], array $deps = []): array
{
    return [
        'id' => $id, 'name' => ucwords(str_replace('-', ' ', $id)),
        'type' => $type, 'publisher' => 'example-author', 'version' => '1.0.0',
        'minimum_core_version' => '0.1.0', 'editions' => $editions,
        'required_capabilities' => ['fileinfo'], 'dependencies' => $deps,
    ];
}
function wipeFixture(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (new FilesystemIterator($directory) as $file) {
        if ($file->isDir() && !$file->isLink()) {
            wipeFixture($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($directory);
}

$root = sys_get_temp_dir() . '/ac-package-tests-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
mkdir($root . '/storage', 0700);
mkdir($root . '/config', 0700);
mkdir($root . '/config/trusted-publishers', 0700);
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($key === false) {
    throw new RuntimeException('Could not generate ephemeral test signing key.');
}
openssl_pkey_export($key, $privateKey);
$publicKey = openssl_pkey_get_details($key)['key'];
$keyPath = $root . '/config/trusted-publishers/example-author.pem';
file_put_contents($keyPath, $publicKey);
$fixtureDir = $root . '/fixtures';
mkdir($fixtureDir, 0700);
$verifier = new PackageVerifier($root . '/config/trusted-publishers');
$store = new PackageStore($root);
$caps = ['fileinfo', 'storage_writable'];
try {
    $baseZip = $fixtureDir . '/base-module.zip';
    packageFixture($baseZip, manifestFixture('base-module'), ['readme.txt' => 'This is a metadata-only test module.'], $privateKey);
    $verified = $verifier->inspect($baseZip);
    verifyPackageTest('Valid publisher-signed module accepted', $verified['manifest']['id'] === 'base-module');
    verifyPackageTest('Archive reads exact verified content', $verified['files']['readme.txt'] === 'This is a metadata-only test module.');
    $store->install($baseZip, 'lite', $caps);
    verifyPackageTest('Signed module installed in private storage', is_file($root . '/storage/packages/modules/base-module/payload/readme.txt'));
    verifyPackageTest('New extension disabled until admin enables', !$store->isRunnable('module', 'base-module', 'lite', $caps));
    $store->changeStatus('module', 'base-module', true, 'lite', $caps);
    verifyPackageTest('Validated module enables on Lite', $store->isRunnable('module', 'base-module', 'lite', $caps));
    verifyPackageTest('Runtime fails closed on missing capability', !$store->isRunnable('module', 'base-module', 'lite', []));
    expectRefusal('Repeated module install refused', fn () => $store->install($baseZip, 'lite', $caps));

    $proZip = $fixtureDir . '/pro-only.zip';
    packageFixture($proZip, manifestFixture('pro-only', 'module', ['pro']), ['readme.txt' => 'pro'], $privateKey);
    expectRefusal('Pro-only module rejected on Lite', fn () => $store->install($proZip, 'lite', $caps));
    verifyPackageTest('Rejected module never enters storage', !is_dir($root . '/storage/packages/modules/pro-only'));
    $store->install($proZip, 'pro', $caps);
    $store->changeStatus('module', 'pro-only', true, 'pro', $caps);
    verifyPackageTest('Pro-only module accepted when edition is Pro', $store->isRunnable('module', 'pro-only', 'pro', $caps));
    verifyPackageTest('Pro-only module cannot run under Lite context', !$store->isRunnable('module', 'pro-only', 'lite', $caps));

    // Product licenses are independent from the core edition license.
    file_put_contents($root.'/config/app-key.php', '<?php return '.var_export(str_repeat('a',64),true).';');
    file_put_contents($root.'/config/license.php', '<?php return '.var_export([
        'license_key'=>'ABCD-EF12-3456-7890','domain'=>'example.test',
        'sku'=>\AdultCore\Licensing\CajunWebClient::SKU_LITE_ONCE,'edition'=>'lite','term'=>'once'
    ],true).';');
    // Paid add-ons must have an actually completed, signed and licensed core
    // installation. The package registry fixture alone is not enough.
    $siteSecret = str_repeat('a',64);
    $coreSku = \AdultCore\Licensing\CajunWebClient::SKU_LITE_ONCE;
    $coreCache = $root.'/storage/license-cache.json';
    $installedState = $root.'/storage/install-state.json';
    file_put_contents($root.'/config/database.php',
        '<?php return '.var_export(['driver'=>'mysql','database'=>'fixture','username'=>'fixture'],true).';');
    file_put_contents($installedState, \AdultCore\Installer\InstallationState::encode('lite',$siteSecret));
    file_put_contents($coreCache, \AdultCore\Licensing\LicenseState::encode(
        'ABCD-EF12-3456-7890','example.test',$siteSecret,'valid',time(),time(),$coreSku));
    verifyPackageTest('Paid module fixture has a signed, valid core license',
        (new \AdultCore\Licensing\LicenseManager($root))->status('example.test') === 'valid');
    $paidSku='SOFTWARE-ADULT-CORE-MODULE-CHAT';
    $paidZip=$fixtureDir.'/paid-chat.zip';
    $paidManifest=manifestFixture('paid-chat');
    $paidManifest['license_sku']=$paidSku;
    packageFixture($paidZip,$paidManifest,['readme.txt'=>'Test paid module'], $privateKey);
    $licenseClient=new \AdultCore\Licensing\CajunWebClient(static function(string $body)use($paidSku):array{
        $data=json_decode($body,true,8,JSON_THROW_ON_ERROR);
        $allowed=($data['product_id']??null)===$paidSku
            &&($data['license_key']??null)==='AAAA-BBBB-CCCC-DDDD'
            &&($data['domain']??null)==='example.test';
        return ['status'=>200,'body'=>json_encode(['authorized'=>$allowed],JSON_THROW_ON_ERROR)];
    });
    $paidStore=new PackageStore($root,$licenseClient);
    $paidStore->install($paidZip,'lite',$caps);
    verifyPackageTest('Paid module accepted as signed but default disabled', !$paidStore->isRunnable('module','paid-chat','lite',$caps));
    expectRefusal('Paid module cannot activate without separate key',fn()=> $paidStore->changeStatus('module','paid-chat',true,'lite',$caps));
    expectRefusal('Wrong paid module key denied',fn()=> $paidStore->changeStatus('module','paid-chat',true,'lite',$caps,'FAKE-FAKE-FAKE-FAKE'));
    $paidStore->changeStatus('module','paid-chat',true,'lite',$caps,'AAAA-BBBB-CCCC-DDDD');
    verifyPackageTest('Correct paid module key activates', $paidStore->isRunnable('module','paid-chat','lite',$caps));
    $licenseCache=$root.'/storage/packages/module-licenses/paid-chat.json';
    verifyPackageTest('Module receipt does not expose key', !str_contains((string)file_get_contents($licenseCache),'AAAA-BBBB-CCCC-DDDD'));
    $moduleLicenses = new \AdultCore\Licensing\ModuleLicenseManager($root,$licenseClient);
    $moduleDir = dirname($licenseCache);
    $moduleLock = $moduleDir.'/.lock';
    $moduleLockBackup = $moduleLock.'.original';
    if (!rename($moduleLock, $moduleLockBackup) || !@symlink($moduleLockBackup, $moduleLock)) {
        throw new RuntimeException('Could not create paid-module lock fixture.');
    }
    try {
        verifyPackageTest('Symlinked module lock invalidates fresh signed grant',
            !$paidStore->isRunnable('module','paid-chat','lite',$caps));
        expectRefusal('Module activation refuses symlinked private lock',
            fn()=> $moduleLicenses->activate('paid-chat',$paidSku,'AAAA-BBBB-CCCC-DDDD'));
    } finally {
        if (is_link($moduleLock)) unlink($moduleLock);
        rename($moduleLockBackup, $moduleLock);
    }
    verifyPackageTest('Restored private lock restores authorized module',
        $paidStore->isRunnable('module','paid-chat','lite',$caps));
    $moduleConfig = $moduleDir.'/paid-chat.php';
    $originalModuleConfig = file_get_contents($moduleConfig);
    $cacheBackup = $licenseCache.'.original';
    if (!rename($licenseCache, $cacheBackup) || !@symlink($cacheBackup, $licenseCache)) {
        throw new RuntimeException('Could not create paid-module cache fixture.');
    }
    try {
        expectRefusal('Module activation preflights symlinked receipt before rewriting config',
            fn()=> $moduleLicenses->activate('paid-chat',$paidSku,'AAAA-BBBB-CCCC-DDDD'));
        verifyPackageTest('Unsafe module receipt preserves original license configuration',
            file_get_contents($moduleConfig) === $originalModuleConfig);
    } finally {
        if (is_link($licenseCache)) unlink($licenseCache);
        rename($cacheBackup, $licenseCache);
    }
    $moduleConfigBackup = $moduleConfig.'.original';
    $originalModuleCache = file_get_contents($licenseCache);
    if (!rename($moduleConfig, $moduleConfigBackup) || !@symlink($moduleConfigBackup, $moduleConfig)) {
        throw new RuntimeException('Could not create paid-module config fixture.');
    }
    try {
        expectRefusal('Module activation refuses symlinked private configuration',
            fn()=> $moduleLicenses->activate('paid-chat',$paidSku,'AAAA-BBBB-CCCC-DDDD'));
        verifyPackageTest('Unsafe module config preserves previous signed receipt',
            file_get_contents($licenseCache) === $originalModuleCache);
    } finally {
        if (is_link($moduleConfig)) unlink($moduleConfig);
        rename($moduleConfigBackup, $moduleConfig);
    }
    // A symlinked containing directory must never redirect addon licensing.
    $moduleDirBackup = $moduleDir.'.original';
    if (!rename($moduleDir, $moduleDirBackup) || !@symlink($moduleDirBackup, $moduleDir)) {
        throw new RuntimeException('Could not create paid-module directory fixture.');
    }
    try {
        verifyPackageTest('Symlinked module license directory invalidates cached grant',
            !$paidStore->isRunnable('module','paid-chat','lite',$caps));
        expectRefusal('Module activation refuses symlinked license directory',
            fn()=> $moduleLicenses->activate('paid-chat',$paidSku,'AAAA-BBBB-CCCC-DDDD'));
    } finally {
        if (is_link($moduleDir)) unlink($moduleDir);
        rename($moduleDirBackup, $moduleDir);
    }
    verifyPackageTest('Module license restored after rejecting unsafe storage',
        $paidStore->isRunnable('module','paid-chat','lite',$caps));
    file_put_contents($licenseCache, \AdultCore\Licensing\LicenseState::encode('AAAA-BBBB-CCCC-DDDD','example.test',str_repeat('a',64),'denied',time(),time()-5,$paidSku));
    verifyPackageTest('Revoked module receipt prevents runtime', !$paidStore->isRunnable('module','paid-chat','lite',$caps));
    $paidStore->changeStatus('module','paid-chat',false,'lite',$caps);
    // An unsigned or invalid module SKU must fail signature-manifest admission.
    $badSku=manifestFixture('bad-sku');$badSku['license_sku']='SOFTWARE-ADULT-CORE-LITE';
    $badZip=$fixtureDir.'/bad-sku.zip';packageFixture($badZip,$badSku,['readme.txt'=>'Invalid entitlement'], $privateKey);
    expectRefusal('Theme/add-on SKU cannot masquerade as a core SKU',fn()=> $verifier->inspect($badZip));
    $fakeTheme=manifestFixture('paid-theme','theme');$fakeTheme['license_sku']=$paidSku;
    $fakeZip=$fixtureDir.'/paid-theme.zip';packageFixture($fakeZip,$fakeTheme,['readme.txt'=>'No paid themes'], $privateKey);
    expectRefusal('Theme cannot include module-only license SKU',fn()=> $verifier->inspect($fakeZip));

    $dependentZip = $fixtureDir . '/dependent-module.zip';
    packageFixture($dependentZip, manifestFixture('dependent-module', 'module', ['lite', 'pro'], ['base-module']), ['info.json' => '{}'], $privateKey);
    $store->install($dependentZip, 'lite', $caps);
    $store->changeStatus('module', 'dependent-module', true, 'lite', $caps);
    verifyPackageTest('Dependent is runnable when dependency is healthy', $store->isRunnable('module', 'dependent-module', 'lite', $caps));
    verifyPackageTest('Dependent fails closed if base capability disappears', !$store->isRunnable('module', 'dependent-module', 'lite', []));
    $basePayload = $root . '/storage/packages/modules/base-module/payload/readme.txt';
    $originalBaseContents = file_get_contents($basePayload);
    file_put_contents($basePayload, 'tampered dependency');
    verifyPackageTest('Dependent fails closed if signed dependency becomes corrupted', !$store->isRunnable('module', 'dependent-module', 'lite', $caps));
    file_put_contents($basePayload, $originalBaseContents);
    expectRefusal('Cannot disable active dependency', fn () => $store->changeStatus('module', 'base-module', false, 'lite', $caps));
    $store->changeStatus('module', 'dependent-module', false, 'lite', $caps);
    $store->changeStatus('module', 'base-module', false, 'lite', $caps);
    expectRefusal('Dependent activation requires active dependency', fn () => $store->changeStatus('module', 'dependent-module', true, 'lite', $caps));
    verifyPackageTest('Disabling module works', !$store->isRunnable('module', 'base-module', 'lite', $caps));

    $themeZip = $fixtureDir . '/midnight-theme.zip';
    packageFixture($themeZip, manifestFixture('midnight-theme', 'theme'), ['assets/theme.css' => '.theme{color:white}'], $privateKey);
    $store->install($themeZip, 'lite', $caps);
    $store->changeStatus('theme', 'midnight-theme', true, 'lite', $caps);
    verifyPackageTest('Theme selection state works', $store->isRunnable('theme', 'midnight-theme', 'lite', $caps));
    $theme2 = $fixtureDir . '/sunset-theme.zip';
    packageFixture($theme2, manifestFixture('sunset-theme', 'theme'), ['assets/theme.css' => '.theme{color:orange}'], $privateKey);
    $store->install($theme2, 'lite', $caps);
    $store->changeStatus('theme', 'sunset-theme', true, 'lite', $caps);
    verifyPackageTest('Selecting next theme disables previous one', !$store->isRunnable('theme', 'midnight-theme', 'lite', $caps));
    verifyPackageTest('New theme selected', $store->isRunnable('theme', 'sunset-theme', 'lite', $caps));

    $unsignedExtra = $fixtureDir . '/extra.zip';
    packageFixture($unsignedExtra, manifestFixture('unsigned-extra'), ['readme.txt' => 'ok'], $privateKey, ['payload/evil.css' => 'bad']);
    expectRefusal('Unsigned archive files rejected', fn () => $verifier->inspect($unsignedExtra));

    $badSig = $fixtureDir . '/bad-signature.zip';
    packageFixture($badSig, manifestFixture('bad-signature'), ['readme.txt' => 'ok'], $privateKey);
    $zip = new PharData($badSig, 0, null, Phar::ZIP);
    $zip['package.sig'] = base64_encode(random_bytes(256));
    unset($zip);
    expectRefusal('Invalid signature rejected', fn () => $verifier->inspect($badSig));

    $badPath = manifestFixture('unsafe-path');
    $badPath['files'] = [['path' => '../config.php', 'sha256' => hash('sha256', 'bad')]];
    $raw = json_encode($badPath, JSON_THROW_ON_ERROR);
    openssl_sign($raw, $sig, $privateKey, OPENSSL_ALGO_SHA256);
    expectRefusal('Traversal even if signed rejected', fn () => $verifier->verifyManifest($raw, base64_encode($sig)));

    $badPhp = manifestFixture('code-drop');
    $badPhp['files'] = [['path' => 'auto-run.php', 'sha256' => hash('sha256', '<?php echo 1;')]];
    $raw = json_encode($badPhp, JSON_THROW_ON_ERROR);
    openssl_sign($raw, $sig, $privateKey, OPENSSL_ALGO_SHA256);
    expectRefusal('Executable PHP payload rejected', fn () => $verifier->verifyManifest($raw, base64_encode($sig)));

    // Tamper with the installed payload after activation: even registry-enabled
    // modules must never be trusted without integrity and compatibility checks.
    file_put_contents($root . '/storage/packages/themes/sunset-theme/payload/assets/theme.css', 'evil');
    verifyPackageTest('Tampered enabled theme no longer runnable', !$store->isRunnable('theme', 'sunset-theme', 'lite', $caps));
    file_put_contents($root . '/storage/packages/themes/sunset-theme/payload/extra.txt', 'unsigned');
    expectRefusal('Unexpected installed files are rejected', fn () => $verifier->verifyInstalled($root . '/storage/packages/themes/sunset-theme'));

    $listed = $store->listPackages('lite', $caps);
    $bad = array_values(array_filter($listed, fn (array $p) => $p['id'] === 'sunset-theme'));
    verifyPackageTest('Tampered package displayed as blocked', count($bad) === 1 && count($bad[0]['blocked']) >= 1);
    unlink($keyPath);
    verifyPackageTest('Revoked trust disables active modules', !$store->isRunnable('theme', 'midnight-theme', 'lite', $caps));
    expectRefusal('Unknown publisher cannot install', fn () => $verifier->inspect($themeZip));
} finally {
    wipeFixture($root);
}
if ($failed !== []) {
    fwrite(STDERR, "FAILED package tests:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}
echo "Passed {$passed} signed-package lifecycle tests.\n";
