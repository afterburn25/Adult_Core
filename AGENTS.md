## Latest update — 0.8.6.2-design-dev cutout live manipulation

`src/Design/CutoutPlacement.php` implements strict X/Y/Width percentage validation and additive MySQL schema M12. `DesignController`, `DesignRepository`, public `/design.css`, `Layout::site`, `public/assets/style.css` and `public/assets/design-editor.js` now support a live drag-and-resize cutout editor. Browser pointer/keyboard/mobile fixture test is external to repo; documented in `docs/DESIGN_STUDIO.md`. Keep all four original built-in theme keys (no duplicates). Run all test suites and GitHub MySQL CI before claiming verified. Operator must back up DB and apply `/admin/setup`. No adult media/billing/identity/Pro/1.0 readiness claim.

## Current 0.8.6-design-dev: Visual Design Studio

Built-in audited themes plus inline homepage plaintext editing and private decorative cutout uploads now exist. Read `docs/DESIGN_STUDIO.md` before changing `src/Design/*`, `/admin/design`, `/design.css`, and `/design/asset/{id}`. Never expose uploaded images directly from public files, never assume public-safe checkbox equals independent age/consent verification, and never enable executing uploaded theme code. Apply `m11_visual_design_v1` via `/admin/setup` on a disposable database. Run `php tests/design_tests.php` and all suites, including GitHub disposable MySQL. Version remains a development preview; MFA/payments/streaming/production not done.

## 0.8.5-ui-dev interface handoff

The UI is now shared: `src/UI/Layout.php`, `src/UI/Dashboard.php`, and the design tokens in `public/assets/style.css`. Keep public/member/guest/admin templates consistent; do not inline new unrelated headers in controllers. Run `php tests/ui_tests.php` and all previous tests, and verify desktop/mobile layouts before changing navigation. Public site brand uses installed site name; admin brand is Adult Core. No external JS/fonts/trackers were introduced. The existing auth/install gates, CSRF, private media restrictions and future administrator MFA requirement are unchanged. Read `docs/UI_DESIGN.md`.

## 0.8.4-dev member recovery — read first

Read `docs/PASSWORD_RECOVERY.md` before modifying login, member sessions, email delivery or private media. Password resets require a confirmed member email, issue hashed one-time tokens, enforce cooldown and invalidate member sessions by database generation. Administrator accounts are **not** recovered through this route. Missing recovery migration must fail closed; `/admin/setup` adds `m10_member_recovery_v1`. Never treat reset as adult age verification or membership activation. Run disposable MySQL CI and update this handoff after publication.


## 0.8.3-dev continuation note

Before modifying member auth or media access, read `docs/EMAIL_CONFIRMATION.md`. The member email verification table is additive via `/admin/setup`; missing confirmation must fail closed. Never treat email ownership as age/identity verification. Do not log raw confirmation tokens. Keep `install/` cleanup gate and Lite/Pro restriction intact. Run the disposable MySQL email-confirmation and browser tests in CI after every change.
## Current release status (0.8.2-dev)

The application is **not production-ready**. Read `docs/SESSION_HANDOFF.md` and `docs/ROADMAP.md` first. Independent verification/provider adapter is not connected; webhooks disabled by default; performer roster and protected media checks are fail-closed. Signed archive verification and encrypted small-DB snapshot are foundations only, NOT a production updater or restoration system. Always run verification and release-security suites, and confirm disposable-MySQL CI before declaring DB compatibility. Never claim this is complete through 1.0.

# Current continuation rules — v0.8.1-dev

Read `docs/SESSION_HANDOFF.md`, `docs/MODERATION.md`, `docs/ROADMAP.md`, `docs/SECURITY.md` first. Moderation now provides private drafts, quarantine/reject, report triage and audit; **no approve or publish functionality**. Apply additive moderation schema via `/admin/setup` on disposable test host; never use real customer data. Run `php tests/moderation_tests.php` and GitHub disposable MySQL CI (both `mysql_install` and `mysql_http_auth`) in addition to the previous suites. Update documentation and verified GitHub commit per release. Do not treat report closure as approval.

# Current developer instructions — v0.8.0-dev

**Read `docs/RELEASE_GATES.md`, `docs/ROADMAP.md`, `docs/SESSION_HANDOFF.md`, and `docs/SECURITY.md` before editing.**

Lite/shared hosting and Pro/VPS install into independent site owners' hosting. NEVER redesign as SaaS. Top-level `install/` MUST be physically deleted after signed install completion; all public/admin routes remain blocked until then. `public/` MUST be the sole document root. Never expose `storage/` secrets or media.

New development code supports only plain-text public pages and **pending** member accounts. There is no production age/identity provider, content consent approval workflow, processor or streaming. `src/Pro/JobInbox.php` stores signed jobs but cannot execute them. `scripts/release-check.php` intentionally prevents declaring 1.0 ready.

**After every development session:** update `README.md`, `docs/ROADMAP.md`, `docs/SESSION_HANDOFF.md`, `docs/CHANGELOG.md`, `docs/DECISIONS.md`, and tests. Run all tests listed in `docs/RELEASE_GATES.md`. Publish changes to GitHub and record a verified commit hash; do not invent test results or claims of pushed code.

---

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

**0.8.6.1-design-dev note:** Keep `ThemeCatalog::THEMES` at the four original IDs; never duplicate existing themes to show reference mockups. A theme can have no hero cutout. Background and gallery imagery in concept mockups is NOT automatically licensed or included in the live website. Do not expose private Design Studio images, remove installation locks, or weaken the member-media authorization gate.

**0.8.6.3-license-dev:** Cajun Web Pro is the sole license authority (see `docs/CAJUN_WEB_PRO_LICENSING.md`). `SOFTWARE-ADULT-CORE` must be registered as a software product in Cajun_Web before real activation. Never ship `config/license.php` or `storage/license-cache.json`, never create a second license service. Tests use in-process fake vendor responses; real vendor API/hosted authorization remains unverified. Installation gates still run before the license guard, and loss of a valid license cannot unlock any feature route.
