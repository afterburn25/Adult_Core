# Adult_Core — Agent / new-session handoff

This file is the **first document to read** for any new developer or AI session.

## Goal and non-negotiable decisions

- Build **self-hosted** adult membership website software, **not SaaS**.
- **One repository and universal browser-based installer**, with **Lite** selected for compatible shared hosting and **Pro** only after verifying the VPS-specific capabilities and background agent.
- Pro is **not** inferred from server name/IP or a shell function; if Pro checks fail, eligible installs fall back to Lite.
- Third-party modules declare compatible editions, core versions, dependencies, and required capabilities; incompatible modules must be refused.
- Installation assets live under top-level `install/`, **outside** the document root, and the operator **must manually delete the entire directory after a successful installation**. No frontend or admin access before completion **and** deletion.
- The website's document root must be `public/`. No files from `config/`, `src/`, `install/`, or `storage/` should be reachable directly by the web server.

## Read in this order

1. `README.md` — setup and actual completed features.
2. `docs/SESSION_HANDOFF.md` — current implementation state and immediate priorities.
3. `docs/ROADMAP.md` — milestones and acceptance criteria.
4. `docs/DECISIONS.md` — architectural rationale.
5. `docs/INSTALLATION_LIFECYCLE.md` — security and state-machine rules.
6. `docs/CHANGELOG.md` — dated implementation history.
7. `docs/PACKAGES.md` — signed extension manifest, offline signing and packaging safeguards.
8. `docs/architecture.md`, `docs/hosting.md` — component and hosting details.

## Before editing

- Inspect code and run `php tests/run.php`.
- Do not claim an installer, payments, accounts, or a live site works until implemented and tested.
- Do not expose an HTTP endpoint, CLI shortcut, or button that falsely marks installation complete. Installation completion must follow successful migrations, verified admin setup, and persisted edition config.
- Never bypass the `InstallationGate` for public/admin routes or for new entry points.
- Maintain compatibility with PHP 8.3+ and constrained shared hosting in Lite; no shell/Composer/root access required for a future prebuilt Lite release.
- Do not claim to have pushed to GitHub unless the remote reports success.

## Before completing a change

1. Update `docs/CHANGELOG.md` with what changed.
2. Update `docs/ROADMAP.md` as milestones progress.
3. Update `docs/SESSION_HANDOFF.md` with implemented state, tests run, limitations and next task.
4. Update `README.md` and feature/hosting instructions if behavior changed.
5. Run `php tests/run.php`, `php -l` on all PHP files, and any newly introduced integration checks.
6. Note security-sensitive decisions in `docs/DECISIONS.md`.

These are **repository conventions** for continuity across sessions, not an automatic logging/auditing system. Future installer steps will need redacted runtime audit logs as well.

## Current implementation update (M4 partial, 2026-11-20)

- Installer input form, MySQL schema/admin bootstrap, HMAC-signed v2 completion marker and private config files are implemented in `install/index.php`, `src/Installer/*` and `src/Database/Schema.php`.
- Unit/HTTP tests are available; `tests/mysql_install.php` needs a **disposable** MySQL database and `pdo_mysql`. `.github/workflows/php-tests.yml` provisions MySQL in CI. Local container lacks MySQL/PDO MySQL; **do not claim full DB integration pass locally**.
- M3 implements `/admin/login`, a read-only authenticated `/admin` dashboard and POST `/admin/logout`. MySQL-backed login and Apache/cPanel behavior remain **unverified locally**. The public `/` remains an intentional 503 placeholder; do not launch real members or adult content.
- `docs/SECURITY.md` describes admin sessions, revocation, CSRF and known gaps. `tests/mysql_http_auth.py` adds opt-in disposable real-MySQL HTTP validation; run before claiming real login works.
- Never hand-write schema-v1 markers; only schema-v2 signed markers created after DB bootstrap count as complete.

- M4 adds `src/Package/PackageVerifier.php`, `PackageStore.php`, `scripts/build-signed-package.php`, `/admin/packages`, and `docs/PACKAGES.md`. Only signed **data-only** ZIPs are accepted. Signer private keys must stay OUTSIDE the repository; public key allowlist is `config/trusted-publishers/<publisher>.pem`. No PHP/JS package execution, live theme rendering, updates, or uninstall yet.
- Run `php tests/package_tests.php` in addition to the other local tests. On shared hosting, the package installer needs `PharData` ZIP support, but the Lite core itself does not.

## Latest developer snapshot 0.5.0-dev

Read `docs/ROADMAP.md` and `docs/SESSION_HANDOFF.md` first. New theme CSS, membership draft schema/authorization, generic event inbox, Pro job envelope and source ZIP builder are incomplete scaffolds. Verify real MySQL before enabling migration and do not infer that payments, consent workflow, streaming or automatic updates are production-ready. Run `php tests/advanced_tests.php` in addition to existing suites, and record any GitHub commit/hash that was actually verified.

## GitHub CI verification — 2026-11-20

GitHub Actions run [44707148841](https://github.com/afterburn25/Adult_Core/actions/runs/44707148841) passed: PHP lint, 62 core tests, 29 package tests, 8 advanced foundation tests, 23 HTTP smoke checks, a real disposable MySQL installation test, and a real MySQL-backed administrator login/logout integration test. This verification runs on GitHub Ubuntu/MySQL; Apache/cPanel, public release packaging, adult compliance workflows, real billing, member media authorization, and Pro streaming remain unverified/incomplete.
