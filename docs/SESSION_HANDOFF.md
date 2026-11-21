## Latest checkpoint — 0.8.9-backup-ops-dev (verified copy + maintenance)

`src/Backup/{BackupVerifier,BackupVault}.php`, `scripts/{verify-backup,ship-backup,backup-job}.php` and `tests/backup_vault_tests.php` extend v0.8.8 encrypted portable recovery. Existing archive format and fresh-empty-target restore semantics stay unchanged. Job must only run after operator actually pauses web/database writes, uploads and Pro workers; `storage/.backup-maintenance` blocks new site/admin HTTP, but not previously admitted PHP requests. Only recognizable older archives that successfully verify are removed after the latest private vault copy is verified. No cloud transfer, hosted cPanel/VPS drills, safe production automated quiescence, CWP real merchant sandbox, age/consent approvals or 1.0 certification is implied. Check GitHub MySQL integration CI before claiming a verified source. Read `docs/BACKUP_OPERATIONS.md`.

---

## Current implementation handoff — 0.8.8-backup-dev (portable encrypted disaster recovery)

`src/Backup/StreamArchive.php` is a versioned AES-256-GCM streaming record envelope with per-archive HKDF, monotonic sequence and authenticated end marker. `src/Backup/SiteBackup.php` strictly whitelists private paths, stores InnoDB schema/rows and private files, authenticates and stages all recoverable files before writing to an EMPTY new database, and leaves `storage/.backup-restore-pending` to lock HTTP on partial DB restore. `scripts/backup-site.php` and `scripts/restore-site.php` are CLI-only and accept a separate offline 32-byte key; target DB config is provided separately and must remain untouched. `install/` remains until manual removal after operator verification. Existing legacy `.acbak` is unchanged. CI must include `tests/backup_tests.php` and `tests/mysql_backup_restore.php` (disposable real MySQL). **Not production ready** until independent shared-host + Pro VPS restore drills, external backups/PITR and security acceptance. Read `docs/PORTABLE_BACKUP_RECOVERY.md`.


---

# Current handoff — Administrator MFA (0.8.7-mfa-dev)

Implementation extends the published 0.8.6.7 subscription HTTP-contract milestone in `afterburn25/Adult_Core`. New `src/Auth/{Totp,AdminMfa,AdminMfaSchema}.php` and `src/Http/MfaController.php` implement optional but enforcement-persistent admin MFA. Login checks `AdminMfa::enabledFor()` only after verifying password and starts a 5-minute challenge session if enabled; login never calls `authenticate()` before a valid second factor. `/admin/security` requires role permission, CSRF and password reauthentication. Rate-limited TOTP/recovery consumption runs in a MySQL row-lock transaction. `/admin/setup` migrates older installs; `Schema::create` creates MFA table for fresh installs. Recovery codes are displayed only once; private key is required to recover encrypted secrets. See `docs/ADMIN_MFA.md` for deployment controls. CI and exact GitHub result must be checked after publishing. Do not claim license billing, backups or production acceptance completed.

---

# 0.8.6.7 continuation — HTTP subscription contract simulation (2026-11-20)

Added `tests/cwp_subscription_contract.py` plus CLI-only fixture `tests/fixtures/cwp_subscription_client.php`, CI step and `docs/SUBSCRIPTION_ACTIVATION_TESTING.md`. Four separate CWP SKU products remain the sole source of edition/term; no new installer, local license minting or payment system. Covers initial paid-through activation, expiry, renewal recovery, confirmed revocation, wrong domain, vendor outages, outage grace expiration and verified domain move via actual PHP licensing classes over loopback HTTP. **Important:** CWP real products, prices, invoice settlement, recurring charges and issuance are still untested and unprovisioned. Do not call this end-to-end *live billing*. Admin warning: swapping a key does not cancel auto-renewal. Keep `install/` physical cleanup requirement; no public adult content or unverified payment activation. Run all CI and check published result before claiming GitHub verification.

## Latest milestone — 0.8.6.6-license-health-dev (2026-11-20)

One installer and the four existing exact Lite/Pro × once/month SKUs remain unchanged. `src/Licensing/LicenseManager.php` allows a privileged forced check while respecting the same signed vendor receipt and fail-closed domain/edition guard. `/admin/license` has two separate CSRF POST actions: `check` revalidates without revealing the key; `replace` keeps the existing same-edition restriction. Only `site.settings.manage` may POST; GET remains protected by dashboard access. Display the issuer's last response separately from effective outage-grace access. Tests: `tests/licensing_tests.php`, `tests/mysql_http_auth.py`, all established CI. Cajun Web Pro catalog pricing, publication, staging issuance and real subscription lifecycle remain unverified, owner-controlled follow-up; follow `docs/CWP_CATALOG_SETUP.md` and Cajun_Web #399. No production billing or adult-content release gates cleared.

