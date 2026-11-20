"""HTTP-level install access-gate smoke test using only Python's standard library.

Run: python3 tests/http_smoke.py

Creates an isolated temporary COPY of the repository; never writes an
installation marker into the developer's real source directory.
"""
import json
import http.cookiejar
import re
import pathlib
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
import urllib.parse

SOURCE = pathlib.Path(__file__).resolve().parents[1]


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, msg, headers, new_url):
        return None


def run():
    with tempfile.TemporaryDirectory(prefix="adult-core-smoke-") as temporary:
        root = pathlib.Path(temporary) / "Adult_Core"
        shutil.copytree(SOURCE, root)

        with socket.socket() as sock:
            sock.bind(("127.0.0.1", 0))
            port = sock.getsockname()[1]
        server = subprocess.Popen(
            ["php", "-S", f"127.0.0.1:{port}", "-t", "public", "public/router.php"],
            cwd=root,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
        )
        opener = urllib.request.build_opener(NoRedirect, urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        checks = 0

        def request(path, form=None):
            payload = urllib.parse.urlencode(form).encode() if form is not None else None
            try:
                response = opener.open(f"http://127.0.0.1:{port}{path}", data=payload, timeout=3)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                return response.status, response.read().decode("utf-8", errors="replace")

        def assert_http(label, route, expected_code, expected_text=None):
            nonlocal checks
            code, content = request(route)
            if code != expected_code or (expected_text is not None and expected_text not in content):
                raise AssertionError(f"{label}: expected {expected_code} and {expected_text!r}; got {code}")
            checks += 1

        try:
            for attempt in range(40):
                try:
                    request("/")
                    break
                except (ConnectionError, OSError):
                    time.sleep(0.1)
            else:
                raise RuntimeError("PHP development server did not start")

            assert_http("new site redirected", "/", 302)
            assert_http("new admin redirected", "/admin", 302)
            assert_http("provider endpoint cannot bypass installer", "/webhooks/verification", 302)
            assert_http("installer wizard accessible", "/install/", 200, "Install Adult Core")
            code, content = request("/install/", {"csrf": "incorrect"})
            if code != 200 or "security token invalid" not in content:
                raise AssertionError("invalid CSRF was not blocked")
            checks += 1

            # Create synthetic fixture using trusted test helper, never manually
            # forging an unsigned state or writing to the actual repository.
            state = root / "storage" / "install-state.json"
            (root / "config" / "app-key.php").write_text("<?php return '" + "a" * 64 + "';", encoding="utf-8")
            (root / "config" / "database.php").write_text("<?php return ['driver'=>'mysql','database'=>'test','username'=>'test'];", encoding="utf-8")
            subprocess.check_call([
                "php", "-r",
                "require 'src/Support/Autoload.php'; file_put_contents('storage/install-state.json', "
                "\\AdultCore\\Installer\\InstallationState::encode('lite', str_repeat('a', 64)));",
            ], cwd=root)
            assert_http("site blocked until cleanup", "/", 503, "Installation cleanup required")
            assert_http("admin blocked until cleanup", "/admin", 503, "Installation cleanup required")
            assert_http("packages blocked until cleanup", "/admin/packages", 503, "Installation cleanup required")
            assert_http("installer blocked after completion", "/install/", 503, "Installation cleanup required")

            # Signed fixture for a test-only authorized license. Production
            # installation must get the initial verdict from Cajun Web Pro.
            (root / "config" / "license.php").write_text(
                "<?php return ['license_key'=>'ABCD-EF12-3456-7890',"
                "'domain'=>'127.0.0.1','sku'=>'SOFTWARE-ADULT-CORE'];", encoding="utf-8"
            )
            subprocess.check_call([
                'php','-r',
                "require 'src/Support/Autoload.php'; "
                "file_put_contents('storage/license-cache.json', "
                "\\AdultCore\\Licensing\\LicenseState::encode('ABCD-EF12-3456-7890', "
                "'127.0.0.1', str_repeat('a', 64), 'valid', time(), time()));",
            ], cwd=root)
            shutil.rmtree(root / "install")
            # Removing a license must not unlock a completed installation.
            license_path = root / "config" / "license.php"
            license_private = license_path.read_text(encoding="utf-8")
            license_path.unlink()
            assert_http("unlicensed public site locked", "/", 503, "license authorization required")
            assert_http("unlicensed admin dashboard locked", "/admin", 503, "license authorization required")
            assert_http("admin login remains reachable to repair license", "/admin/login", 200, "Sign in")
            assert_http("license repair requires admin login", "/admin/license", 302)
            license_path.write_text(license_private, encoding="utf-8")
            # The public membership runtime remains deliberately disabled,
            # while the newly implemented administrator login is accessible.
            assert_http("public runtime stays unavailable", "/", 503, "Temporarily unavailable")
            assert_http("public page holding route", "/p/about", 503, "Temporarily unavailable")
            assert_http("member join refuses absent database", "/join", 503, "Temporarily unavailable")
            assert_http("member recovery refuses absent database", "/members/recover", 503, "Temporarily unavailable")
            assert_http("members portal refuses absent database", "/members", 503, "Temporarily unavailable")
            assert_http("new admin editor route requires login", "/admin/pages", 302)
            assert_http("design studio requires login", "/admin/design", 302)
            assert_http("private design image requires login", "/admin/design/asset/12", 302)
            assert_http("public design asset denied without schema", "/design/asset/12", 503)
            assert_http("moderation requires admin login", "/admin/moderation", 302)
            assert_http("unauthenticated admin redirects", "/admin", 302)
            assert_http("unauthenticated package manager redirects", "/admin/packages", 302)
            assert_http("administrator login reachable", "/admin/login", 200, "Sign in")
            assert_http("unknown admin route requires login", "/admin/private", 302)
            assert_http("GET logout forbidden", "/admin/logout", 405, "Method not allowed")
            assert_http("removed installer inaccessible", "/install/", 404, "Installer removed")
            assert_http("disabled verification callback GET unavailable", "/webhooks/verification", 405)
            code, body = request("/admin/login", {"email": "owner@example.test", "password": "anything", "csrf": "invalid"})
            if code != 403 or "Invalid security token" not in body:
                raise AssertionError("Admin login POST accepted bad CSRF token")
            checks += 1
            code, body = request("/admin/logout", {"csrf": "invalid"})
            if code != 403 or "Invalid security token" not in body:
                raise AssertionError("Admin logout POST accepted bad CSRF token")
            checks += 1
            # With the fixture-only placeholder DB config, credentials must
            # never create an authenticated session or reveal DB diagnostics.
            code, body = request("/admin/login")
            match = re.search(r'name="csrf" value="([0-9a-f]{64})"', body)
            if code != 200 or not match:
                raise AssertionError("Administrator CSRF input missing")
            checks += 1
            code, body = request("/admin/login", {"email":"owner@example.test", "password":"a-safe-test-password", "csrf":match.group(1)})
            if code != 401 or "temporarily unavailable" not in body or "PDO" in body:
                raise AssertionError("Database failures did not fail closed at admin login")
            checks += 1
            assert_http("failed login never grants admin access", "/admin", 302)

            state.unlink()
            assert_http("early folder deletion blocks site", "/", 503, "Installer missing")
            assert_http("early folder deletion blocks admin", "/admin", 503, "Installer missing")
            assert_http("early folder deletion blocks packages", "/admin/packages", 503, "Installer missing")

            print(f"Passed {checks} HTTP smoke checks.")
        finally:
            server.terminate()
            try:
                server.wait(timeout=3)
            except subprocess.TimeoutExpired:
                server.kill()
                server.wait(timeout=3)


if __name__ == "__main__":
    run()
