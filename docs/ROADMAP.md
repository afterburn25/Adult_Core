## 0.8.11 deployment preflight — development only

A read-only operator CLI now triages Lite shared-host / Pro VPS capabilities and insecure `public/` layouts before and after installation. It does not choose the edition, contact the issuer, configure HTTPS/FPM, install a production Pro worker or certify actual cPanel/VPS hosting. Those real staging acceptance tests and all payment/age/consent/security release gates remain open. See `docs/DEPLOYMENT_PREFLIGHT.md`.

## 0.8.10 staging probe checkpoint

Implemented CLI-only **read-only** CWP staging key product/domain acceptance probe with strict trusted HTTPS and opt-in. The four Adult_Core edition × term SKUs and one installer are unchanged. Actual CWP product rows, issuer-authorized staging keys, Authorize.Net sandbox enrollment/renewal/cancel, external Lite/Pro hosting tests and release safety/compliance remain **open**; the probe does not substitute for merchant acceptance. See `docs/CWP_STAGING_PREFLIGHT.md`.

# 0.8.9 development checkpoint — backup verification, private vault and controlled scheduling

Encrypted archival verification and verified vault rotation are now development features. Operators can schedule the CLI job **only with actual host-side draining, stopped file/DB writers and stopped Pro workers**; the new HTTP maintenance gate alone does not guarantee a consistent cross-file/database snapshot. Retention is count-based in an operator-provided private directory, not remote upload, immutable storage or true PITR. Real cPanel and Pro VPS disaster drills, cloud/remote replication, external monitoring, billing sandbox activation and independent compliance/security review remain open. See `docs/BACKUP_OPERATIONS.md`.

---

# Current milestone — encrypted portable backup and empty-site restore (0.8.8-backup-dev)

Development implementation: streaming encrypted `.acfull` backup covers Adult_Core MySQL schema/rows and private media/license/MFA files; CLI-only verified restore targets a fresh same-version source plus completely empty dedicated MySQL database and preserves the installer gate. See `docs/PORTABLE_BACKUP_RECOVERY.md` for explicit limits and destructive-DDL warnings. Complete production backup/restore is **not yet certified**: offsite retention, write quiescence, cPanel/VPS drills, large data, database PITR and independent security review remain. Cajun Web Pro real product registration/billing and adult identity/consent/processor gates remain unchanged.


---

# Current development milestone — 0.8.7-mfa-dev

Implemented: administrator authenticator MFA enrollment and challenged login, encrypted TOTP secret storage, single-use recovery codes, replay protection, reauthentication for disabling, and additive schema preparation, with local tests and CI acceptance required. This is **not production certification**. Next security work: full backup/restore drills and session revocation/password rotation; Cajun Web Pro still needs actual catalog products and verified paid sandbox renewals; age/identity, consent and billing gates remain blocked. See `docs/ADMIN_MFA.md`.

---

# Subscription activation contract milestone — 0.8.6.7 (2026-11-20)

**Implemented locally:** HTTP loopback simulation of all four Cajun Web Pro license products, monthly paid-through expiration and renewal, cancellation/revocation, wrong domain, transient errors, manual recovery, same-edition license replacement, and edition separation. New CLI/Python fixtures do not enable billing or replace the issuer. Admin licensing now explains cancellation remains with CWP. **Still blocked:** owner-created products and prices, real sandbox issuer/payment/renewal tests, real production subscriptions, administrator MFA and independent production certification. See `docs/SUBSCRIPTION_ACTIVATION_TESTING.md`. GitHub CI result must be checked after publishing.

# Licensing health and Cajun Web Pro catalog readiness — 0.8.6.6-license-health-dev (2026-11-20)

**Implemented in development:** Live administrator recheck through the existing vendor endpoint, with site-management authorization, CSRF, IP rate limit, signed cache, explicit-denial enforcement, and a visible distinction between vendor outage and active authorization. Four Lite/Pro × one-time/monthly product requirements and a staging acceptance matrix are documented in `docs/CWP_CATALOG_SETUP.md`. **Not live:** Cajun Web Pro catalog SKU creation, prices, production subscriptions, merchant approval, owner issuance or actual staging payments; these require owner action and verified external acceptance. Do not claim 1.0.

# Lite-to-Pro verified transition — 0.8.6.5-upgrade-dev (2026-11-20)

