<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Installer\AtomicFile;
use AdultCore\Installer\InstallationGate;
use AdultCore\Installer\InstallationState;
use AdultCore\Licensing\CajunWebClient;
use AdultCore\Licensing\LicenseManager;
use AdultCore\Licensing\LicenseState;
use AdultCore\Licensing\LicenseUpdateRecovery;

$n = 0;
$check = static function (string $name, bool $passed) use (&$n): void {
    if (!$passed) throw new RuntimeException('License recovery test failed: '.$name);
    $n++;
};
$root = sys_get_temp_dir().'/adultcore-license-rollback-'.bin2hex(random_bytes(8));
mkdir($root);
mkdir($root.'/storage');
mkdir($root.'/config');
$secret = str_repeat('a', 64);
$key = 'ABCD-EF12-3456-7890';
$domain = 'creator.example';
file_put_contents($root.'/config/app-key.php', "<?php return ".var_export($secret, true).";");
file_put_contents($root.'/config/database.php', "<?php return ['driver'=>'mysql','database'=>'fixture','username'=>'fixture'];");
file_put_contents($root.'/storage/install-state.json', InstallationState::encode('lite', $secret));
$client = static fn (string $authorizedSku): CajunWebClient => new CajunWebClient(
    static function (string $body) use ($authorizedSku, $key, $domain): array {
        $request = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
        return ['status' => 200, 'body' => json_encode([
            'authorized' => $request['license_key'] === $key
                && $request['domain'] === $domain
                && $request['product_id'] === $authorizedSku,
        ], JSON_THROW_ON_ERROR)];
    }
);
try {
    $manager = new LicenseManager($root, $client(CajunWebClient::SKU_LITE_MONTH));
    $manager->persistInitial($manager->verifyForInstall($key, $domain), $secret);
    $gate = new InstallationGate($root);
    $check('Signed initial license site is ready', $gate->status() === InstallationGate::READY);

    $once = new LicenseManager($root, $client(CajunWebClient::SKU_LITE_ONCE));
    $once->replaceLicense($key, $domain);
    $check('Completed same-edition replacement changes term', $once->config()['sku'] === CajunWebClient::SKU_LITE_ONCE);
    $check('Successful replacement removes pending marker',
        $gate->status() === InstallationGate::READY
        && !file_exists($root.LicenseUpdateRecovery::MARKER));
    $check('Replacement cache remains valid', $once->status($domain) === 'valid');

    $oldConfig = $once->config();
    $oldCache = file_get_contents($root.'/storage/license-cache.json');
    $check('Old cache exists for snapshot', is_string($oldCache));
    $snapshot = LicenseUpdateRecovery::snapshot($oldConfig, $oldCache, $secret);
    $decoded = LicenseUpdateRecovery::decode($snapshot, $secret, 'lite');
    $check('Signed rollback snapshot preserves original key, SKU and cache',
        $decoded['sku'] === CajunWebClient::SKU_LITE_ONCE
        && $decoded['cache'] === $oldCache && $decoded['license_key'] === $key);

    $marker = $root.LicenseUpdateRecovery::MARKER;
    AtomicFile::write($marker, $snapshot);
    $mode = fileperms($marker);
    $check('Signed rollback snapshot is private owner-only storage',
        is_int($mode) && ($mode & 0077) === 0);
    // Simulate a crash after publishing the new license config but before
    // replacing its signed cache. No real vendor or database is touched.
    file_put_contents($root.'/config/license.php',
        "<?php return ".var_export([
            'license_key' => $key, 'domain' => $domain,
            'sku' => CajunWebClient::SKU_LITE_MONTH,
        ], true).";");
    $check('Pending marker locks all normal site access',
        $gate->status() === InstallationGate::LICENSE_UPDATE_PENDING);
    $check('Interrupted update blocks backup work',
        !$gate->eligibleForBackup());
    $check('Pending update cannot use old signed cache or issuer refresh',
        $once->status($domain) === 'unavailable');
    $calls = 0;
    $counting = new LicenseManager($root, new CajunWebClient(
        static function (string $body) use (&$calls): array {
            $calls++;
            return ['status' => 200, 'body' => '{"authorized":true}'];
        }
    ));
    try { $counting->replaceLicense($key, $domain); $blocked = false; }
    catch (RuntimeException) { $blocked = true; }
    $check('Second replacement blocked before issuer request', $blocked && $calls === 0);

    $damaged = str_replace('"sku": "'.CajunWebClient::SKU_LITE_ONCE.'"',
        '"sku": "'.CajunWebClient::SKU_PRO_ONCE.'"', $snapshot);
    $check('Tamper fixture changes signed marker', $damaged !== $snapshot);
    file_put_contents($marker, $damaged);
    try { (new LicenseUpdateRecovery($root))->rollback(); $blocked = false; }
    catch (RuntimeException) { $blocked = true; }
    $check('Tampered rollback snapshot refuses recovery', $blocked
        && $gate->status() === InstallationGate::LICENSE_UPDATE_PENDING);
    $check('Tampered recovery does not rewrite licensed configuration',
        (new LicenseManager($root))->config()['sku'] === CajunWebClient::SKU_LITE_MONTH);
    file_put_contents($marker, $snapshot);
    try { LicenseUpdateRecovery::decode($snapshot, $secret, 'pro'); $blocked = false; }
    catch (RuntimeException) { $blocked = true; }
    $check('Recovery cannot cross signed installed edition', $blocked);

    $cachePath = $root.'/storage/license-cache.json';
    $cacheBackup = $root.'/storage/cache-before-recovery';
    if (!rename($cachePath, $cacheBackup)) {
        throw new RuntimeException('Failed to prepare symlink recovery fixture.');
    }
    try {
        if (!@symlink($cacheBackup, $cachePath)) {
            throw new RuntimeException('Failed to prepare symlink fixture.');
        }
        try { (new LicenseUpdateRecovery($root))->rollback(); $blocked = false; }
        catch (RuntimeException) { $blocked = true; }
        $check('Symlinked rollback destination blocked', $blocked && file_exists($marker));
    } finally {
        if (is_link($cachePath)) unlink($cachePath);
        rename($cacheBackup, $cachePath);
    }

    (new LicenseUpdateRecovery($root))->rollback();
    $check('CLI rollback restores previous license SKU',
        (new LicenseManager($root))->config()['sku'] === CajunWebClient::SKU_LITE_ONCE);
    $check('CLI rollback restores previous signed cache', file_get_contents($cachePath) === $oldCache);
    $check('Rollback clears marker and unblocks installation gate',
        !file_exists($marker) && $gate->status() === InstallationGate::READY);
    $check('Recovered license is still authorized', $once->status($domain) === 'valid');
    try { (new LicenseUpdateRecovery($root))->rollback(); $blocked = false; }
    catch (RuntimeException) { $blocked = true; }
    $check('Rollback refuses absent pending marker', $blocked);

    // A marker symlink is never followed, even if it points to a signed
    // installation record. It must not restore or authorize anything.
    if (!@symlink($root.'/storage/install-state.json', $marker)) {
        throw new RuntimeException('Could not create marker symlink test fixture.');
    }
    try {
        $check('Marker symlink locks the installation', $gate->status() === InstallationGate::LICENSE_UPDATE_PENDING);
        $check('Marker symlink blocks installed license status', $once->status($domain) === 'unavailable');
        try { (new LicenseUpdateRecovery($root))->rollback(); $blocked = false; }
        catch (RuntimeException) { $blocked = true; }
        $check('Recovery never follows marker symlink', $blocked);
    } finally {
        unlink($marker);
    }

    // Missing-cache history must be restored as missing, not forged valid.
    unlink($cachePath);
    AtomicFile::write($marker, LicenseUpdateRecovery::snapshot($once->config(), null, $secret));
    file_put_contents($cachePath, '{"unsigned":"placeholder"}');
    (new LicenseUpdateRecovery($root))->rollback();
    $check('Rollback to cacheless history removes replaced cache', !file_exists($cachePath)
        && $gate->status() === InstallationGate::READY);

    // An explicitly revoked/denied historical receipt must remain denied
    // after rollback; recovery never fabricates a positive license verdict.
    $deniedCache = LicenseState::encode($key, $domain, $secret, 'denied',
        time(), time() - 60, CajunWebClient::SKU_LITE_ONCE);
    file_put_contents($cachePath, $deniedCache);
    AtomicFile::write($marker, LicenseUpdateRecovery::snapshot($once->config(), $deniedCache, $secret));
    file_put_contents($cachePath, LicenseState::encode($key, $domain, $secret, 'valid',
        time(), time(), CajunWebClient::SKU_LITE_ONCE));
    (new LicenseUpdateRecovery($root))->rollback();
    $check('Rollback preserves signed explicit denial rather than creating authority',
        file_get_contents($cachePath) === $deniedCache && $once->status($domain) === 'denied');

    $cli = file_get_contents(dirname(__DIR__).'/scripts/recover-license-update.php');
    $check('Recovery script is console-only and requires explicit rollback option',
        str_contains($cli, "PHP_SAPI !== 'cli'") && str_contains($cli, "'--rollback'"));
} finally {
    foreach (glob($root.'/config/{*,.*}', GLOB_BRACE) ?: [] as $path) {
        if (is_file($path) || is_link($path)) unlink($path);
    }
    foreach (glob($root.'/storage/{*,.*}', GLOB_BRACE) ?: [] as $path) {
        if (is_file($path) || is_link($path)) unlink($path);
    }
    rmdir($root.'/storage');
    rmdir($root.'/config');
    rmdir($root);
}
echo "Passed {$n} interrupted license-update recovery checks.\n";
