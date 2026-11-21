<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Edition\EditionSelector;
use AdultCore\Module\ModuleCompatibility;
use AdultCore\Support\ManifestLoader;
use AdultCore\Capability\HostInspector;
use AdultCore\Installer\InstallationGate;
use AdultCore\Installer\InstallationState;
use AdultCore\Installer\InstallInput;
use AdultCore\Installer\AtomicFile;
use AdultCore\Installer\InstallRateLimiter;
use AdultCore\Auth\AdminSession;
use AdultCore\Auth\LoginRateLimiter;
use AdultCore\Auth\RolePolicy;

$root = dirname(__DIR__);
$selector = new EditionSelector($root . '/config/editions');
$compat = new ModuleCompatibility();
$checks = 0;
$failures = [];

function assertCheck(string $name, bool $condition): void
{
    global $checks, $failures;
    $checks++;
    if (!$condition) {
        $failures[] = $name;
    }
}

$base = ['php_83','pdo_mysql','json','openssl','fileinfo','sessions','storage_writable','config_writable','public_docroot'];
$pro = [...$base, 'pro_agent', 'persistent_process', 'ffmpeg'];
$bad = ['php_83', 'json'];

assertCheck('Lite chosen when base capabilities are available', $selector->select($base)['edition'] === 'lite');
assertCheck('Pro chosen when verified capabilities are available', $selector->select($pro)['edition'] === 'pro');
assertCheck('Installation blocked when basic requirements missing', $selector->select($bad)['edition'] === null);
assertCheck('Pro missing exactly the verified agent and process when Lite', $selector->missing('pro', $base) === ['pro_agent', 'persistent_process']);
assertCheck('Pro also requires all of Lite', $selector->missing('pro', $bad) !== []);

$gallery = ManifestLoader::load($root . '/modules/photo-gallery/module.json');
$video = ManifestLoader::load($root . '/modules/video-streaming/module.json');
$chat = ManifestLoader::load($root . '/modules/realtime-chat/module.json');
assertCheck('Gallery module allowed on Lite', $compat->blockedReasons($gallery, 'lite', $base) === []);
assertCheck('Gallery module allowed on Pro', $compat->blockedReasons($gallery, 'pro', $pro) === []);
assertCheck('Video module rejected on Lite', $compat->blockedReasons($video, 'lite', $base) !== []);
try {
    $compat->assertInstallable($video, 'lite', $base);
    assertCheck('Install guard refuses Pro module on Lite', false);
} catch (RuntimeException $e) {
    assertCheck('Install guard refuses Pro module on Lite', str_contains($e->getMessage(), 'refused'));
}
try {
    $compat->assertInstallable($gallery, 'lite', $base);
    assertCheck('Install guard permits compatible Lite module', true);
} catch (RuntimeException) {
    assertCheck('Install guard permits compatible Lite module', false);
}

assertCheck('Video module rejected even on Pro without video pipeline', $compat->blockedReasons($video, 'pro', $pro) !== []);
assertCheck('Video module allowed when Pro pipeline is installed', $compat->blockedReasons($video, 'pro', [...$pro, 'video_pipeline']) === []);
assertCheck('Chat rejected without realtime service', $compat->blockedReasons($chat, 'pro', $pro) !== []);
assertCheck('Chat allowed when websocket service provisioned', $compat->blockedReasons($chat, 'pro', [...$pro, 'websocket_service']) === []);
assertCheck('Module cannot run on unqualified core', $compat->blockedReasons($gallery, null, $bad) !== []);

$requiresNewCore = $gallery;
$requiresNewCore['minimum_core_version'] = '9.0.0';
assertCheck('Module minimum core version enforced', $compat->blockedReasons($requiresNewCore, 'lite', $base) !== []);
$dependent = $gallery;
$dependent['dependencies'] = ['media-library'];
assertCheck('Module dependencies enforced', $compat->blockedReasons($dependent, 'lite', $base) !== []);
assertCheck('Module dependencies satisfied', $compat->blockedReasons($dependent, 'lite', $base, ['media-library']) === []);

$invalid = $gallery;
$invalid['id'] = '../bad';
try {
    $compat->blockedReasons($invalid, 'lite', $base);
    assertCheck('Invalid module ID rejected', false);
} catch (RuntimeException) {
    assertCheck('Invalid module ID rejected', true);
}

// Real local probe must not create or modify installation state.
$real = (new HostInspector($root))->inspect($root . '/public');
assertCheck('Real host scanner returns checks', count($real['checks']) >= 9);
assertCheck('Real host scanner recognizes secure docroot', in_array('public_docroot', $real['capabilities'], true));
assertCheck('Host scanner rejects wrong document root', !in_array('public_docroot', (new HostInspector($root))->inspect($root)['capabilities'], true));