## Current handoff — 0.8.6.5-upgrade-dev in-place Lite → Pro (2026-11-20)

**Repository:** `afterburn25/Adult_Core`, branch `main`. Added `src/Licensing/EditionUpgrade.php`, `scripts/recover-edition-upgrade.php`, admin `/admin/upgrade`, maintenance sentinel in `InstallationGate`, operator docs `docs/LITE_TO_PRO_UPGRADE.md`, unit and disposable-MySQL fault-injection tests. **One installer, no second database/software.** Existing signed Lite marker + DB must agree. Pro one-time/month SKU must validate for same domain, and a real Pro agent/capability check is required on production. Step-up password, CSRF, rate limit and explicit external backup acknowledgement protect the browser action. DB edition metadata and private vendor key/cache/HMAC marker change in place, retaining installed_at and all member/page/theme/media records. A private, MAC-authenticated snapshot covers only three metadata files. Crash sentinel blocks all HTTP requests; operator CLI `php scripts/recover-edition-upgrade.php --rollback` restores original Lite metadata if the upgrade is interrupted. If rollback fails, keep the gate locked and restore a separately tested external backup. **Local/disposable CI doubles do not establish real Pro agent, paid subscription or production certification.** Do not advertise video streaming as enabled. Verify entire CI on exact published GitHub commit, then document result.

## Session handoff — edition-bound monthly licensing (2026-11-20)

Version `0.8.6.4-license-dev`: **one installable software**, key-selects Lite/Pro and either one-time or monthly term via Cajun Web Pro's four separate product SKUs. Pro eligibility is independently enforced from verified host capabilities; cross-edition license replacement is blocked until safe migration is implemented. Paid static add-ons may declare signed `license_sku` and require a distinct vendor key before enabling; runtime eligibility rechecks signed entitlement caches. Lite admin displays disabled Pro-only features with **Pro Version Only** and an upgrade link to https://cajunwebpro.com (placeholder). No customer payment or live license issuance was performed. The earlier single SKU is retired for new installs; existing installations require a matching replacement key. Operator must register catalog offerings and test actual monthly billing externally. Details: `docs/CAJUN_WEB_PRO_LICENSING.md`. Release 1.0 still blocked by production compliance, payment, streaming and security gates.

# Handoff — Cajun Web Pro mandatory product licensing (0.8.6.3-license-dev)

- Inspected the **actual** `afterburn25/Cajun_Web` default branch: `site/api/validate.php`, `site/api/handlers/license.php`, `site/app/licenses/service.php`, `site/app/licenses/software_grants.php` and owner licensing documentation. Product-bound POST requires `license_key`, canonical `domain` and exact `product_id`; HTTP200 `authorized:false` is definitive denial, all non-200 replies are inconclusive. `version` is optional and is omitted until `SOFTWARE-ADULT-CORE` is registered and released. The target product was NOT found in source search.
- Added `src/Licensing/CajunWebClient.php`, `LicenseState.php`, `LicenseManager.php`. Browser installer now requires a key/domain, validates remotely before MySQL creation, writes owner-only `config/license.php` and a signed cache **before** the existing signed completion marker. `public/index.php` enforces runtime license before all regular routes, with hourly positive cache, 72h temporary-outage grace and immediate explicit-denial lock. `/admin/login`, `/admin/logout` and authenticated `/admin/license` remain available while locked; authenticated repair validates remotely again and stores state privately.
- Test doubles are **in-process only**: tests/licensing_tests.php, tests/mysql_install.php and tests/mysql_http_fixture.php simulate Cajun Web Pro without a real key or live charge. GitHub CI must verify MySQL/browser integration after publication. Never claim real Cajun Web Pro hosted activation, SKU provisioning or production readiness without external acceptance.
- Next: create `SOFTWARE-ADULT-CORE` as a software-family catalog product in Cajun Web Pro and publish a release; issue staging license and register hostname; verify hosted activation/revocation/timeouts; establish edition-specific licensing terms and version-based update rights.
- Keep `config/license.php` excluded from Git/ZIP, `storage/license-cache.json` excluded by the storage ignore, installation cleanup gate first, and developer suite tests green. Never add a second license generator/database to Adult_Core.

