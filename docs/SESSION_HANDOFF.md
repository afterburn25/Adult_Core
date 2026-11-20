# CURRENT HANDOFF — v0.8.0-dev / 2026-11-20

**Repository:** `afterburn25/Adult_Core`. **Target:** independently installed Lite (shared hosting) and Pro (verified VPS). **NEVER SaaS.**

The latest changes are from the 0.6–1.0 development request, but **0.9, Pro runtime and production 1.0 are NOT complete**.

## Current additions

- `src/Website/{SiteSchema,PageRepository,PublicSite}.php`, `src/Http/SiteAdminController.php` and admin routes: additive schema, safe published plain-text pages, homepage, signed CSS styling, edit/publish tools.
- `src/Member/{MemberSchema,MemberAccounts,MemberSession}.php` and `src/Http/MemberController.php`: registration/login with rate limiting, separate member sessions and **pending** user status. `/join` is not adult age verification. No activation or paid content routes.
- Repaired `src/Membership/{MembershipSchema,Entitlements}.php` to support additive content/tier tables and default-deny age/moderation/consent checks; `src/Media/PrivateMedia.php`, `/media/{id}` authorization. No public adult media uploads or complete performer verification. Physical assets must stay outside public root.
- `src/Pro/JobInbox.php` safely verifies/stores deduplicated signed job messages, NO FFmpeg execution; `src/Support/ReleaseReadiness.php`, `scripts/release-check.php` intentionally block 1.0 claim.
- Tests: `tests/site_member_tests.php`, `tests/pro_release_tests.php`, expanded `tests/mysql_install.php`, `tests/mysql_http_auth.py`, and `tests/http_smoke.py` plus GitHub CI workflow.
- **Install gate remains mandatory**: valid signed completion state AND physical deletion of top-level `install/` before any site/admin route. All production services remain unverified.

## Tests and limitations

Run `php tests/run.php`, `php tests/package_tests.php`, `php tests/advanced_tests.php`, `php tests/site_member_tests.php`, `php tests/pro_release_tests.php`, `python3 tests/http_smoke.py`, and syntax lint. For real DB tests, use CI or an explicitly disposable MySQL instance; local PHP environment does not have pdo_mysql. CI verification of this snapshot must be recorded **after** run results are checked.

**Next:** Verify new MySQL/browser tests and cPanel, develop full protected upload/moderation/consent workflow, provider-specific age verification and payments, then Pro worker, backup/update/restore and security certification. Never imply the release is ready for customer deployment.

---

## Historical session notes

# Current session handoff — read before editing

**Date:** 2026-11-20  
**Target:** `afterburn25/Adult_Core`  
**Distribution:** self-hosted Lite on shared hosting and Pro on provisioned VPS; **not SaaS**.  
**Status:** M4 partially implemented: signed data-only ZIP package management + admin interface. Real MySQL/cPanel verification remains pending. Nothing has been pushed to GitHub from this session.

## Read first

`AGENTS.md` → `README.md` → `docs/ROADMAP.md` → `docs/DECISIONS.md` → `docs/SECURITY.md` → `docs/TESTING.md`.

## Non-negotiable product decisions

1. One universal browser installer. **Lite** requires PHP/MySQL plus safe hosting checks. **Pro** requires an authenticated provisioned Pro agent/capability probe; do not infer VPS via hostname or `exec()` availability.
2. One common core; add-ons declare edition, core-version, dependencies and capabilities and must be refused when incompatible. M4 now accepts signed **data-only** ZIP add-ons. Full runtime PHP plugins, upgrade lifecycle and theme renderer are not implemented.
3. Installation assets live in deletable **top-level `install/`**, outside the document root. The website and admin stay inaccessible until a valid signed completion marker exists AND the whole folder has been manually deleted.
4. The **only** public document root is `public/`. Keep credentials and private storage above the web root. Public content/memberships remain disabled until compliance and security controls are actually developed.
5. Update roadmap, handoff, changelog and tests after each development session. Never claim a GitHub push without remote confirmation.