**Implemented in development:** admin `/admin/upgrade` can accept a separately issued Pro one-time/monthly key on the same domain and preserve the existing installation, database, member/page/content/design rows and original install timestamp. Checks require Pro-host capabilities, verified product SKU, authenticated settings administrator, step-up password, CSRF, rate limit and off-site backup acknowledgement. A private snapshot plus fail-closed upgrade-pending gate and CLI rollback guard interruptions. This update does not provide a complete backup/restore engine, production worker, real vendor checkout, independent security audit or production migration certification. Cross-product paid module licenses remain independent. More: `docs/LITE_TO_PRO_UPGRADE.md`.

## Roadmap — edition-bound monthly licensing (2026-11-20)

Version `0.8.6.4-license-dev`: **one installable software**, key-selects Lite/Pro and either one-time or monthly term via Cajun Web Pro's four separate product SKUs. Pro eligibility is independently enforced from verified host capabilities; cross-edition license replacement is blocked until safe migration is implemented. Paid static add-ons may declare signed `license_sku` and require a distinct vendor key before enabling; runtime eligibility rechecks signed entitlement caches. Lite admin displays disabled Pro-only features with **Pro Version Only** and an upgrade link to https://cajunwebpro.com (placeholder). No customer payment or live license issuance was performed. The earlier single SKU is retired for new installs; existing installations require a matching replacement key. Operator must register catalog offerings and test actual monthly billing externally. Details: `docs/CAJUN_WEB_PRO_LICENSING.md`. Release 1.0 still blocked by production compliance, payment, streaming and security gates.

## Cajun Web Pro license integration — 0.8.6.3-license-dev

**Implemented in source:** mandatory product-bound Cajun Web Pro vendor validation before initial database setup; private server-side key storage; HMAC-sealed hourly runtime cache, explicit revocation and 72-hour bounded transient-outage grace; wrong-domain lockout; authenticated CSRF-protected `/admin/license` repair for legacy installations. Existing Lite/Pro capability selection and mandatory physical `install/` deletion are preserved.

**Not yet done:** Register/publish the `SOFTWARE-ADULT-CORE` SKU in Cajun Web Pro (not found in its code/catalog definitions), validate a real staging key/domain and live hosted TLS, add software update-version entitlements, test existing-host migration and enterprise license support. Production readiness remains blocked by unrelated release gates. See [CAJUN_WEB_PRO_LICENSING.md](CAJUN_WEB_PRO_LICENSING.md).

## Current UI milestone — 0.8.6.2-design-dev: live hero cutout manipulation

**Implemented locally (CI verification pending):** in `/admin/design`, cutouts can be repositioned by mouse/touch dragging (or keyboard via Move handle and three range sliders) and resized with a visible corner handle. New percentages persist in additive `m12_cutout_placement_v1` and render through validated public `/design.css` geometry, preserving the four existing themes and explicit public-safe asset policy. Browser pointer-interaction and mobile-width checks passed with a static fixture using the shipped CSS and JavaScript.

**Unfinished:** per-theme independent layouts/draft positioning, true page-wide drag and drop, richer multiple-image layering, visual masks and undo/redo, automatic background removal; also all existing MFA/age/consent/billing/Pro video/update/hosting/independent-audit release gates. No production 1.0 claim.

## 0.8.6.1-design-dev — Theme preservation and cutout slots

The **same four** built-in themes remain selectable (no duplicated IDs). The administrator can explicitly choose no hero cutout while retaining theme background styling, or upload a personal transparent cutout. Four edited concept screenshot pages are supplied **outside the runtime codebase** for reference. Theme background art in these mockups is visual reference; it is not silently installed into public CSS assets. No member media or public adult gallery thumbnails are imported.