// The real installer will write a completion marker only after migrations,
// admin bootstrap and edition persistence succeed. These synthetic test
// fixtures must never create that marker in a real deployment.
$fixtureRoot = sys_get_temp_dir() . '/adult-core-gate-' . bin2hex(random_bytes(8));
mkdir($fixtureRoot);
mkdir($fixtureRoot . '/storage');
mkdir($fixtureRoot . '/install');
$gate = new InstallationGate($fixtureRoot);
assertCheck('New site with installer routes to setup', $gate->status() === InstallationGate::SETUP_REQUIRED);
try {
    $gate->assertSiteAccessible();
    assertCheck('Cannot access site before installation', false);
} catch (RuntimeException) {
    assertCheck('Cannot access site before installation', true);
}

$stateFile = $fixtureRoot . '/storage/install-state.json';
file_put_contents($stateFile, '{broken json');
assertCheck('Invalid completion state fails closed', $gate->status() === InstallationGate::INVALID_STATE);
$appKey = bin2hex(random_bytes(32));
mkdir($fixtureRoot . '/config');
file_put_contents($fixtureRoot . '/config/app-key.php', "<?php return '" . $appKey . "';");
file_put_contents($fixtureRoot . '/config/database.php', "<?php return ['driver' => 'mysql', 'database' => 'fixture', 'username' => 'fixture'];");
file_put_contents($stateFile, InstallationState::encode('lite', $appKey, '2026-11-20T00:00:00Z'));
assertCheck('Signed completion state accepted', $gate->status() === InstallationGate::CLEANUP_REQUIRED);
if (function_exists('symlink')) {
    $secretPath = $fixtureRoot . '/config/app-key.php';
    $symlinkTarget = $fixtureRoot . '/storage/secret-original.php';
    rename($secretPath, $symlinkTarget);
    if (@symlink($symlinkTarget, $secretPath)) {
        assertCheck('Symlinked application secret cannot establish ready state',
            $gate->status() === InstallationGate::INVALID_STATE);
        unlink($secretPath);
    }
    rename($symlinkTarget, $secretPath);
}

unlink($fixtureRoot . '/config/database.php');
assertCheck('Missing DB config invalidates signed marker', $gate->status() === InstallationGate::INVALID_STATE);
file_put_contents($fixtureRoot . '/config/database.php', "<?php return ['driver' => 'mysql', 'database' => 'fixture', 'username' => 'fixture'];");
file_put_contents($stateFile, str_replace('"edition": "lite"', '"edition": "pro"', (string) file_get_contents($stateFile)));
assertCheck('Tampered completion marker rejected', $gate->status() === InstallationGate::INVALID_STATE);
file_put_contents($stateFile, InstallationState::encode('lite', $appKey));
assertCheck('Completed site locked while install directory exists', $gate->status() === InstallationGate::CLEANUP_REQUIRED);
try {
    $gate->assertSiteAccessible();
    assertCheck('Admin/site access refused until installer removed', false);
} catch (RuntimeException) {
    assertCheck('Admin/site access refused until installer removed', true);
}
rmdir($fixtureRoot . '/install');
assertCheck('Deleting install directory unlocks completed site', $gate->status() === InstallationGate::READY);
try {
    $gate->assertSiteAccessible();
    assertCheck('Site access guard allows completed clean installs', true);
} catch (RuntimeException) {
    assertCheck('Site access guard allows completed clean installs', false);
}
unlink($stateFile);
assertCheck('Missing marker with existing private config is invalid, not fresh setup',
    $gate->status() === InstallationGate::INVALID_STATE);
try {
    $gate->assertSiteAccessible();
    assertCheck('Private files without marker never unlock the site', false);
} catch (RuntimeException) {
    assertCheck('Private files without marker never unlock the site', true);
}
unlink($fixtureRoot . '/config/app-key.php');
unlink($fixtureRoot . '/config/database.php');
assertCheck('Missing installer and no private state stays locked',
    $gate->status() === InstallationGate::INSTALLER_MISSING);
// Even a remnant license file blocks the clean setup path after a failed install.
file_put_contents($fixtureRoot . '/config/license.php', '<?php return [];');
assertCheck('Orphaned private license without marker means invalid state',
    $gate->status() === InstallationGate::INVALID_STATE);
unlink($fixtureRoot . '/config/license.php');
file_put_contents($fixtureRoot . '/storage/license-cache.json', '{}');
assertCheck('Orphaned license cache without marker means invalid state',
    $gate->status() === InstallationGate::INVALID_STATE);
