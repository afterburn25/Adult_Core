"""Direct HTTP contract for InstallService hostname enforcement.

Uses an isolated synthetic installation and a mock denying license authority.
Never accesses Cajun Web Pro or a real MySQL database.
"""
import os
import pathlib
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

SOURCE = pathlib.Path(__file__).resolve().parents[1]

ROUTER = r"""<?php
declare(strict_types=1);
require getenv('AC_TEST_SOURCE') . '/src/Support/Autoload.php';

$root = (string) getenv('AC_TEST_ROOT');
$mode = (string) ($_GET['case'] ?? '');
$fields = [
    'license_key' => 'ABCD-EF12-3456-7890',
    'license_domain' => $mode === 'match' ? '127.0.0.1' : 'wrong.example.test',
];
$issuer = new \AdultCore\Licensing\CajunWebClient(static function (string $body) use ($root): array {
    file_put_contents($root . '/storage/issuer-called', '1');
    return ['status' => 200, 'body' => '{"authorized":false}'];
});
try {
    (new \AdultCore\Installer\InstallService($root, $issuer))->install($fields);
    http_response_code(500);
    echo 'UNEXPECTED_INSTALL';
} catch (\InvalidArgumentException $error) {
    http_response_code(422);
    echo 'HOST_REJECTED';
} catch (\RuntimeException $error) {
    http_response_code(409);
    echo str_contains($error->getMessage(), 'License not authorized')
        ? 'ISSUER_DENIED' : 'UNEXPECTED_ERROR';
}
"""


def fetch(url):
    try:
        response = urllib.request.urlopen(url, timeout=3)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, response.read().decode('utf-8', errors='replace')


def run():
    with tempfile.TemporaryDirectory(prefix='adult-core-service-http-') as directory:
        root = pathlib.Path(directory)
        for path in ('storage', 'config', 'install', 'public'):
            (root / path).mkdir()
        router = root / 'router.php'
        router.write_text(ROUTER, encoding='utf-8')
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            port = sock.getsockname()[1]
        env = dict(os.environ, AC_TEST_ROOT=str(root), AC_TEST_SOURCE=str(SOURCE))
        server = subprocess.Popen(
            ['php', '-S', f'127.0.0.1:{port}', '-t', str(root / 'public'), str(router)],
            cwd=SOURCE,
            env=env,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        try:
            for _ in range(50):
                try:
                    status, body = fetch(f'http://127.0.0.1:{port}/?case=wrong')
                    break
                except OSError:
                    if server.poll() is not None:
                        raise AssertionError('PHP fixture server exited unexpectedly')
                    time.sleep(0.1)
            else:
                raise AssertionError('PHP fixture server did not start')
            assert (status, body) == (422, 'HOST_REJECTED'), (status, body)
            assert not (root / 'storage' / 'issuer-called').exists(), 'Vendor called before rejecting hostname'
            assert not (root / 'storage' / 'install-state.json').exists(), 'Rejected hostname wrote completion state'
            assert not (root / 'config' / 'license.php').exists(), 'Rejected hostname wrote license config'

            status, body = fetch(f'http://127.0.0.1:{port}/?case=match')
            assert (status, body) == (409, 'ISSUER_DENIED'), (status, body)
            assert (root / 'storage' / 'issuer-called').exists(), 'Matching hostname never reached issuer'
            assert not (root / 'storage' / 'install-state.json').exists(), 'Rejected license wrote install marker'
            print('Passed direct HTTP installation service hostname and issuer-boundary checks.')
        finally:
            server.terminate()
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=5)


if __name__ == '__main__':
    run()
