## Backup-and-restore security and real MySQL fixture — 0.8.8-backup-dev

Run `php tests/backup_tests.php` for wrong-key/truncation/tamper/replay/path checks and `php tests/mysql_backup_restore.php` with the **same disposable-only MySQL environment** required by `tests/mysql_install.php` for DDL/rows/files round-trip. The test uses an explicit synthetic license and removes only disposable tables and fixture directories. Do not use customer data. Independent real Lite shared-host and Pro VPS recovery drills still required.


---

## Administrator MFA (0.8.7-mfa-dev)

Run `php tests/mfa_tests.php` for TOTP test vectors, clock windows and session controls. Disposable MySQL integration tests in `php tests/mysql_install.php` validate persisted encrypted MFA, replay, recovery and removal detection. Real PHP HTTP integration in `python3 tests/mysql_http_auth.py` exercises enrollment, passwords, challenge, CSRF, backup codes, replay and disabling. Both integration suites *refuse* any database without `AC_TEST_DB_CONFIRM=YES_THIS_IS_A_DISPOSABLE_DATABASE` and environment-provided credentials. No live licensing endpoint or merchant gateway is used.

## 0.8.2-dev additional checks

```sh
php tests/verification_tests.php
php tests/release_security_tests.php
```

These run without MySQL. With an explicitly disposable MySQL database, rerun `tests/mysql_install.php` and `tests/mysql_http_auth.py` to check event idempotence, revocation, incomplete performer coverage and roster creation. Real provider, cPanel, VPS and backup-restore tests are still missing.

# Adult Core tests

From the repository root using PHP 8.3+:

```sh
php tests/run.php
php tests/package_tests.php
python3 tests/http_smoke.py
find src install public tests -type f -name '*.php' -exec php -l {} \;
```

These run against temporary fixtures and do not require MySQL. The smoke test starts PHP's local development server against a temporary repository **copy**, exercises the install/cleanup gate, anonymous admin redirects, CSRF protection and failure-closed behavior, then removes the copy.

## Real database integration (opt-in ONLY)

Requires PDO MySQL, a running MySQL/MariaDB server and an **empty disposable database** belonging to you. The tests create/drop `ac_` tables and must never run against an existing installation, customer database or shared environment. To opt in:

```sh
export AC_TEST_DB_CONFIRM=YES_THIS_IS_A_DISPOSABLE_DATABASE
export AC_TEST_DB_HOST=127.0.0.1
export AC_TEST_DB_PORT=3306
export AC_TEST_DB_NAME=adultcore_disposable_test
export AC_TEST_DB_USER=dedicated_test_user
export AC_TEST_DB_PASSWORD='test-only-password'
php tests/mysql_install.php
python3 tests/mysql_http_auth.py
```

The first test covers installation schema, first admin hash, edition persistence, signed state, installer deletion, credential verification and immediate role revocation. The second performs a real HTTP login/logout session using a temporary source copy, a disposable MySQL database, and the PHP built-in server. It covers incorrect credentials, successful login, protected admin views, logout CSRF and HTML-escaped site names.

GitHub Actions configures a disposable MySQL service and runs both checks when code is published. This local development container **does not include `pdo_mysql` or MySQL**; passing the unit/smoke tests does not mean real installation or login works on cPanel. Test HTTPS, rewrites, filesystem permissions, cookie settings and failure recovery on real supported hosting before release.

## Data-only extension lifecycle tests

`php tests/package_tests.php` uses a disposable private key and PHP Phar-created ZIPs to cover signature and file-hash validation, malicious archive fields, mismatched editions, dependency activation/disable, corrupted installed files, theme selection and publisher key removal. No MySQL is needed. `docs/PACKAGES.md` explains the offline signing workflow. Authenticated HTTP upload on real MySQL and cPanel is not verified yet.