## Implemented through M4 (partial)

- M1/M1.1: capability scanning, edition manifests, module compatibility checks and mandatory cleanup state machine.
- M2: browser install form, CSRF/session/rate limiting, strict database validation, initial schema and first `ac_users` administrator, generated private config and signed install marker. Local PHP & HTTP tests pass; actual MySQL install not locally verified.
- **M3:** `src/Database/Connection.php` opens only installer-generated private MySQL config. `src/Auth/AdminAccounts.php` verifies hashed credentials and checks admin role on each request. `src/Auth/RolePolicy.php` denies unknown permissions/roles by default.
- `src/Auth/AdminSession.php` creates private file sessions with 30-minute idle and 12-hour absolute expiry, login session rotation and CSRF token rotation, POST-only logout and cookie removal.
- `src/Auth/LoginRateLimiter.php` locks hashed-IP and hashed-email counters; admin login responses do not disclose whether an email exists. Requires HTTPS outside loopback.
- `src/Http/AdminController.php` handles `/admin/login`, `/admin` (read-only overview), and `/admin/logout`. The front controller applies `InstallationGate` **before** dispatching. GET `/` still serves the intentional development 503.
- `tests/run.php` now covers role/session/throttle; `tests/http_smoke.py` covers install gate and unauthenticated admin/CSRF; opt-in `tests/mysql_install.php` tests real MySQL auth/role revocation. New opt-in `tests/mysql_http_auth.py` covers real HTTP login/logout, session cookie, HTML-escaped site name, via `tests/mysql_http_fixture.php`. GitHub Actions jobs configured but not executed locally.

## Tests last verified here

```text
php tests/run.php                → 62 checks passed
python3 tests/http_smoke.py       → 20 HTTP checks passed
find src install public tests -type f -name '*.php' ... php -l  → all PHP files valid
```

**Local limitation:** PHP 8.4 CLI exists, but `pdo_mysql` and a MySQL server are unavailable. Do NOT call M2/M3 real database or HTTP login integration verified until tests pass on disposable MySQL/cPanel. There is no production login claim.

## Immediate next engineering work

1. Run `php tests/mysql_install.php` and `python3 tests/mysql_http_auth.py` with explicit `AC_TEST_DB_CONFIRM` and disposable MySQL credentials (see `docs/TESTING.md`). Fix any real MySQL/session defects and test on Apache/cPanel with safe web root.
2. Add administrator MFA, account recovery, security logging/alerting and hardened account management after integration. Consider first-admin `owner` migration separately; initial role is `admin` by design.
3. Implement signed add-on uploads/lifecycle (M4) with archive traversal/symlink controls, manifests, dependency/edition enforcement and signature checks. Do not execute untrusted PHP from manifests.
4. Only then implement private galleries, member auth, age and consent workflows, payment integrations and Pro features under the roadmap.

## M4 partial — signed extensions and themes (2026-11-20)

- Implemented `src/Package/PackageVerifier.php`: `PharData` ZIP read without ext-zip, max 8 MiB compressed/16 MiB uncompressed, no symlinks or path traversal, allowlisted static asset types, signed manifest + SHA-256 file hashes with operator-provisioned publisher PEM public keys. No signing private keys are in repository.
- Implemented `src/Package/PackageStore.php`: private staging under `storage/packages/`, serialized and atomic registry updates, default-disabled installed packages, module enable/disable, theme selection, dependency guards, Lite/Pro and current-capability checks, and runtime fail-closed `isRunnable`.
- Implemented `/admin/packages` (GET/POST): admin-authenticated upload/enable/disable UI with existing HTTPS, session, CSRF and installation gates. **Not locally tested with real MySQL authentication and file upload**; requires disposable MySQL/cPanel validation.
- Added `scripts/build-signed-package.php`, `examples/gallery-addon`, `examples/basic-theme`, and `docs/PACKAGES.md`. The offline builder was exercised with a temporary private RSA key; the resulting ZIP verified successfully.
- **No executable PHP/JS plugin support, theme rendering, updates, uninstall, backup/rollback, or safe public content features.** Admin package enablement only updates private registry state. The built-in example manifests in `modules/` remain metadata-only. Never claim functional gallery or video modules.
- Local tests: `php tests/run.php` (62 tests), `php tests/package_tests.php` (29 checks), `python3 tests/http_smoke.py` (23 checks), plus PHP syntax lint.
- Remote `afterburn25/Adult_Core` not updated from this environment; publish local release ZIP manually or after a verified GitHub write connection. Do not claim a push without success.

