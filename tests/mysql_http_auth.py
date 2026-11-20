"""Opt-in disposable MySQL + real HTTP session integration test.

Run only with the AC_TEST_DB_* vars in docs/TESTING.md, against a NEW disposable
MySQL database. Sets up a temporary COPY of source; does not change the working
repository. The fixture deletes only its ac_ tables after the test.
"""
import http.cookiejar
import os
import pathlib
import re
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

SOURCE = pathlib.Path(__file__).resolve().parents[1]


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, new_url):
        return None


def main():
    if os.environ.get('AC_TEST_DB_CONFIRM') != 'YES_THIS_IS_A_DISPOSABLE_DATABASE':
        raise SystemExit('Refusing real MySQL HTTP test: disposable DB confirmation missing.')
    with tempfile.TemporaryDirectory(prefix='adult-core-mysql-http-') as tmp:
        root = pathlib.Path(tmp) / 'Adult_Core'
        shutil.copytree(SOURCE, root)
        php_fixture = ['php', 'tests/mysql_http_fixture.php']
        server = None
        setup_started = False
        try:
            setup_started = True
            subprocess.check_call(php_fixture + ['setup'], cwd=root)
            with socket.socket() as sock:
                sock.bind(('127.0.0.1', 0))
                port = sock.getsockname()[1]
            server = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', 'public'],
                                      cwd=root, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            opener = urllib.request.build_opener(NoRedirect, urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            checks = 0

            def req(path, data=None):
                payload = urllib.parse.urlencode(data).encode() if data is not None else None
                try:
                    resp = opener.open(f'http://127.0.0.1:{port}{path}', data=payload, timeout=4)
                except urllib.error.HTTPError as err:
                    resp = err
                with resp:
                    return resp.status, resp.read().decode('utf-8', errors='replace')

            for _ in range(50):
                try:
                    req('/admin/login')
                    break
                except OSError:
                    time.sleep(0.1)
            else:
                raise RuntimeError('PHP development server failed to start')

            def ensure(label, condition):
                nonlocal checks
                if not condition:
                    raise AssertionError(label)
                checks += 1

            code, body = req('/admin')
            ensure('Anonymous admin access must redirect', code == 302)
            code, body = req('/admin/login')
            ensure('Real login form loads', code == 200 and 'Sign in' in body)
            csrf_match = re.search(r'name="csrf" value="([a-f0-9]{64})"', body)
            ensure('Login CSRF hidden field', csrf_match is not None)
            csrf = csrf_match.group(1)
            code, body = req('/admin/login', {'email': 'owner@example.test', 'password': 'bad-password', 'csrf': csrf})
            ensure('Incorrect password rejected', code == 401 and 'Invalid email or password' in body)
            code, body = req('/admin')
            ensure('Failed login cannot view dashboard', code == 302)
            code, body = req('/admin/login', {'email': 'owner@example.test', 'password': 'strong-example-admin-password', 'csrf': csrf})
            ensure('Correct credentials redirect to dashboard', code == 303)
            code, body = req('/admin')
            ensure('Dashboard loads authenticated', code == 200 and 'HTTP Fixture &lt;script&gt;alert(1)&lt;/script&gt;' in body and '<script>alert(1)</script>' not in body)
            ensure('Edition visible', 'LITE' in body)
            code, body = req('/admin/logout')
            ensure('GET cannot sign out', code == 405)
            csrf_match = re.search(r'name="csrf" value="([a-f0-9]{64})"', body)
            # GET /admin/logout never includes the dashboard form, so fetch fresh token.
            _, dashboard = req('/admin')
            csrf_match = re.search(r'name="csrf" value="([a-f0-9]{64})"', dashboard)
            ensure('Logout CSRF hidden field', csrf_match is not None)
            code, body = req('/admin/logout', {'csrf': 'invalid'})
            ensure('Bad logout CSRF rejected', code == 403)
            code, body = req('/admin')
            ensure('Bad logout does not revoke session', code == 200)
            code, body = req('/admin/logout', {'csrf': csrf_match.group(1)})
            ensure('Valid logout redirects', code == 303)
            code, body = req('/admin')
            ensure('Logged-out session denied', code == 302)
            print(f'Passed {checks} real MySQL HTTP authentication checks.')
        finally:
            if server is not None:
                server.terminate()
                try:
                    server.wait(timeout=3)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait(timeout=3)
            if setup_started:
                subprocess.check_call(php_fixture + ['cleanup'], cwd=root)


if __name__ == '__main__':
    main()