**Verified 0.8.6-design-dev CI:** [run 44826489627](https://github.com/afterburn25/Adult_Core/actions/runs/44826489627) passed PHP 8.3 lint/unit/security tests, disposable MySQL installation and authenticated browser checks (including real PNG upload and private/public decorative-image gating) for source commit [`c427cf9`](https://github.com/afterburn25/Adult_Core/commit/c427cf957a132749555cf5f777419b024cf240b6). Apache/cPanel production deployment, real adult verification, payments and streaming are NOT certified.

## 0.8.6-design-dev — editable themes and decorative cutouts

**Implemented for development testing:** `/admin/design` with four trusted presets (`noir-gold`, `velvet-plum`, `midnight-teal`, `rose-ivory`), distinct layouts, palette editor, inline homepage text, private uploaded decorative images, transparent cutout placement, and fail-closed public image delivery. Existing signed CSS add-on rules remain. New schema `m11_visual_design_v1` is additive. **Not implemented:** automatic background removal, arbitrary page inline editing, component dragging, section reordering, executable extension themes, theme version upgrade/rollback or adult-media approval. Real MySQL CI requires verification after commit. See [Design Studio guide](DESIGN_STUDIO.md).

**Still open for 1.0:** administrator MFA, contracted identity/performer consent verification, authorized adult uploads, paid subscriptions, production video services, updates/backups and hosting/security certification. Do not mark M4/M5/M6/M7/M8 done.

---

# Current UI milestone — 0.8.5-ui-dev (2026-11-20)

**Premium interface refresh: implemented and locally tested**. Site homepage and published pages now use a responsive branded layout, membership and admin guest pages share a refined sign-in style, authenticated administration uses a consistent sidebar + header, real metrics, quick links, and an improved page editor. The redesign uses no external JS, fonts or network dependencies and preserves signed CSS theme selection.

**Remaining:** Administrator MFA, production age/identity and consent verification, verified billing, Pro streaming, signed update & complete backup restore, hosting certification and independent security review. This aesthetic release does not move any payment/identity/streaming/production feature to done. Next priority is MFA/security and full verification integrations. See `docs/UI_DESIGN.md` and `docs/RELEASE_GATES.md`.

---

## Current development increment — 0.8.4-dev

Member password recovery added with a confirmed-email prerequisite, 30-minute single-use token, neutral account-enumeration-resistant responses, CSRF-protected confirmation and session-generation revocation checked by private-media delivery. **No age/identity verification, adult-content approval, processor integration, Pro streaming or production readiness.** See `docs/PASSWORD_RECOVERY.md`. GitHub CI status must be verified after publishing this source.

## 0.8.3-dev — Member email ownership (2026-11-20)

- **Implemented:** additive email-confirmation migration, hashed one-time 30-minute tokens, 5-minute resend cooldown, neutral opt-in PHP mail transport, public confirmation preview (GET) plus CSRF-protected consuming POST, member email settings, and private-access refusal until email ownership is confirmed. Does not activate members or self-certify age.
- **Verification:** local unit/HTTP suite; disposable MySQL and real HTTP CI must pass before this release is called integration-verified. Mail delivery and actual shared-host behavior remain unverified.
- **Still open:** contracted age/identity provider, consent workflow, adult-content approvals, admin MFA, approved payments, Pro worker, backup/restore updater, cPanel/VPS certification and independent security review.

# Adult_Core roadmap — current iteration

**2026-11-20, version 0.8.4-dev (development only).** The project has not reached production 1.0.

| Target | Current status | Verified work | Unfinished prerequisites |
|---|---|---|---|
| 0.6 Public frontend & themes | **ALPHA** | Publish/draft plain-text pages, browser editor, public navigation and selected signed CSS | Full layout templates, signed theme updates/rollback, cPanel validation |
| 0.7 Membership accounts | **ALPHA** | Separate sessions and login, CSRF/rate limits, pending registration, confirmed inbox workflow, member password-reset tokens & browser flow, revocable member sessions, admin member listing | Admin MFA/recovery, production SMTP, real age/identity verification, profile and tier UX, verified email delivery on shared hosting |
| 0.8 Private content | **FOUNDATION + VERIFICATION SCAFFOLD** | Private media gate, triage, signed verification protocol tests, revocation and pending performer roster | Real content uploads, contracted independent provider, lawful consent/approval and appeals |
| 0.9 Payments | **FOUNDATION ONLY** | Existing generic verified event inbox | Approved provider, real processor webhook adapter and checkout, renewals/refunds/disputes |
| 0.10 Pro streaming | **FOUNDATION ONLY** | Signed job envelope and non-executing replay-safe inbox | Verified VPS, FFmpeg worker, segment authorization, live/realtime streaming |
| 1.0 Production release | **BLOCKED** | Explicit fail-closed release gates and source ZIP tooling | Full verified updates, restores, audits, host certification, all above |

See [`RELEASE_GATES.md`](RELEASE_GATES.md). This iteration does **not** claim versions 0.6–1.0 are all completed.

## Immediate engineering priorities

1. Run new disposable MySQL CI browser tests for `/admin/setup`, page editor, registration and status; fix defects. Repeat on actual cPanel Apache HTTPS.
2. Complete independent age/identity provider, performer consent completeness and uploader controls before allowing adult media uploads or any publish/approve action. The 0.8.1 safety-triage UI does NOT perform verification.
3. Finish the signed theme/template runtime and rollback-capable package upgrades.
4. Obtain payment-provider approval and build provider-specific checkout/webhooks, then finish the Pro worker and production-hardening gates.

---

## Previous milestone tracking (historical)

# Adult_Core roadmap (maintained)

Status as of **2026-11-20**, developer snapshot **0.5.0-dev**. Update statuses and acceptance criteria after every milestone. Do not mark code production-ready merely because the unit tests pass.

| ID | Scope | Status | Acceptance criteria |
|---|---|---|---|
| M1 | Core layout, Lite/Pro manifests, capability checks, example module compatibility, preflight | DONE (prototype) | Edition and module decision paths tested |
| M1.1 | Removable `install/`, mandatory folder deletion gate, continuity docs | DONE (prototype) | Cleanup/ready/missing/invalid states tested via PHP + HTTP |
| M2 | Real browser installer: DB credentials, migrations, admin bootstrap, edition persistence, CSRF, signed state, retries/recovery | IMPLEMENTED; GitHub CI disposable MySQL PASS, cPanel pending | PHP/HTTP tests pass locally; disposable real-MySQL CI and cPanel integration remain unverified |
| M3 | Admin runtime: login, static roles/permissions and read-only dashboard | IMPLEMENTED; GitHub CI MySQL-backed HTTP PASS, cPanel pending | Authenticated dashboard; persistent sessions, CSRF/HTTPS/rate limits, revocation; installation gate before every route |
| M4 | Signed add-on package lifecycle + themes | PARTIALLY IMPLEMENTED (signed packages; signed CSS preview route) | Trusted publisher signature and asset hashes verified; admin upload/enable/disable; Lite/Pro and dependency guards. **Pending:** real MySQL-admin HTTP verification, executable plugin runtime, theme renderer, updater/rollback |
| M5 | Membership, content management, private galleries, consent and safety workflows | STARTED (draft schema and authorization predicate, not wired to runtime) | Authorized protected media, adult age/identity controls, consent and moderation features |
| M6 | Payment-provider integrations, renewals and entitlements | STARTED (generic verified-event inbox and HMAC utility, no processor integration) | Provider approval, idempotent webhooks and tested authorization/refunds |
| M7 | Production Pro agent, video/transcoding/streaming and realtime services | STARTED (HMAC job envelope contract only) | Authenticated production agent, worker health, access-controlled delivery and recovery tests |
| M8 | Release packaging, backups, updates, hosting certification | STARTED (developer ZIP packaging script, no signed updates/backups) | ZIP deploy on compatible shared hosting, VPS provisioning, restore and signed-release tests |

## Immediate next task

1. **Verify M2 on a disposable MySQL database** (CI is preconfigured in `.github/workflows/php-tests.yml`; see `docs/TESTING.md`). Test under Apache/cPanel as well as PHP's dev server. Fix any integration defects discovered before declaring M2 complete.
2. **Verify M3** authenticated HTTP login/logout with the new disposable MySQL suite and Apache/cPanel HTTPS hosting tests. Fix any discovered issues before production use.
3. **Verify M4 administrator package upload** on a disposable MySQL HTTPS/cPanel host. No executable extensions or live themes exist yet. Finish versioned module runtime, theme renderer, update/rollback, and trust-key administration before claiming M4 is complete.
4. Improve installer diagnostics, backup/recovery and add administrator MFA/recovery without changing mandatory `install/` cleanup.

## Explicit non-features

No functioning membership website, subscription billing, video streaming, real Pro provisioning, verified age/consent workflows, executable extension runtime, live theme renderer or production-ready update system exists yet. Admin login is implemented but real hosting verification is pending.

## Developer snapshot 0.5.0-dev additions

- Signed theme CSS delivery path (`/theme.css`) behind the installation gate, including runtime package integrity checks. Full frontend theme rendering is not implemented.
- Draft MySQL membership/content/performer-record/report tables and a default-deny entitlement predicate. Migration is not run by the existing installer pending disposable DB validation and recovery design. No public member routes.
- Billing inbox scaffold for verified third-party events and duplicate event IDs. No actual payment-provider adapter, webhook endpoint or settlement workflow.
- HMAC-signed Pro job envelope protocol with narrow job allowlist. No live worker, video transcoding or streaming implementation.
- Developer source-release packager; not a production installer package or updater.

**Milestones 4–8 are NOT complete.** Completion needs real MySQL/cPanel tests, usable galleries/membership UX, legal/compliance checks, payment provider approval and certification, production Pro worker and streaming, encrypted/verifiable backups, signed rollback-capable updates, security audit, load and hosting tests.

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
