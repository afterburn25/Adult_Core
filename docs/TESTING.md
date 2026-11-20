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
