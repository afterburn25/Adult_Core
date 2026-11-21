<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Capability\DeploymentPreflight;
use AdultCore\Capability\HostInspector;
use AdultCore\Installer\InstallationState;
use AdultCore\Licensing\CajunWebClient;

$checks = 0;
function ensure(bool $pass, string $message): void {
    global $checks;
    if (!$pass) throw new RuntimeException($message);
    $checks++;
}
function row(array $report, string $id): array {
    foreach ($report['checks'] as $r) if ($r['id'] === $id) return $r;
    throw new RuntimeException('Missing report field ' . $id);
}
function removeTree(string $root): void {
    if (!is_dir($root)) return;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname());
        else unlink($item->getPathname());
    }
    rmdir($root);
}
$source = dirname(__DIR__);
$base = sys_get_temp_dir() . '/adult-deployment-' . bin2hex(random_bytes(6));
mkdir($base, 0700);
try {
    foreach (['public','public/assets','install','config','config/editions','storage'] as $dir) mkdir($base . '/' . $dir, 0700, true);
    foreach (['lite','pro'] as $name) copy($source . '/config/editions/' . $name . '.json', $base . '/config/editions/' . $name . '.json');
    foreach (['index.php','router.php','.htaccess'] as $name) copy($source . '/public/' . $name, $base . '/public/' . $name);
    $tool = new DeploymentPreflight($base);
    $fresh = $tool->inspect($base . '/public', 'lite', 'preinstall');
    ensure($fresh['schema'] === 'adult-core-deployment-preflight-v1', 'Versioned machine report');
    ensure($fresh['expected_edition'] === 'lite', 'Edition label');
    ensure(row($fresh,'installation_gate')['pass'], 'Fresh installation status');
    ensure(row($fresh,'fresh_private_state')['pass'], 'No secrets on fresh setup');
    ensure(row($fresh,'no_public_symlinks_or_executables')['pass'], 'No public executables beyond router');
    ensure(row($fresh,'host_secure_public_document_root')['pass'], 'Exact document root accepted');
    ensure(in_array('public_docroot', (new HostInspector($base))->inspect($base . '/public')['capabilities'], true),
        'Browser installer host inspector accepts safe bundled public layout');
    ensure(count($fresh['external_verification_required']) >= 5, 'Manual checks persist even on local pass');
    ensure(!isset($fresh['license_key']), 'No key in report');

    $wrong = $tool->inspect($base, 'lite', 'preinstall');
    ensure(!row($wrong,'host_secure_public_document_root')['pass'], 'Repository-root docroot is rejected');
    ensure($wrong['result'] === 'blocked', 'Wrong docroot blocks preflight');
    file_put_contents($base . '/public/assets/execute.phtml', '<?php echo 1;');
    $executable = $tool->inspect($base . '/public', 'lite', 'preinstall');
    ensure(!row($executable,'no_public_symlinks_or_executables')['pass'], 'Executable assets rejected');
    ensure(!in_array('public_docroot', (new HostInspector($base))->inspect($base . '/public')['capabilities'], true),
        'Installer refuses unexpected public executable even with correct document root');
    ensure(str_contains(row($executable,'no_public_symlinks_or_executables')['detail'],'execute.phtml'), 'Unsafe path diagnosed');
    unlink($base . '/public/assets/execute.phtml');
    file_put_contents($base . '/public/assets/.htaccess', 'Require all granted');
    ensure(!row($tool->inspect($base . '/public', 'lite', 'preinstall'),'no_public_symlinks_or_executables')['pass'], 'Nested rewrite overrides rejected');
    unlink($base . '/public/assets/.htaccess');
    file_put_contents($base . '/public/assets/composer.json', '{}');
    ensure(!row($tool->inspect($base . '/public', 'lite', 'preinstall'),'no_public_symlinks_or_executables')['pass'], 'Copied application metadata rejected');
    unlink($base . '/public/assets/composer.json');

    symlink($base . '/config', $base . '/public/config');
    $linked = $tool->inspect($base . '/public', 'lite', 'preinstall');
    ensure(!row($linked,'no_public_symlinks_or_executables')['pass'], 'Symlinked public tree rejected');
    ensure(!row($linked,'private_config')['pass'], 'Private configuration exposure rejected');
    unlink($base . '/public/config');
    mkdir($base . '/public/storage');
    ensure(!row($tool->inspect($base . '/public', 'lite', 'preinstall'),'private_storage')['pass'], 'Plain copied private directory rejected');
    ensure(!in_array('public_docroot', (new HostInspector($base))->inspect($base . '/public')['capabilities'], true),
        'Installer refuses even an empty private storage directory inside public');
    rmdir($base . '/public/storage');

    file_put_contents($base . '/public/.htaccess', 'Options Indexes');
    ensure(!row($tool->inspect($base . '/public', 'lite', 'preinstall'),'apache_routing_file')['pass'], 'Broken Apache routing rejected');
    ensure(!in_array('public_docroot', (new HostInspector($base))->inspect($base . '/public')['capabilities'], true),
        'Installer refuses broken bundled Apache safety rules');
    copy($source . '/public/.htaccess', $base . '/public/.htaccess');
    $apache=(string)file_get_contents($base . '/public/.htaccess');
    ensure(str_contains($apache,'[F,L,NC]') && str_contains($apache,'<FilesMatch'),
        'Apache distribution refuses unexpected executable scripts directly');
    file_put_contents($base . '/public/.htaccess',str_replace('[F,L,NC]','[L]',$apache));
    ensure(!row($tool->inspect($base . '/public','lite','preinstall'),'apache_routing_file')['pass'],
        'Deployment preflight refuses Apache policy without direct-script denial');
    ensure(!in_array('public_docroot',(new HostInspector($base))->inspect($base . '/public')['capabilities'],true),
        'Browser installer refuses Apache policy missing executable-denial guard');
    file_put_contents($base . '/public/.htaccess',$apache);
    file_put_contents($base . '/config/license.php', '<?php return '. var_export([
        'license_key' => 'FAKE-TEST-KEY-0001', 'domain' => 'staging.example.test',
        'sku' => CajunWebClient::SKU_LITE_MONTH,
    ], true) . ';');
    $existing = $tool->inspect($base . '/public', 'lite', 'preinstall');
    ensure(!row($existing,'fresh_private_state')['pass'], 'Preinstall refuses existing license state');

    $key = bin2hex(random_bytes(32));
    file_put_contents($base . '/config/app-key.php', '<?php return ' . var_export($key, true) . ';');
    file_put_contents($base . '/config/database.php', '<?php return '.var_export([
        'driver'=>'mysql','database'=>'fake_test_db','username'=>'fake_user',
    ], true).';');
    file_put_contents($base . '/storage/install-state.json', InstallationState::encode('lite', $key));
    rmdir($base . '/install');
    $installed = $tool->inspect($base . '/public', 'lite', 'installed');
    ensure(row($installed,'installation_gate')['pass'], 'Signed, cleaned-up installation recognized');
    ensure(row($installed,'signed_edition_matches')['pass'], 'Locally sealed monthly Lite SKU recognized');
    ensure(!str_contains(json_encode($installed, JSON_THROW_ON_ERROR),'FAKE-TEST-KEY'), 'License key never emitted in report');
    $mismatch = $tool->inspect($base . '/public', 'pro', 'installed');
    ensure(!row($mismatch,'signed_edition_matches')['pass'], 'Pro expected refuses signed Lite');
    ensure(!row($mismatch,'edition_capabilities')['pass'] || row($mismatch,'signed_edition_matches')['pass'] === false,
        'Pro capability and edition mismatch cannot be masked');
    file_put_contents($base . '/storage/install-state.json', '{"edition":"pro"}');
    ensure(!row($tool->inspect($base . '/public', 'lite', 'installed'),'installation_gate')['pass'], 'Tampered install marker blocked');
    ensure(!row($tool->inspect($base . '/public', 'lite', 'installed'),'signed_edition_matches')['pass'], 'Tampered edition cannot pass');
    foreach ([['lite','unknown'],['enterprise','preinstall'],['lite','installed']] as [$edition,$phase]) {
        if ($edition === 'lite' && $phase === 'installed') continue;
        try { $tool->inspect($base . '/public', $edition, $phase); throw new RuntimeException('Invalid input was accepted'); }
        catch (RuntimeException $error) { ensure($error->getMessage() !== 'Invalid input was accepted','Input guard'); }
    }
    try { $tool->inspect('public','lite','preinstall'); throw new RuntimeException('Relative docroot accepted'); }
    catch (RuntimeException $e) { ensure($e->getMessage() !== 'Relative docroot accepted','Absolute path required'); }
    echo "Passed $checks deployment preflight checks.\n";
} finally { removeTree($base); }