unlink($fixtureRoot . '/storage/license-cache.json');
rmdir($fixtureRoot . '/config');
rmdir($fixtureRoot . '/storage');
rmdir($fixtureRoot);

$goodInput = [
    'license_key' => 'ABCD-EF12-3456-7890', 'license_domain' => 'example.test',
    'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => 'test_db',
    'db_user' => 'test_user', 'db_password' => 'some-private-pass',
    'site_name' => 'Test Membership', 'admin_email' => 'OWNER@EXAMPLE.COM',
    'admin_password' => 'some-long-secret-password', 'admin_password_confirm' => 'some-long-secret-password',
];
$validated = InstallInput::validate($goodInput);
assertCheck('Valid installer input normalizes email and port', $validated['admin_email'] === 'owner@example.com' && $validated['db_port'] === 3306);
foreach ([
    'Invalid license key rejected' => ['license_key', 'short'],
    'Invalid license hostname rejected' => ['license_domain', 'https://bad.example.com/x'],
    'Invalid DB host rejects DSN injection' => ['db_host', 'localhost;dbname=bad'],
    'Invalid DB name rejects SQL injection' => ['db_name', 'name`;DROP TABLE x'],
    'Weak admin password rejected' => ['admin_password', '123'],
    'Password mismatch rejected' => ['admin_password_confirm', 'different password'],
    'Invalid DB port rejected' => ['db_port', '0'],
    'Invalid email rejected' => ['admin_email', 'not-an-email'],
    'Excessively long password rejected' => ['admin_password', str_repeat('A', 73)],
] as $name => [$field, $value]) {
    try {
        InstallInput::validate(array_replace($goodInput, [$field => $value]));
        assertCheck($name, false);
    } catch (InvalidArgumentException) {
        assertCheck($name, true);
    }
}
foreach ([
    ['example.test', 'example.test'],
    ['example.test', 'WWW.EXAMPLE.TEST'],
    ['www.example.test', 'example.test:443'],
    ['example.test', 'example.test:8443'],
] as [$registeredHost, $requestHost]) {
    try {
        InstallInput::assertRequestHost($registeredHost, $requestHost);
        assertCheck('Installer accepts matching request host '.$requestHost, true);
    } catch (InvalidArgumentException) {
        assertCheck('Installer accepts matching request host '.$requestHost, false);
    }
}
foreach ([
    ['example.test', 'other.example.test'],
    ['example.test', 'example.test.evil.test'],
    ['example.test', 'example.test:0'],
    ['example.test', 'example.test:65536'],
    ['example.test', 'https://example.test'],
    ['example.test', 'example.test/path'],
    ['example.test', 'example.test:443@evil.test'],
    ['example.test', 'example.test\\r\\nHost: evil.test'],
    ['example.test', '127.0.0.1'],
] as [$registeredHost, $requestHost]) {
    try {
        InstallInput::assertRequestHost($registeredHost, $requestHost);
        assertCheck('Installer rejects mismatched/unsafe request host '.$requestHost, false);
    } catch (InvalidArgumentException) {
        assertCheck('Installer rejects mismatched/unsafe request host '.$requestHost, true);
    }
}

// InstallService must revalidate normalized fields even if a caller skips the
// browser controller. Its input has an integer port and no confirmation field.
$serviceInput = $goodInput;
$serviceInput['db_port'] = 3306;
unset($serviceInput['admin_password_confirm']);
$serviceValidated = InstallInput::validateForService($serviceInput);
assertCheck('Service input accepts validated normalized installer fields',
    $serviceValidated['db_port'] === 3306 && $serviceValidated['admin_email'] === 'owner@example.com');
foreach ([
    'Direct service rejects DSN host injection' => ['db_host', 'localhost;dbname=other'],
    'Direct service rejects invalid database name' => ['db_name', 'bad;DROP'],
    'Direct service rejects invalid port' => ['db_port', '3306;host=evil'],
    'Direct service rejects invalid port type' => ['db_port', ['3306']],
    'Direct service rejects missing administrator email' => ['admin_email', null],
    'Direct service rejects missing administrator password' => ['admin_password', null],
    'Direct service rejects short administrator password' => ['admin_password', '123'],
    'Direct service rejects unsafe license hostname' => ['license_domain', 'other.test:443'],
] as $name => [$field, $value]) {
    try {
        InstallInput::validateForService(array_replace($serviceInput, [$field => $value]));
        assertCheck($name, false);
    } catch (InvalidArgumentException) {
        assertCheck($name, true);
    }
}

