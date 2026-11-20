# Current handoff — 0.8.4-dev

`afterburn25/Adult_Core`: self-hosted Lite/shared + Pro/verified VPS. Not SaaS. This increment builds on 0.8.3-dev email ownership. **MISSING:** certified verification provider, performer consent release, production content uploads, payments, video worker, admin MFA, full restore/updater, cPanel audit.

**New:** `src/Member/{PasswordRecovery,PasswordRecoverySchema}.php`, `/members/recover`, `/members/recover/reset`, `EmailDelivery::sendPasswordReset`, per-user `session_generation` in `ac_member_password_resets`, enforced by `MemberController::member()` AND `PrivateMediaController`. Session generation invalidated on password reset; all pending accounts remain pending. `SiteAdminController::setup` migrates schema. Fixture and disposable MySQL/HTTP tests cover expiry, rate limits, replay, neutral responses, password hash changes, stale sessions. Read `docs/PASSWORD_RECOVERY.md`.

**Deployment:** back up DB; apply `/admin/setup` additive migration on a disposable test site before member routes work. Keep private `config/email.php` outside Git; mail() delivery remains unverified. Required root `install/` folder deletion gate unchanged. No direct public storage. All public adult uploads remain disabled.

**Next:** verify exact GitHub commit CI; test real Apache/cPanel and mail service on approved shared host; implement independent identity-provider integration, administrative MFA and trustworthy consent record review, then checkout and Pro services. Do not label 1.0 ready.

---

# Latest handoff — 0.8.3-dev (2026-11-20)

**Codebase:** `afterburn25/Adult_Core` (independently installed Lite shared hosting / Pro provisioned VPS, not SaaS). **Development only: NOT 1.0.**

- Member email ownership: `src/Member/{MemberEmailSchema,EmailConfirmations,EmailDelivery}.php`, opt-in `config/email.example.php`, browser `/members/email` and `/members/email/confirm`. Form uses GET preview and CSRF-protected POST, SHA-256 token hashes, 30m TTL, 5m resend cooldown, replays rejected. Registration stays pending, neither age nor identity verified. Mail delivery is optional and untested on real hosting.
- Additive migration `m9_member_email_v1` runs under `/admin/setup` after a backup; `Entitlements::canView` now requires email confirmation for members. Existing users without proof remain denied.
- Tests: local PHP/HTTP, `tests/mysql_install.php` email replay/expiry/cooldown, `tests/mysql_http_auth.py` browser confirmation using disposable **test-only** CLI token issuer. Check CI after the GitHub publish and record run result.
- Unchanged non-negotiables: remove `install/` manually to unlock site; protect public web root; signed add-on Lite/Pro compatibility; verification provider disabled by default; no adult content approval/publication; no payment or Pro streaming; `ReleaseReadiness` continues blocking 1.0.
- Next: replace generic verification HMAC scaffold with contracted provider adapter and verified subject binding, secure performer evidence retention and release procedures, admin MFA/recovery, payment onboarding, Pro worker, signed restores/updates and real cPanel/VPS certification.
- Read `docs/EMAIL_CONFIRMATION.md`, `docs/VERIFICATION.md`, `docs/RELEASE_GATES.md`, `docs/ROADMAP.md`, `AGENTS.md` and preceding handoffs below.

---

# Latest handoff — 0.8.2-dev (2026-11-20)

Worktree target: `afterburn25/Adult_Core`, self-hosted Lite/Pro, NOT SaaS. This build must **NOT** be labeled 1.0 or used with real adult media/payments.

Read `docs/VERIFICATION.md`, `docs/BACKUPS_AND_UPDATES.md`, `docs/MODERATION.md`, `docs/RELEASE_GATES.md` before extending these features.

- Added verification webhook scaffold disabled by default (`config/verification.example.php`); signatures use 32+ byte secret, five-minute time window, 24h event recency, idempotent provider-event receipt, same-subject monotonic ordering. `VerificationSchema` must be applied at `/admin/setup`. An actual provider-specific signed adapter and provider approval are NOT present.
- A signed third-party member-age receipt creates/revokes age records but never activates pending members; performer receipts reset status to pending and quarantine formerly published content. No media may be approved/published. `expected_performers=0` denies access when consent is required; pending roster can be declared from admin moderation (1–20 unique records), each still needs independent verification and legal review.
- Added limited private AES-GCM snapshot codec, CLI-only small-database export and signed release manifest verifier. No media backup, full restore, updater or rollback yet.
- Tests: `php tests/run.php`, `php tests/package_tests.php`, `php tests/advanced_tests.php`, `php tests/site_member_tests.php`, `php tests/pro_release_tests.php`, `php tests/moderation_tests.php`, `php tests/verification_tests.php`, `php tests/release_security_tests.php`, `python3 tests/http_smoke.py`; `tests/mysql_install.php` and `tests/mysql_http_auth.py` run only with **disposable** MySQL in GitHub CI. Record actual CI status after publication.
- Non-negotiable: install folder must be manually deleted before any website/admin access; no trusted code autoexec; add-on edition compatibility is fail-closed; do not change `ReleaseReadiness::productionReady()` to bypass unresolved gates.
- NEXT: independently audited provider adapter, binding performer consent to media, member email confirmation and MFA, legal review, real private uploads with safe moderation, approved billing, Pro worker, complete encrypted restore and versioned updates, actual cPanel and VPS tests.

---

# Latest handoff — 0.8.1-dev (2026-11-20)

Repository `afterburn25/Adult_Core` is self-hosted Lite/Pro, NOT SaaS. This continuation adds `/admin/moderation`, `/members/report`, `src/Moderation/{ModerationSchema,ModerationRepository}.php`, a reasoned audit trail, content quarantine/rejection, default-deny open-report entitlement check, and tests. The only supported next step for database upgrades is `/admin/setup` **after a backup**. Re-running it installs the new moderation schema.

**Very important:** no publish/approve endpoint, media upload UI, external age verification, complete performer consent review, adult payment processor, real Pro streaming or signed update/backup exists. Do not launch paid/adult content. `ac_moderation_actions` must be dropped before `ac_content` in disposable test cleanup. `tests/mysql_install.php` and `tests/mysql_http_auth.py` now exercise moderation/reporting in GitHub CI; local PHP environment lacks `pdo_mysql`. Check the latest GitHub CI result before saying these pass.

Next priorities: implement external age/identity and performer-consent verification interfaces; introduce safe evidence capture outside ordinary report notes; email verification and rate limiting for abuse, appeal and takedown processes; member and administrator MFA; then consider restricted-media publishing only after verification and security review. Continue to preserve installation folder cleanup gate and Lite/Pro compatibility. See `docs/MODERATION.md`, `docs/ROADMAP.md`, `docs/RELEASE_GATES.md` and `AGENTS.md`.

---

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

## Verified CI — 0.8.1-dev moderation (2026-11-20)

GitHub Actions run 44733014757 passed PHP lint, moderation input validation, disposable MySQL installation and authenticated browser tests. Real Apache/cPanel hosting, identity/performer consent verification, payments and production streaming remain unverified.
