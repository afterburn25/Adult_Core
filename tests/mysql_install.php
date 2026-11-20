<?php

declare(strict_types=1);

/**
 * Real MySQL/MariaDB installation integration test.
 * IMPORTANT: ONLY run against a disposable, initially empty database.
 * Requires AC_TEST_DB_CONFIRM=YES_THIS_IS_A_DISPOSABLE_DATABASE.
 * Example environment variables are documented in docs/TESTING.md.
 */

require dirname(__DIR__) . '/src/Support/Autoload.php';

use AdultCore\Database\Schema;
use AdultCore\Database\Connection;
use AdultCore\Auth\AdminAccounts;
use AdultCore\Installer\InstallService;
use AdultCore\Installer\InstallationGate;

if (getenv('AC_TEST_DB_CONFIRM') !== 'YES_THIS_IS_A_DISPOSABLE_DATABASE') {
    fwrite(STDERR, "Refusing to run: disposable test database confirmation is missing.\n");
    exit(2);
}
if (!extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "MySQL PDO extension required for integration test.\n");
    exit(2);
}
$host = getenv('AC_TEST_DB_HOST') ?: '127.0.0.1';
$port = getenv('AC_TEST_DB_PORT') ?: '3306';
$name = getenv('AC_TEST_DB_NAME');
$user = getenv('AC_TEST_DB_USER');
$pass = getenv('AC_TEST_DB_PASSWORD');
if (!is_string($name) || !is_string($user) || !is_string($pass) || $name === '' || $user === '') {
    throw new RuntimeException('Test DB environment variables are missing.');
}
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
Schema::assertFresh($pdo); // Prevent any chance of modifying an existing Adult Core installation.
$root = sys_get_temp_dir() . '/adult-core-db-test-' . bin2hex(random_bytes(8));
mkdir($root);
mkdir($root . '/config');
mkdir($root . '/install');
mkdir($root . '/storage');
$inputs = [
    'db_host' => $host, 'db_port' => (int) $port, 'db_name' => $name,
    'db_user' => $user, 'db_password' => $pass,
    'site_name' => 'Disposable Integration Site',
    'admin_email' => 'owner@example.test',
    'admin_password' => 'super-secret-example-password',
];
try {
    (new InstallService($root))->install($inputs, 'lite');
    $gate = new InstallationGate($root);
    if ($gate->status() !== InstallationGate::CLEANUP_REQUIRED) {
        throw new RuntimeException('Gate did not enforce installer cleanup.');
    }
    $account = $pdo->query("SELECT email, password_hash, role FROM ac_users LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!is_array($account) || $account['role'] !== 'admin' || $account['email'] !== 'owner@example.test'
        || !password_verify('super-secret-example-password', $account['password_hash'])) {
        throw new RuntimeException('Administrator bootstrap did not verify.');
    }
    $edition = $pdo->query('SELECT edition FROM ac_installations WHERE id = 1')->fetchColumn();
    if ($edition !== 'lite') {
        throw new RuntimeException('Persisted edition mismatch.');
    }
    // Verify runtime authentication against actual installed schema/config.
    $auth = new AdminAccounts(Connection::open($root));
    $valid = $auth->verifyCredentials('OWNER@EXAMPLE.TEST', 'super-secret-example-password');
    if (!is_array($valid) || $valid['role'] !== 'admin' || $valid['email'] !== 'owner@example.test') {
        throw new RuntimeException('Real MySQL administrator login failed.');
    }
    if ($auth->verifyCredentials('owner@example.test', 'incorrect-password') !== null
        || $auth->verifyCredentials('missing@example.test', 'super-secret-example-password') !== null) {
        throw new RuntimeException('Incorrect/unknown credentials were accepted.');
    }
    $pdo->prepare('UPDATE ac_users SET role = ? WHERE id = ?')->execute(['member', $valid['id']]);
    if ($auth->verifyCredentials('owner@example.test', 'super-secret-example-password') !== null
        || $auth->authorizedById($valid['id']) !== null) {
        throw new RuntimeException('Revoked administrator role still grants access.');
    }
    $pdo->prepare('UPDATE ac_users SET role = ? WHERE id = ?')->execute(['admin', $valid['id']]);
    if ($auth->authorizedById($valid['id']) === null) {
        throw new RuntimeException('Restored administrator role not recognized.');
    }
    rmdir($root . '/install');
    if ($gate->status() !== InstallationGate::READY) {
        throw new RuntimeException('Gate did not unlock after installer deletion.');
    }
    echo "Passed real MySQL installation, administrator login/role revocation, signed marker and cleanup tests.\n";
} finally {
    // Only touch the four tables this test created. The database is explicitly disposable.
    foreach (['ac_installations', 'ac_settings', 'ac_users', 'ac_schema_migrations'] as $table) {
        $pdo->exec("DROP TABLE IF EXISTS {$table}");
    }
    foreach (['storage', 'config'] as $directory) {
        foreach (glob($root . '/' . $directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($root . '/' . $directory . '/.*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($root . '/' . $directory);
    }
    if (is_dir($root . '/install')) {
        rmdir($root . '/install');
    }
    rmdir($root);
}