---

# Immediate handoff — 0.8.6.2-design-dev live drag-and-resize (2026-11-20)

Target `afterburn25/Adult_Core`, self-hosted Lite shared hosting / Pro verified VPS (not SaaS). Four built-in themes retained unchanged; **no new themes created**. This change adds `src/Design/CutoutPlacement.php` with validated 0–100 X/Y and 20–100 width, additive migration `m12_cutout_placement_v1`, new controls in `DesignController.php`, live pointer/touch/keyboard manipulation in `public/assets/design-editor.js`, positioned image CSS in `style.css`, persistent repository coordinates and matching public `design.css` output. `Layout::site` nests approved public hero art in a `cutout-positioner`. CSS and JavaScript require no CDN, inline scripts or executable plugin install.

**Deployment:** back up site DB, apply `/admin/setup`; until M12 is present `/admin/design` fails closed with a setup link. Existing M11 and selected cutouts remain private/public subject to original approval controls. No change to public web root or mandatory removal of `install/`.

**Tests:** PHP core/security/UI/design suites, Python HTTP smoke, PHP lint and Node JS syntax. Real MySQL CI integration verifies migration twice, saved X/Y/Width, invalid POST rejection, and public `design.css` values on a disposable database. Chromium interaction tests were run locally using a static fixture with the actual shipping CSS/JS; these test the pointer and slider UX without requiring authentication or MySQL. Record the actual CI outcome and commit SHA after publishing; do not claim CI passed until verified.

**Not implemented:** production member billing/media verification/Pro streaming, MFA, backup/restore certification, automatic background removal or arbitrary page-wide layering. Remain development-only.

---

# 0.8.6.1-design-dev theme-design maintenance

The four original `ThemeCatalog::THEMES` keys remain unchanged: `noir-gold`, `velvet-plum`, `midnight-teal`, `rose-ivory`. The studio has a clearly labelled no-cutout state and editable empty picture slot; it does **not** seed any model photos or change the existing CSS layouts. No schema migration is added. Separate edited concept previews (4 individual PNGs plus original references) are packaged outside the runtime ZIP, since gallery photographs in concept art are illustrative and unverified for public deployment. The runtime currently saves a single global selected hero image. Run `php tests/design_tests.php`, all prior unit/HTTP smoke checks and CI after publishing. See `docs/DESIGN_STUDIO.md`. Production blockers remain unchanged.