## Next tasks

1. Run opt-in MySQL installer and admin HTTP tests on disposable database, and test admin `/admin/packages` with actual authenticated cookies and file upload under real Apache/cPanel HTTPS.
2. Extend M4 with versioned package upgrade/rollback/restore, safe module runtime API, public theme renderer, trust store administration and security audit. Do **not** auto-execute user-uploaded PHP.
3. Add recovery/MFA, then M5 membership/content/consent controls; keep `/` disabled until compliance and protected media authorization are implemented.
4. Maintain `AGENTS.md`, `docs/ROADMAP.md`, `docs/SESSION_HANDOFF.md`, `docs/DECISIONS.md`, `docs/CHANGELOG.md`, and `docs/SECURITY.md` each milestone.

## 0.5.0-dev continuation (2026-11-20)

- Added `src/Theme/ThemeAssets.php`, `/theme.css` (installation-gated; only selected signed CSS served), `src/Membership/{MembershipSchema,Entitlements}.php`, `src/Billing/WebhookInbox.php`, `src/Pro/JobEnvelope.php`, and `scripts/build-release.php`.
- The draft membership schema is **not wired into existing installation migrations**: do not apply to a production database until a safe forward migration, restart-safe journal, and disposable MySQL tests are written.
- The webhook verifier is illustrative HMAC only; payment processors require provider-specific signatures, replay windows, state reconciliation and approved accounts before handling real events. The inbox must not directly grant membership.
- The Pro envelope is a protocol proof-of-concept, not a worker. Replay detection, asset lookup, authorized input storage, FFmpeg isolation, and segment authorization all remain undone.
- `scripts/build-release.php` requires PHP ext-zip, may only be used as a developer source archive, and does not create an install-ready signed release.
- Run `php tests/run.php`, `php tests/package_tests.php`, `php tests/advanced_tests.php`, and `python3 tests/http_smoke.py`. Real MySQL/cPanel/Pro coverage remains pending.
- Publish each change to GitHub and record the verified commit hash; do not claim GitHub is synchronized unless all files are confirmed.

## GitHub CI verification — 2026-11-20

GitHub Actions run [44707148841](https://github.com/afterburn25/Adult_Core/actions/runs/44707148841) passed: PHP lint, 62 core tests, 29 package tests, 8 advanced foundation tests, 23 HTTP smoke checks, a real disposable MySQL installation test, and a real MySQL-backed administrator login/logout integration test. This verification runs on GitHub Ubuntu/MySQL; Apache/cPanel, public release packaging, adult compliance workflows, real billing, member media authorization, and Pro streaming remain unverified/incomplete.


## Verified GitHub CI for 0.8.0-dev foundation

- Source commit: [`4fec09071fda8ea517fe84b5f44a4bb348986b1c`](https://github.com/afterburn25/Adult_Core/commit/4fec09071fda8ea517fe84b5f44a4bb348986b1c).
- GitHub Actions: [run 44714670111](https://github.com/afterburn25/Adult_Core/actions/runs/44714670111) **passed** on PHP 8.3 + disposable MySQL. Includes unit checks, signed-package tests, Pro/release tests, HTTP smoke tests, MySQL installer and MySQL-backed admin/page/member browser tests.
- Real Apache/cPanel deployment, actual age/consent/payment integrations, video streaming, and production release gates **remain unverified or unimplemented**.