$testRoot = sys_get_temp_dir() . '/adult-core-installer-' . bin2hex(random_bytes(6));
mkdir($testRoot);
AtomicFile::write($testRoot . '/secret.php', '<?php return 1;');
assertCheck('Private atomic file exists with content', file_get_contents($testRoot . '/secret.php') === '<?php return 1;');
assertCheck('Atomic file is private to owner', (fileperms($testRoot . '/secret.php') & 077) === 0);
try {
    AtomicFile::write($testRoot . '/secret.php', '<?php return 2;');
    assertCheck('Atomic secret file refuses overwrite', false);
} catch (RuntimeException) {
    assertCheck('Atomic secret file refuses overwrite', true);
}
$limiter = new InstallRateLimiter($testRoot);
for ($i = 0; $i < 5; $i++) {
    $limiter->consume('127.0.0.1');
}
try {
    $limiter->consume('127.0.0.1');
    assertCheck('Installer rate limits repeated attempts', false);
} catch (RuntimeException) {
    assertCheck('Installer rate limits repeated attempts', true);
}
foreach (glob($testRoot . '/*') ?: [] as $file) { unlink($file); }
foreach (glob($testRoot . '/.*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
rmdir($testRoot);


// Milestone 3: administrator authorization, login protection and session expiry.
assertCheck('Admin may view dashboard', RolePolicy::allows('admin', 'admin.dashboard.view'));
assertCheck('Owner may manage modules', RolePolicy::allows('owner', 'modules.manage'));
assertCheck('Moderator cannot access admin dashboard', !RolePolicy::allows('moderator', 'admin.access'));
assertCheck('Member cannot access dashboard', !RolePolicy::allows('member', 'admin.dashboard.view'));
assertCheck('Unknown role denied', !RolePolicy::allows('unknown', 'admin.access'));
assertCheck('Unknown permission denied', !RolePolicy::allows('admin', 'unexpected.permission'));

$authRoot = sys_get_temp_dir() . '/adult-core-auth-' . bin2hex(random_bytes(8));
mkdir($authRoot);
mkdir($authRoot . '/storage');
$authLimiter = new LoginRateLimiter($authRoot . '/storage');
for ($i = 0; $i < 8; $i++) {
    $authLimiter->consume('203.0.113.' . $i, 'owner@example.test', 2000000000);
}
try {
    $authLimiter->consume('203.0.113.44', 'owner@example.test', 2000000000);
    assertCheck('Account login attempts are limited across IPs', false);
} catch (RuntimeException $e) {
    assertCheck('Account login attempts are limited across IPs', str_contains($e->getMessage(), 'Too many login'));
}
$separateIp = new LoginRateLimiter($authRoot . '/storage');
for ($i = 0; $i < 15; $i++) {
    $separateIp->consume('198.51.100.11', 'user' . $i . '@example.test', 2000000000);
}
try {
    $separateIp->consume('198.51.100.11', 'another@example.test', 2000000000);
    assertCheck('IP login attempts are limited across accounts', false);
} catch (RuntimeException $e) {
    assertCheck('IP login attempts are limited across accounts', str_contains($e->getMessage(), 'Too many login'));
}
// Simulate the private session lifecycle independently of a MySQL server.
AdminSession::start($authRoot, false);
$csrfBeforeLogin = AdminSession::csrf();
assertCheck('Admin session generates CSRF token', strlen($csrfBeforeLogin) === 64);
assertCheck('Admin session rejects invalid CSRF token', !AdminSession::csrfValid('malicious'));
assertCheck('Admin session validates its CSRF token', AdminSession::csrfValid($csrfBeforeLogin));
AdminSession::authenticate(13, 2000000000);
assertCheck('Login rotates CSRF token', $csrfBeforeLogin !== AdminSession::csrf());
assertCheck('Admin session retains authenticated ID', AdminSession::authenticatedId(2000000100) === 13);
assertCheck('Admin session expires after 30 minutes idle', AdminSession::authenticatedId(2000002000) === null);
AdminSession::authenticate(14, 2000000000);
assertCheck('Admin session expires after 12 hours absolute', AdminSession::authenticatedId(2000043200) === null);
AdminSession::authenticate(15, 2000000000);
AdminSession::revoke();
assertCheck('Revoked session denies access', AdminSession::authenticatedId(2000000001) === null);
AdminSession::logout();
foreach (glob($authRoot . '/storage/*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
foreach (glob($authRoot . '/storage/.*') ?: [] as $file) { if (is_file($file)) { unlink($file); } }
foreach (glob($authRoot . '/storage/sessions/*') ?: [] as $file) { unlink($file); }
rmdir($authRoot . '/storage/sessions');
rmdir($authRoot . '/storage');
rmdir($authRoot);

if ($failures !== []) {
    fwrite(STDERR, "FAILED: " . implode('; ', $failures) . "\n");
    exit(1);
}
echo "Passed {$checks} tests.\n";