**Verified 0.8.6-design-dev CI:** [run 44826489627](https://github.com/afterburn25/Adult_Core/actions/runs/44826489627) passed PHP 8.3 lint/unit/security tests, disposable MySQL installation and authenticated browser checks (including real PNG upload and private/public decorative-image gating) for source commit [`c427cf9`](https://github.com/afterburn25/Adult_Core/commit/c427cf957a132749555cf5f777419b024cf240b6). Apache/cPanel production deployment, real adult verification, payments and streaming are NOT certified.

# Development server routing note

Run `php -S 127.0.0.1:8080 -t public public/router.php` for local tests. PHP's default static handling may return 404 for `/design.css`, so `tests/mysql_http_auth.py` and HTTP smoke tests now use this explicit router. Apache uses `public/.htaccess`; dynamic asset URLs must never bypass `public/index.php`.

# Current handoff — 0.8.6-design-dev Visual Design Studio

Target `afterburn25/Adult_Core`, **self-hosted** Lite (shared hosting) / Pro (verified VPS); **NOT** SaaS. Complete and manually remove top-level `install/` before any route unlocks. Only `public/` is web-exposed.

- Added `src/Design/{ThemeCatalog,DesignSchema,DesignRepository,DesignImages}.php`, `src/Http/DesignController.php`, `public/assets/design-editor.js`, new `public/assets/style.css` layout variants and `/design.css` / `/design/asset/{id}` routes. `/admin/setup` applies the additive `m11_visual_design_v1` migration. Admin `/admin/design` is gated by existing authentication, `site.settings.manage`, HTTPS and CSRF; uploads land in private `storage/design-assets/`.
- Four audited layouts and their palettes, custom validated accent, live homepage title/body plaintext edits, image choice and left/right/size placement. For now only homepage gets inline preview; additional pages use `/admin/page`. Automatic image background removal and drag-and-drop blocks remain future work.
- Public design assets only appear when explicitly public-safe, selected in settings, and home page is published. No real adult content or identity documents may be stored here. This declaration does not replace independent age/consent review. Signed third-party packages still CSS/data-only.
- Run PHP tests, `tests/design_tests.php`, HTTP smoke, disposable-MySQL integration (CI), browser visual review and the entire security suite. Record a **verified** GitHub commit / CI run, never assume a local artifact was pushed.
- Remaining blockers: administrator MFA, independent age/consent provider, adult media approval, approved payments, Pro video, signed updates/complete restore, host and security audit. **Not a production release.** See `docs/DESIGN_STUDIO.md`, `docs/SECURITY.md` and `docs/RELEASE_GATES.md`.

---

**0.8.5-ui-dev CI verification:** GitHub Actions [run 44777593038](https://github.com/afterburn25/Adult_Core/actions/runs/44777593038) passed PHP lint, all local-style UI tests and disposable-MySQL installer/admin/member browser integration checks for source commit [`f886e8684fa8be5c39ea953ff2c1373ea09bd937`](https://github.com/afterburn25/Adult_Core/commit/f886e8684fa8be5c39ea953ff2c1373ea09bd937). Production cPanel/VPS and security/compliance verification remain outstanding.

# Immediate handoff — 0.8.5-ui-dev (premium UI refresh)

**Repository:** afterburn25/Adult_Core; **edition model unchanged:** self-hosted Lite shared hosting and Pro verified VPS, not SaaS. **Status:** Development-only; no real adult media publishing, payment settlement or production Pro video.

**Implemented:** `src/UI/Layout.php` standardizes public, member, guest admin and authenticated admin shells. `src/UI/Dashboard.php` builds admin overview from existing authenticated session and MySQL-sourced metrics. `public/assets/style.css` adds premium dark design tokens, editorial hero/abstract CSS art, sidebar nav, form/editor/table layouts, mobile breakpoints, reduced-motion and focus behavior. `src/Website/PublicSite.php` loads and escapes the configured site name; `src/Http/{AdminController,SiteAdminController,ModerationController,MemberController}.php` delegate rendering to shared layouts. Routes, access gates, CSRF, DB schema and permissions are unchanged.

**Test:** `php tests/ui_tests.php`, all prior PHP/HTTP suites and PHP lint. CI definition includes UI tests; record actual GitHub run after publishing. The screenshots are generated with hard-coded **sample content only**, from production HTML generator methods, outside the web root—not live MySQL or authenticated browser screens.

**Next:** administrator MFA, accessible visual QA with real data on a disposable Apache/cPanel HTTPS deployment, complete publishing/verification and billing/Pro/release blockers. Avoid falsely claiming this UI refresh fulfills production 1.0. Keep manual installer-folder deletion gate and `public/` web root.

---

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


## Verified GitHub CI — 0.8.4-dev password recovery (2026-11-20)

- Source fix commit: [`e70582930faf914757ec3829c9251c9e54fed262`](https://github.com/afterburn25/Adult_Core/commit/e70582930faf914757ec3829c9251c9e54fed262).
- [GitHub Actions run 44765614968](https://github.com/afterburn25/Adult_Core/actions/runs/44765614968) passed: PHP lint and unit tests, HTTP smoke, disposable MySQL installation/recovery tests and browser session revocation tests.
- The first run caught a browser test-order issue: reset rotates CSRF, so the replay test now uses a newly acquired CSRF token to independently assert that the reset token cannot be reused. This is tested, not a production security audit.
- Real cPanel, email deliverability, identity/age verification, payment processing, Pro streaming, signed restore/update workflow, independent review and production 1.0 remain incomplete.


## Verified GitHub CI — 0.8.6.2 design cutout controls (2026-11-20)

Source commit [`bb06da70cfcfce2e18b012c139a0cf37f37980c0`](https://github.com/afterburn25/Adult_Core/commit/bb06da70cfcfce2e18b012c139a0cf37f37980c0) passed [GitHub Actions run 44830354150](https://github.com/afterburn25/Adult_Core/actions/runs/44830354150) on PHP 8.3 and disposable MySQL. PHP lint and all registered test suites passed, including the new repeat-safe cutout migration, persisted X/Y/Width, invalid POST refusal, and public CSS geometry checked through authenticated HTTP. The local Chromium pointer interaction and mobile fixture also passed. This does not certify Apache/cPanel, actual customer assets, real verification/payment/Pro services or a production 1.0 release.
