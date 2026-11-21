## 0.8.11-host-preflight-dev — local deployment-readiness diagnostics (2026-11-20)

- Added `src/Capability/DeploymentPreflight.php` and CLI `scripts/deployment-preflight.php` with text/JSON results for preinstall/installed Lite/Pro expectations; preserves license-key edition authority and never returns private license credentials.
- Fail-closed checks for unsafe or wrong document root, public symlinks/executables/dotfiles and accidentally public `config/`/`storage/`/`install/`, missing PHP extensions, verified Pro loopback capability, missing/invalid signed installation state and inconsistent edition.
- Added fixture and authenticated loopback Pro-agent tests, updated CI, and explicit manual-host-only acceptance runbook `docs/DEPLOYMENT_PREFLIGHT.md`. Real shared-host/VPS deployments, CWP merchant subscription sandbox, offsite recovery drills, age/consent verification and production certification remain open.

## 0.8.10-cwp-staging-dev — Read-only issuer staging acceptance kit

- Add operator-only, explicitly confirmed diagnostic for one owner-issued Cajun Web Pro staging key; validates product/domain acceptance and two negative authorization controls over strict trusted HTTPS with no redirects, three calls total and no mutation/billing endpoints.
- Require private license-key file outside checkout, never transmit a key in argv; prevent accidental primary-host calls without extra approval and refuse foreign hosts, query parameters, cleartext transport and links.
- Add deterministic offline tests for endpoint policies, permissions, unauthorized SKU/domain, master-grant overreach, malformed response and 429/5xx inconclusive handling; CI retains existing MySQL/browser suites.
- **No live product creation, actual payment, subscription enrollment, merchant sandbox testing or production release.** Staging service and credentials must be authorized separately.

## 0.8.9-backup-ops-dev — Verified backup operations and private vault retention

- Add CLI read-only authenticated archive verifier: validates version/edition, schema/row ordering, safe private-file paths, chunk lengths/hashes, signed installation state, mandatory files and authenticated terminal counters; never exports secrets.
- Add private vault copy + postcopy full verification/SHA-256 match, immutable unique naming, conservative retention of the newest N verified backups, stale/concurrent lock refusal and preservation of unreadable/unknown files.
- Add opt-in cron-compatible backup job requiring externally quiesced writes and Pro worker shutdown confirmation. The signed installed site enters fail-closed backup maintenance (503) for new HTTP requests during the operation; a hard crash leaves a marker for operator recovery.
- Add isolated unit tests, CI integration with full real-MySQL vault-copy-to-empty-DB restore, operator runbook and CLI commands. No storage-provider upload, automatic write quiescence, real host recovery certification or production 1.0 claim.

---

## 0.8.8-backup-dev — Encrypted full-site restore development milestone

- Add a streaming AES-256-GCM authenticated archive for all Adult_Core InnoDB DDL/rows and explicitly allowed private media/config files (including signed licensing, design, MFA material), excluding sessions and old DB credentials.
- Add CLI-only export and fresh, empty same-version restore; archive preflight authenticates all records, private file paths/checksums and signed installation state before database DDL import.
- Add permanent fail-closed backup-restore pending marker if restoration fails mid-import, since MySQL DDL cannot be rolled back atomically. No browser bypass, live overwrites or automatic installer removal.
- Add non-MySQL archive/security tests and disposable-MySQL content/member/media roundtrip CI. Independent hosted/Pro recovery certification remains open.


---

## 0.8.7-mfa-dev — Administrator authenticator MFA (2026-11-20)

- Add additive `ac_admin_mfa` state with encrypted 160-bit TOTP secrets, 30-second RFC 6238 codes, ±1-step skew allowance and transactional replay prevention.
- Add password-reauthenticated `/admin/security` enrollment/disable, 5-minute `/admin/mfa` challenge, 10-minute pending enrollment and ten single-use 64-bit recovery codes stored only as keyed digests.
- Rotate sessions after password verification and full second-factor completion, revalidate MFA status on every authenticated admin route, and refuse silent downgrade after MFA DB row/table removal.
- Add rate limiting, CSRF, role checks, PHPUnit-free unit tests and disposable MySQL/real HTTP workflow coverage.
- Not complete: WebAuthn, independent security assessment, live hosting certification, backup/restore, actual Cajun Web Pro subscription billing activation, and adult-content provider/compliance gates.

## 0.8.6.7-subscription-contract-dev — 2026-11-20

- Added an isolated stateful loopback HTTP simulator for Cajun Web Pro's product-bound license validation, and PHP fixture exercising the actual Adult_Core resolver/signed-cache/license manager without an external network or charge. Covers four products and active/unpaid/expired/renewed/cancelled/revoked/wrong-domain/outage/transfer flows.
- Added the test to PHP CI, retaining the disposable MySQL and authenticated browser tests. No actual CWP catalog, payment, subscription or gateway code is modified by the simulator.
- Administrator licensing clarifies that the API does not reveal upcoming charge dates and that swapping installed keys does not cancel recurring charges; the billing link remains the CWP homepage placeholder.
- Next gates: verify GitHub CI, owner-controlled staging SKU provisioning and real merchant sandbox, then admin MFA and complete backup/restore certification. Not a production release.

## 0.8.6.6-license-health-dev — 2026-11-20

