## 0.8.6.1-design-dev — Empty hero cutout slots

- Preserved the four built-in theme entries, palettes, layout and backgrounds without duplicates or new tables.
- Made the no-cutout option clear and added an image-upload placeholder in the live Design Studio preview.
- Added regression tests and documentation; image concept references (with background/gallery thumbnails) remain outside the runtime gallery.

**Verified 0.8.6-design-dev CI:** [run 44826489627](https://github.com/afterburn25/Adult_Core/actions/runs/44826489627) passed PHP 8.3 lint/unit/security tests, disposable MySQL installation and authenticated browser checks (including real PNG upload and private/public decorative-image gating) for source commit [`c427cf9`](https://github.com/afterburn25/Adult_Core/commit/c427cf957a132749555cf5f777419b024cf240b6). Apache/cPanel production deployment, real adult verification, payments and streaming are NOT certified.

## 2026-11-20 — 0.8.6 dev-server route correction

- Added `public/router.php` for PHP's built-in development server to correctly route dynamic `/design.css` and `/design/asset/*`; Apache continues using `public/.htaccess`. Updated HTTP integration tests to use the dev router. This keeps the installation/auth/image gates in effect, rather than inventing a static file.

## 2026-11-20 — 0.8.6-design-dev (development)

- Added authenticated Visual Design Studio with 4 layout/palette presets, validated custom accent CSS, live homepage plaintext editing, private decorative image library and responsive preview.
- Uploaded PNG/WebP/JPEG assets are size/dimension/type checked and private by default. Public asset serving is only allowed after operator confirmation of non-explicit rights-cleared decorative use, selection in design settings and publication of the home page.
- Added `m11_visual_design_v1` additive schema, PHP/browser tests, design docs. Did not alter installation folder gate, member/private-media entitlements, or paid-media authorization. Automated background removal, adult verification, real billing and production release remain pending.

**0.8.5-ui-dev CI verification:** GitHub Actions [run 44777593038](https://github.com/afterburn25/Adult_Core/actions/runs/44777593038) passed PHP lint, all local-style UI tests and disposable-MySQL installer/admin/member browser integration checks for source commit [`f886e8684fa8be5c39ea953ff2c1373ea09bd937`](https://github.com/afterburn25/Adult_Core/commit/f886e8684fa8be5c39ea953ff2c1373ea09bd937). Production cPanel/VPS and security/compliance verification remain outstanding.

# Changelog / implementation record

## 0.8.5-ui-dev — premium UI foundation (2026-11-20)

- Replaced scattered, inconsistent public, member, guest admin, and authenticated admin HTML wrappers with `src/UI/Layout.php` (responsive server-rendered layout, safe escaped branding, contextual navigation, keyboard skip link and explicit authenticated sidebar).
- Added a premium dark visual design system directly to `public/assets/style.css` with polished gradients, hierarchy, responsive desktop/mobile layouts, reduced-motion controls, form/table styling, and no external fonts/scripts/trackers.
- Added `src/UI/Dashboard.php` with an editable site-specific workspace banner, truthful installation/account metrics, quick links and prominent development-stage restrictions. No fabricated revenue or approval counts.
- Improved actual CMS page editor with a two-column guidance panel and consistent admin navigation. Moderation and member-account screens share the same visual language; no permission, CSRF, user-status, billing, identity, or publication code was intentionally changed.
- Added `tests/ui_tests.php` with escaped output, navigation, CSRF markup and accessibility checks; CI runs it. Browser screenshots are derived from the actual PHP UI layout with **sample-only** content, not from a real MySQL-installed site.
- **Security/nonfeatures:** This is a UI milestone, **not administrator MFA**. All previous production-release blockers remain. Host-specific Apache/cPanel verification and full accessible usability evaluation are pending.

## 2026-11-20 — 0.8.4-dev: member password recovery (development)

- Added additive `PasswordRecoverySchema` and `PasswordRecovery` with token digests, 30-minute expiry, 5-minute cooldown and single-use transaction. Only confirmed members can request; administrator accounts excluded.
- New `/members/recover` and `/members/recover/reset` routes; neutral account-disclosure-safe request response, HTTPS/CSRF protections, GET preview without consuming tokens, fixed-origin mail adapter and retry throttling.
- Successful reset updates the password hash and increments the member's session generation. Every member and private-media request now checks the generation; stale sessions are revoked. Reset cannot change account status or unlock media.
- Added unit and disposable MySQL/browser integration tests, deployment guidance `docs/PASSWORD_RECOVERY.md`; production mail delivery, administrative MFA and age/consent compliance remain unverified.

## 2026-11-20 — 0.8.3-dev: member email confirmation (development)

- Added `MemberEmailSchema`, `EmailConfirmations`, `EmailDelivery`, and opt-in private `config/email.php` sample.
- Registration can initiate a confirmation message on explicitly configured shared hosts; logged-in members may request a new link with database cooldown. No live SMTP provider or guaranteed delivery.
- Links are hashed, single-use, expire in 30 minutes, and only a CSRF-protected POST can consume them. GET previews do not mutate data.
- Private member media now also requires confirmed email ownership; age/identity, performer consent, moderation and paid access remain separate fail-closed checks.
- Added disposable MySQL and HTTP tests plus updated privacy and deployment documentation. No release 1.0 claim.

## 2026-11-20 — 0.8.2-dev: verification and release security foundations

- Added `VerificationSchema`, `SignedAssertion`, `VerificationRegistry`, and disabled-by-default `/webhooks/verification` adapter entry point. Signature/time/metadata checking, per-provider replay deduplication and out-of-order refusal, member-age revocation and performer-evidence invalidation. No real vendor is installed and no approval/publishing route exists.
- Added `expected_performers` to content. Unknown/mismatched consent roster now denies even previously approved private media. Added admin-only pending roster declaration and review visibility, never evidence approval.
- Added `EncryptedSnapshot` (authenticated AES-256-GCM), bounded CLI-only development DB export and signed manifest verifier for future release updates. They are not complete production backup or updater services.
- Extended local validation, MySQL fixture, authenticated browser and CI suites; new `docs/VERIFICATION.md` and `docs/BACKUPS_AND_UPDATES.md`.
- **No claim of 1.0 completion:** payment processor, independent provider verification, real media upload and approval, Pro streaming, restore/rollback, administrator MFA and shared hosting certification remain blocked.

# 2026-11-20 — 0.8.1-dev: moderation and abuse reports (not production-ready)

- Add idempotent `ModerationSchema` and append-only `ac_moderation_actions` review log. Re-applying `/admin/setup` installs the new table.
- Add `/admin/moderation` with private draft creation, content quarantine/reject and reasoned report resolution, authenticated and CSRF-gated. It cannot approve or publish.
- Add `/members/report` with authenticated, CSRF-protected safety reporting; content and all related media are quarantined atomically, with duplicate submissions suppressed per member/content/24 hours.
- Deny media entitlement while any report remains open; resolution never automatically restores content.
- Add unit, HTTP smoke and disposable-MySQL tests; document moderation security limitations and future integration needs.

---

# Latest changelog — 2026-11-20 / 0.8.0-dev

- Created safe page schema and public renderer with plain-text escaping, draft/published management, `/admin/setup`, `/admin/pages`, `/admin/page` and an admin members list.
- Created pending member signup, login, independent member sessions and CSRF/rate limits. Accounts cannot self-activate or self-verify age.
- Added idempotent private-content schema and verified-age/consent/moderation entitlement policy, private file key checking and guarded image delivery without public uploads.
- Added signed, deduplicated Pro job inbox **without executing code** and explicit production release blockers.
- Expanded pure PHP, HTTP and disposable MySQL tests; updated CI and handoff docs. Production release remains blocked.

---

## 2026-11-20 — 0.5.0-dev foundation expansion (not completed M4–M8)

- Serve selected signed theme CSS through installation-gated `/theme.css` while still refusing executable upload code.
- Add draft member/content/consent/report schema and default-deny private entitlement policy, not activated in production.
- Add replay/idempotency-ready billing inbox draft and HMAC check (no real payment adapter).
- Add allowlisted HMAC Pro job contract (no real streaming worker).
- Add developer source ZIP script and new unit tests.
- Update roadmap, CI workflow and session handoff.

# Changelog / implementation record

## 2026-11-20 — Milestone 1.1: removable installer and access gate

- Added **top-level `install/`** and moved the browser preflight view from `public/index.php` to `install/index.php`. The URL `/install/` is still served by the safe public front controller.
- Added `src/Installer/InstallationGate.php`, a fail-closed state machine checking installation completion and the physical presence of `install/`.
- Added `src/Installer/LockedScreen.php` for cleanup-required, missing-installer and invalid-state responses.
- Updated `public/index.php` to block site/admin, redirect incomplete installs to `/install/`, and reject installation access after completion.
- Added `public/.htaccess` for Apache/cPanel route rewriting.
- Expanded PHP tests with temporary fixture directories for required cleanup and negative cases; added 11 isolated HTTP smoke tests and GitHub Actions coverage.
- Added `AGENTS.md`, `docs/SESSION_HANDOFF.md`, `docs/ROADMAP.md`, `docs/DECISIONS.md` and `docs/INSTALLATION_LIFECYCLE.md` for future-session continuity.
- **Not implemented:** credentials, database setup, admin creation, real installation finalization, full site runtime.

## 2026-11-20 — Milestone 1: foundation prototype

- Added minimal PHP preflight frontend, Lite/Pro manifests, PHP host checks and authenticated loopback Pro-agent probe.
- Added module compatibility engine and example photo-gallery, streaming and real-time-chat manifests.
- Added PHP unit-style test runner, CI lint/test workflow and setup/hosting documentation.

## 2026-11-20 — Milestone 2: MySQL installer implementation (integration pending)

- Added full browser `install/` form for site name, new database credentials, administrator email and password, with automatic Lite/Pro profile selection.
- Added installer session cookie hardening, CSRF protection, HTTPS enforcement on real domains, server-side field validation, rate limiting and a concurrency lock.
- Added MySQL bootstrap schema (`ac_users`, `ac_settings`, `ac_schema_migrations`, `ac_installations`), prepared inserts, hashed first administrator password and edition persistence.
- Added private configuration writes using owner-only atomic files; completion marker is now an HMAC-signed schema-v2 JSON record written **last**. `InstallationGate` refuses unsigned, tampered or incomplete configuration.
- Kept mandatory manual deletion of `install/` before any site/admin route is accessible. No automatically deleting installation files.
- Added filesystem-redacted installation auditing and explicit refusal to overwrite an existing Adult Core database schema.
- Extended PHP and HTTP test coverage, created opt-in real-MySQL integration test and configured GitHub Actions MySQL service.
- **Not verified locally:** real database installation; PHP environment lacks `pdo_mysql` and MySQL. **Not implemented:** production app/admin login and membership runtime.

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
