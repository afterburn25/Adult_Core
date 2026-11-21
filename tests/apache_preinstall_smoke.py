#!/usr/bin/env python3
"""Disposable Apache 2.4 / PHP 8.3 HTTP preinstall acceptance.

Uses Docker's official PHP/Apache image with a temporary source-tree copy.
Does NOT use real licenses, payment services, customer databases or HTTPS,
and does NOT constitute cPanel or production hosting certification.
"""
import http.client
import os
import pathlib
import re
import secrets
import shutil
import subprocess
import tempfile
import time

SOURCE = pathlib.Path(__file__).resolve().parents[1]
CHECKS = 0


def check(label, value):
    global CHECKS
    if not value:
        raise AssertionError(label)
    CHECKS += 1


def run_command(args, timeout=180):
    return subprocess.run(args, check=True, capture_output=True, text=True,
                          timeout=timeout).stdout.strip()


def request(port, path):
    connection = http.client.HTTPConnection("127.0.0.1", port, timeout=6)
    try:
        connection.request("GET", path, headers={"Host": "localhost", "Connection": "close"})
        response = connection.getresponse()
        body = response.read(131072)
        return response.status, dict((k.lower(), v) for k, v in response.getheaders()), body
    finally:
        connection.close()


def main():
    with tempfile.TemporaryDirectory(prefix="adultcore-apache-disposable-") as directory:
        checkout = pathlib.Path(directory) / "Adult_Core"
        shutil.copytree(SOURCE, checkout, ignore=shutil.ignore_patterns(".git", "__pycache__"))
        # These are synthetic development fixtures and carry no customer secrets.
        for private in ("storage", "config"):
            (checkout / private).chmod(0o777)
        public = checkout / "public"
        asset_dir = public / "assets"
        # A misplaced script must be forbidden by Apache even when the file exists.
        (asset_dir / "unwanted.php").write_text("<?php echo 'UNEXPECTED-SCRIPT-EXECUTION';",
                                                 encoding="utf-8")
        (asset_dir / "index.php").write_text("<?php echo 'NESTED-SCRIPT-EXECUTION';",
                                              encoding="utf-8")
        name = "ac-apache-smoke-" + secrets.token_hex(6)
        container = None
        try:
            command = """
set -eu
sed -ri 's#DocumentRoot /var/www/html#DocumentRoot /srv/adult-core/public#g' /etc/apache2/sites-available/000-default.conf
printf '<Directory /srv/adult-core/public>\n  Options -Indexes\n  AllowOverride All\n  Require all granted\n</Directory>\n' >/etc/apache2/conf-enabled/adult-core.conf
a2enmod rewrite >/dev/null
exec apache2-foreground
"""
            container = run_command([
                "docker", "run", "--rm", "-d", "--name", name,
                "-p", "127.0.0.1::80",
                "--mount", f"type=bind,src={checkout},dst=/srv/adult-core",
                "php:8.3-apache", "sh", "-c", command,
            ], timeout=240)
            port_text = run_command(["docker", "port", container, "80/tcp"])
            found = re.search(r"127\.0\.0\.1:(\d+)", port_text)
            check("Apache container binds to loopback only", found is not None)
            port = int(found.group(1))
            for _ in range(80):
                try:
                    status, headers, body = request(port, "/")
                    break
                except (ConnectionError, OSError):
                    time.sleep(0.25)
            else:
                logs = subprocess.run(["docker", "logs", container], capture_output=True,
                                      text=True, timeout=10).stderr[-2000:]
                raise AssertionError("Disposable Apache failed to serve HTTP: " + logs)
            check("Preinstall home is redirected by actual Apache", status == 302
                  and headers.get("location") == "/install/")
            status, headers, body = request(port, "/install/")
            check("Browser installer reachable through Apache rewrite", status == 200
                  and b"Install Adult Core" in body
                  and b"Cajun Web Pro license key selects Lite or Pro" in body)
            status, headers, body = request(port, "/assets/style.css")
            check("Allowlisted static CSS served directly", status == 200
                  and headers.get("content-type", "").startswith("text/css"))
            for path, expected in (
                ("/config/database.php", 403),
                ("/scripts/build-release.php", 403),
                ("/storage/license-cache.json", 302),
                ("/AGENTS.md", 302),
                ("/media/999", 302),
                ("/design.css", 302),
            ):
                status, _, body = request(port, path)
                check("Private/dynamic route denied or setup-gated " + path,
                      status == expected and b"UNEXPECTED-SCRIPT-EXECUTION" not in body)
            status, _, body = request(port, "/.htaccess")
            check("Apache never serves .htaccess", status in (403, 404))
            for path, marker in (("/assets/unwanted.php", b"UNEXPECTED-SCRIPT-EXECUTION"),
                                 ("/assets/index.php", b"NESTED-SCRIPT-EXECUTION"),
                                 ("/router.php", b"<?php")):
                status, _, body = request(port, path)
                check("Apache refuses misplaced executable " + path,
                      status == 403 and marker not in body)
            check("Temporary fixture never generated site credentials",
                  not (checkout / "config" / "license.php").exists()
                  and not (checkout / "storage" / "install-state.json").exists())
            print(f"Passed {CHECKS} disposable Apache preinstall/routing checks.")
        finally:
            if container:
                subprocess.run(["docker", "rm", "-f", container], capture_output=True,
                               text=True, timeout=30)


if __name__ == "__main__":
    main()