- Added a real-time **Check license now** control to the authenticated administrator license screen; restricted mutating license actions to site-managing roles and kept explicit CSRF and rate limits.
- Added one-request-only vendor-result classification (`valid`/`denied`/`transient`) without storing or exposing license keys. One-hour cached verdicts remain intact unless the administrator requests a recheck; any denied response overwrites the signed cache.
- Added unit checks for forced validation, revocation, subsequent recovery, and temporary outage grace, plus authenticated HTTP checks for the check form's CSRF and invalid actions.
- Added a four-SKU, owner-controlled Cajun Web Pro catalog/staging checklist. No real license issuance, prices or merchant activation.

## 0.8.6.5-upgrade-dev — In-place Lite-to-Pro transition (2026-11-20)

- Added authenticated `/admin/upgrade` with step-up password check, CSRF, rate limiting, backup acknowledgement and Pro host/agent readiness.
- Added `EditionUpgrade` preflight, product-bound Pro license validation, in-place `ac_installations.edition` update, HMAC-signed installation marker rewrite, fresh vendor-bound license receipt, and private short-lived rollback snapshot.
- `InstallationGate` now blocks all HTTP routes during an incomplete upgrade. Emergency recovery is CLI-only; no web rollback endpoint or unverified Pro activation.
- Added unit/browser/disposable-MySQL tests for denial cases, fault-injected rollback and preservation of member/page/theme/content records. Real purchased keys, cPanel/VPS hosting and external restore remain unverified.

## Changelog — edition-bound monthly licensing (2026-11-20)

Version `0.8.6.4-license-dev`: **one installable software**, key-selects Lite/Pro and either one-time or monthly term via Cajun Web Pro's four separate product SKUs. Pro eligibility is independently enforced from verified host capabilities; cross-edition license replacement is blocked until safe migration is implemented. Paid static add-ons may declare signed `license_sku` and require a distinct vendor key before enabling; runtime eligibility rechecks signed entitlement caches. Lite admin displays disabled Pro-only features with **Pro Version Only** and an upgrade link to https://cajunwebpro.com (placeholder). No customer payment or live license issuance was performed. The earlier single SKU is retired for new installs; existing installations require a matching replacement key. Operator must register catalog offerings and test actual monthly billing externally. Details: `docs/CAJUN_WEB_PRO_LICENSING.md`. Release 1.0 still blocked by production compliance, payment, streaming and security gates.

## 2026-11-20 — 0.8.6.3-license-dev: Cajun Web Pro product licensing integration

- Verified Cajun_Web's product-bound validation endpoint contract and wired one shared license authority into Adult_Core. Added mandatory key/hostname validation before MySQL bootstrap, private first-install license state, signed positive/negative cache, runtime revalidation/revocation with limited transient grace, wrong-domain refusal, and authenticated administrator `/admin/license` recovery.
- Existing Lite/Pro detection, physical installer cleanup, signed install completion, admin sessions/CSRF, package controls and private-media gate remain intact.
- Added fake-service contract tests, denied-install protection, HTTP license-lock smoke tests, and disposable MySQL/browser assertions; updated CI to run license tests. No live Cajun Web Pro license was provisioned, and existing production hosting has not been tested.
- **Operator action still required:** register `SOFTWARE-ADULT-CORE` in Cajun Web Pro's software catalog and issue a product-specific license with registered domain before any real installation can pass.

## 2026-11-20 — 0.8.6.2-design-dev: drag/resize live cutouts

- Added additive, restart-safe `CutoutPlacement::migrate()` (`m12_cutout_placement_v1`) with bounded integer coordinates and one-time translation from legacy Small/Medium/Large.
- Design Studio now displays explicit Move and Resize handles, percentage sliders, reset, keyboard shortcuts and mouse/touch pointer capture with live CSS position/size preview.
- Public hero uses the same CSS variable geometry only after validated saved settings, and continues to refuse unpublished or private image assets.
- Extended PHP and disposable-MySQL HTTP/installer tests for unsafe CSS input, coordinate persistence, and public parity. Locally exercised Chromium pointer dragging, resizing, keyboard/reset and mobile layout against a static fixture; GitHub CI/cPanel certification pending.
- No duplicate themes, new executable plugins, billing, content publishing, or changed installer-folder deletion gate.

## 0.8.6.1-design-dev — Empty hero cutout slots and nonduplicated themes

- Kept all four existing built-in theme identifiers, palettes and layout CSS exactly once; no migration.
- Clarified the no-cutout selection and added a visible upload slot within the administrative visual editor without replacing the original theme backgrounds.
- Updated editor JS/CSS, regression checks, and documentation. Created a separate concept-art bundle with background/gallery imagery retained but prominent hero models removed.
- No production age-verification, consent certification, payments or streaming implemented.

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


## Verified GitHub CI — 0.8.6.2 design cutout controls (2026-11-20)

Source commit [`bb06da70cfcfce2e18b012c139a0cf37f37980c0`](https://github.com/afterburn25/Adult_Core/commit/bb06da70cfcfce2e18b012c139a0cf37f37980c0) passed [GitHub Actions run 44830354150](https://github.com/afterburn25/Adult_Core/actions/runs/44830354150) on PHP 8.3 and disposable MySQL. PHP lint and all registered test suites passed, including the new repeat-safe cutout migration, persisted X/Y/Width, invalid POST refusal, and public CSS geometry checked through authenticated HTTP. The local Chromium pointer interaction and mobile fixture also passed. This does not certify Apache/cPanel, actual customer assets, real verification/payment/Pro services or a production 1.0 release.
