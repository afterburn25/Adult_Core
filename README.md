## New in 0.8.9-backup-ops-dev — verified backup vault and maintenance operations

Private CLI tooling now verifies existing `.acfull` archives in place, copies them into a separately supplied restricted backup vault, rotates only old verified vault archives and preserves corrupted copies for operator review. `scripts/backup-job.php` can be scheduled **only after the operator has actually drained traffic, stopped uploads/writers and (on Pro) stopped workers**; it adds a 503 maintenance gate for new HTTP requests, but is not a substitute for quiescence. No cloud service or automated remote upload is claimed. See [`docs/BACKUP_OPERATIONS.md`](docs/BACKUP_OPERATIONS.md) and [`docs/PORTABLE_BACKUP_RECOVERY.md`](docs/PORTABLE_BACKUP_RECOVERY.md). Real shared-host/VPS disaster recovery and live Cajun Web Pro paid subscriptions remain unverified.

## Development encrypted full-site recovery — 0.8.8-backup-dev

New CLI-only `scripts/backup-site.php` creates an AES-256-GCM streaming `.acfull` archive of MySQL table schemas and rows plus private media, signed licensing/MFA data and settings; `scripts/restore-site.php` accepts it **only** on a clean, same-version source with a separately configured empty database. The original `install/` deletion rule, Lite/Pro license distinctions and private hosting requirements remain. Do not mistake this for approved production backup automation, online updates, or compliance readiness. Read [`docs/PORTABLE_BACKUP_RECOVERY.md`](docs/PORTABLE_BACKUP_RECOVERY.md) before using either command.


---

## Administrator MFA milestone — 0.8.7-mfa-dev

The owner can enroll standard authenticator-app (TOTP) MFA under `/admin/security`. Once enabled, `/admin/mfa` requires a fresh authenticator or single-use recovery code after password verification, including on Lite and Pro. Secrets are AES-256-GCM encrypted using the private application key and recovery codes are stored as keyed hashes; previous password-only sessions cannot stay privileged. This is a development feature, not an audited production release. **Existing sites must apply additive `/admin/setup` migration first; preserve tested off-server backups.** See [Administrator MFA guide](docs/ADMIN_MFA.md). Cajun Web Pro licensing/subscription catalog activation and production deployment remain pending.

## Subscription licensing lifecycle tests — 0.8.6.7 development

Adult_Core uses the same installer for Lite/Pro and one-time/monthly terms; the Cajun Web Pro product-specific key selects the installed edition. A new **local loopback HTTP issuer simulation** exercises all four SKUs, activation after a paid-through date, expiration, renewal/reinstatement, revoked domains, payment-service outages, cross-edition rejection, and same-edition plan changes. Run `python3 tests/cwp_subscription_contract.py`. The administrator license page explicitly warns that **changing a key does not cancel recurring billing**. No live Cajun Web Pro purchase or issuer installation was performed. See `docs/SUBSCRIPTION_ACTIVATION_TESTING.md` and `docs/CWP_CATALOG_SETUP.md`.

## License health and Cajun Web Pro catalog readiness (0.8.6.6 development)

Adult_Core uses a single installer with four different vendor-licensed product SKUs: Lite/Pro × one-time/monthly. An authorized site manager can open `/admin/license` and select **Check license now** to verify with Cajun Web Pro immediately, without displaying the stored key; confirmed denials block access and temporary issuer failures are distinguished from authorization. Owner-priced catalog creation, published software releases and actual subscription activation are still pending. See [Cajun Web Pro catalog setup](docs/CWP_CATALOG_SETUP.md).

## Lite → Pro in-place upgrade (0.8.6.5-upgrade-dev)

One Adult_Core installation remains on the original database and host; the Cajun Web Pro Pro-edition license, not a browser dropdown, authorizes the transition. An authenticated administrator opens `/admin/upgrade`, provides a separately purchased Pro one-time or monthly key, reenters their password, and confirms a verified **external** site+database backup. The site verifies the Pro agent and required host capabilities before changing the single installation's edition, cached license and signed state. No member, page, theme, moderation, or media records are migrated or deleted. Pro-only navigation remains **In development**; this is not production streaming support. Emergency rollback of an interrupted upgrade is CLI-only: `php scripts/recover-edition-upgrade.php --rollback`; the public site stays locked while the maintenance sentinel exists. This does not replace external backup/restore certification. See `docs/LITE_TO_PRO_UPGRADE.md`.

## 0.8.6.4-license-dev — one installer, license-selected Lite/Pro, monthly plans

Adult_Core now uses four product-specific Cajun Web Pro SKUs (Lite/Pro × one-time/monthly), with the **key determining the installed edition** before any database creation. Pro keys require verified Pro host capability; no silent downgrade. Lite administrators see disabled Pro-only features labelled **Pro Version Only**, plus an upgrade button temporarily linking to https://cajunwebpro.com. Signed data-only modules may carry their own licensed SKU and require a separate key to activate. This adds no live payment processing; Cajun Web Pro owner catalog registration and real subscription acceptance remain pending. See [licensing guide](docs/CAJUN_WEB_PRO_LICENSING.md).


## Cajun Web Pro mandatory licensing (0.8.6.3-license-dev)

First installation now requires a **Cajun Web Pro Adult_Core software license key** and the pre-registered installation hostname. The installer validates `product_id=SOFTWARE-ADULT-CORE` through Cajun Web Pro **before touching MySQL**. Installed sites periodically revalidate and block feature routes when the license is missing, revoked or unverifiable beyond a bounded outage grace. Signed installation cleanup remains mandatory, and authenticated license repair is at `/admin/license`.

**Vendor setup required:** The `Cajun_Web` repository has a functioning product-bound licensing API, but no Adult_Core catalog SKU was identified. An operator must first register `SOFTWARE-ADULT-CORE`, publish its product/release and issue/register an eligible customer license in Cajun Web Pro. See **[integration and security guide](docs/CAJUN_WEB_PRO_LICENSING.md)**. Do not use a made-up key or assume paid license checkout has been tested.

## 0.8.6.2-design-dev — live cutout drag and resize (2026-11-20)

In the authenticated **Design Studio** (`/admin/design`), choose an existing uploaded PNG/WebP cutout, then drag its image or **Move** handle directly on the theme background; drag the **↘** corner handle to resize with an immediate visual update. Horizontal/vertical/width sliders (with percentage readouts), arrow-key controls, reset, and touch-pointer support are provided. Save to persist relative coordinates in an additive MySQL migration and render the same percentage geometry on the public homepage **only if** the image has been explicitly declared suitable for public decorative use and the homepage is published. Applying `/admin/setup` after backup is required; the prior four built-in themes and their background styling are unchanged.

This does **not** add automatic background removal, public adult-content uploads, real billing, age-verification approval or production readiness. See [`docs/DESIGN_STUDIO.md`](docs/DESIGN_STUDIO.md).

# Adult Core — Self-Hosted Lite & Pro Membership Platform

**Verified 0.8.6-design-dev CI:** [run 44826489627](https://github.com/afterburn25/Adult_Core/actions/runs/44826489627) passed PHP 8.3 lint/unit/security tests, disposable MySQL installation and authenticated browser checks (including real PNG upload and private/public decorative-image gating) for source commit [`c427cf9`](https://github.com/afterburn25/Adult_Core/commit/c427cf957a132749555cf5f777419b024cf240b6). Apache/cPanel production deployment, real adult verification, payments and streaming are NOT certified.


**Version: `0.8.6-design-dev` (visual theme builder development preview, not a production release).** The 0.6 public page editor and 0.7 pending member-account workflows are implemented; 0.8 restricted-media authorization is a foundation only. Milestones 0.9 through 1.0 are **not complete**. See [release gates](docs/RELEASE_GATES.md) and [roadmap](docs/ROADMAP.md).

[GitHub: afterburn25/Adult_Core](https://github.com/afterburn25/Adult_Core)

**CI verification:** GitHub Actions [run 44777593038](https://github.com/afterburn25/Adult_Core/actions/runs/44777593038) passed PHP lint, all local-style UI tests and disposable-MySQL installer/admin/member browser integration checks for source commit [`f886e8684fa8be5c39ea953ff2c1373ea09bd937`](https://github.com/afterburn25/Adult_Core/commit/f886e8684fa8be5c39ea953ff2c1373ea09bd937). Production cPanel/VPS and security/compliance verification remain outstanding.

**Never deploy this release as a real adult content, membership billing or streaming website.** Payment integration, independent age verification, consent evidence review, complete external verification and consent review, Pro streaming, safe updates and production security are unfinished.

## New in 0.8.6-design-dev — Visual Design Studio

- Authenticated `/admin/design` provides four distinct built-in theme layouts, their own palettes, a customizable accent color, responsive live preview and click-to-edit **homepage headline and paragraph**. A plain-text fallback form remains accessible without JS.
- Administrators can upload their own **already-transparent PNG/WebP cutout photos**, or JPEG decorative photos; select image placement and scale. Uploads are restricted to 5 MiB / 4096 pixels and privately stored outside `public/`. Public delivery requires explicitly non-explicit, rights-cleared asset status, saved selection, and a published home page. No automatic background removal or adult identity/performer verification is implied.
- Run `/admin/setup` to apply additive migration `m11_visual_design_v1`, then open `/admin/design`. Built-in layouts are not PHP plugins or part of the signed CSS package runtime. Read [Design Studio guide](docs/DESIGN_STUDIO.md).
- The rest of the website remains a development preview: no live adult-media publishing, provider-approved payments, Pro streaming, or production 1.0 certification.

## New in 0.8.5-ui-dev — premium frontend and administrator redesign

- Responsive, brandable public landing page with actual site name, a CSS-only illustrated hero, refined navigation and clearer member entry points. Page titles and body text still come exclusively from published escaped plain-text content.
- Consistent admin sidebar, contextual topbar, truthful dashboard cards, workspace shortcuts, and a redesigned page editor. The existing moderation, package and setup tools use the same design shell.
- Member and admin sign-in pages use the same accessible auth design. No external fonts, JavaScript framework, image tracking, or CDN required (shared-host friendly).
- New CLI-independent UI checks: `php tests/ui_tests.php`; see `docs/UI_DESIGN.md` for design and preview information.

**Still not production 1.0:** administrator MFA, real independent verification/consent, payments, streaming, backup restore, and hosting certification remain incomplete. This update changes presentation, not authorization or entitlements.

## What's new since 0.5.0-dev

- `GET /` and `/p/{slug}` serve only published, escaped **plain-text** pages. Site owners can apply additive schema updates at `/admin/setup` and create/edit drafts at `/admin/pages` and `/admin/page`. Existing signed CSS themes can style these pages; arbitrary executable templates are never loaded.
- `/join`, `/members/login`, `/members` and POST-only `/members/logout` offer **pending** member accounts with separate sessions, password hashing, CSRF and throttling. Pending signups do not unlock content. Email verification and independent age verification are NOT integrated.
- A guarded image endpoint `/media/{id}` is present but strictly denies access without age evidence, media/content moderation approval, consent evidence (when required), and authorization. Private image storage stays outside `public/`. There is not yet an administrator upload/consent review workflow.
- Additive `MembershipSchema` and `MemberSchema`, test coverage for Lite+Pro job contracts and a production-readiness gate; authenticated administrators can initiate migrations on a test installation. They are not automatically enabled for existing installations.
- HMAC Pro job **receipt** supports deduplication but performs no processing or streaming. Generic billing-event receipt remains a scaffold with no actual processor connection.

## New in 0.8.3-dev — safety triage (development-only)

- `/admin/moderation` now provides private content draft creation, an HTML-escaped report queue, quarantine/reject actions and administrator review notes. All decisions are audited in `ac_moderation_actions` after applying `/admin/setup` again.
- `/members/report` accepts CSRF-protected member safety reports. Reporting automatically quarantines content and prevents serving associated images; closing a report does not restore access.
- **No media upload, production verification, performer approval, or publish button exists.** No customer adult content or payments should be launched. See [`docs/MODERATION.md`](docs/MODERATION.md).

## New in 0.8.3-dev — verification and release safety foundations

- An **opt-in, disabled-by-default** signed verification callback scaffold with replay/timestamp checks and durable receipts; no independent provider is connected yet. Revocations invalidate evidence. It never activates accounts or publishes content. See [`docs/VERIFICATION.md`](docs/VERIFICATION.md).
- The moderator can declare the number of performers and create individual **pending** verification records. The private-media guard denies unknown or incomplete performer counts. Real identity/consent documents must not be uploaded to this prototype.
- `EncryptedSnapshot` (AES-256-GCM), a **limited CLI encrypted database export**, and an offline signed release-manifest verifier are implemented and tested. Neither a complete backup/restore system nor a production updater exists. See [`docs/BACKUPS_AND_UPDATES.md`](docs/BACKUPS_AND_UPDATES.md).
- **0.9 billing, 0.10 video and 1.0 production release remain unfinished**, pending approved external providers and external deployment/security testing.

For PHP's built-in development server, use `php -S 127.0.0.1:8080 -t public public/router.php`. The custom router forwards dynamic CSS and image URLs through the same install/authentication gate that Apache handles using `public/.htaccess`.

## Development installation (test database only)

1. Put the project **above** your host's web directory. Point the document root exclusively at `public/`. `src/`, `install/`, `storage/` and `config/` must never be directly web-accessible.
2. Install with the browser wizard at `/install/`, which chooses Lite or verified Pro capabilities. Use a new disposable MySQL database and HTTPS.
3. Delete the entire `install/` directory **manually** after successful installation, otherwise all site and admin routes remain locked.
4. Sign in at `/admin/login`, and choose `/admin/setup` to apply additive website, member and private-content migrations. Back up the database first.
5. Use `/admin/pages` to edit the `home` draft and publish it only for safe, non-explicit holding-page content. Registration is a pending-account prototype, not a verified adult membership platform.

See `docs/TESTING.md`, `docs/SECURITY.md`, `docs/RELEASE_GATES.md`, and `AGENTS.md` before attempting deployment.

## Contributing / continuation

Use the source repository as the project history. Update `docs/ROADMAP.md`, `docs/SESSION_HANDOFF.md`, `docs/CHANGELOG.md`, `docs/DECISIONS.md` and tests with each meaningful change. Review `docs/RELEASE_GATES.md` before claiming a release is production-ready.

---

## Developer reference

The original foundation and prior milestones are preserved in `docs/CHANGELOG.md`, `docs/DECISIONS.md`, `docs/M3_RELEASE_NOTES.md` and `docs/M4_RELEASE_NOTES.md`. Read `docs/SESSION_HANDOFF.md` for the verified current state and `docs/RELEASE_GATES.md` for remaining production work.

## Verified CI — 0.8.3-dev moderation (2026-11-20)

GitHub Actions run 44733014757 passed PHP lint, moderation input validation, disposable MySQL installation and authenticated browser tests. Real Apache/cPanel hosting, identity/performer consent verification, payments and production streaming remain unverified.

## 0.8.3-dev: email confirmation

Member inbox confirmation is implemented using time-limited hashed one-use tokens and an optional private `config/email.php` shared-host `mail()` adapter. Apply `/admin/setup` after backup. Email confirmation **does not** activate members or verify adult age. GET confirmation links never consume tokens; CSRF-protected POST completes ownership confirmation. See [docs/EMAIL_CONFIRMATION.md](docs/EMAIL_CONFIRMATION.md). Admin MFA, provider approval, compliant adult publishing, real billing, production video, and release 1.0 remain blocked.

## 0.8.4-dev: member password recovery

Members with an independently confirmed email address can request a one-use password reset from `/members/recover`; fixed-origin email delivery uses optional private `config/email.php`. Recovery requests do not disclose account existence, and reset links expire after 30 minutes. Completing a reset changes only the password and invalidates existing member sessions—including sessions requesting `/media/{id}`. Back up the DB and re-run `/admin/setup` to add `m10_member_recovery_v1`. Read [docs/PASSWORD_RECOVERY.md](docs/PASSWORD_RECOVERY.md). Admin MFA/recovery and real delivery certification are **not complete**. The application remains a development preview, not a verified production membership website.


## Verified GitHub CI — 0.8.4-dev password recovery (2026-11-20)

- Source fix commit: [`e70582930faf914757ec3829c9251c9e54fed262`](https://github.com/afterburn25/Adult_Core/commit/e70582930faf914757ec3829c9251c9e54fed262).
- [GitHub Actions run 44765614968](https://github.com/afterburn25/Adult_Core/actions/runs/44765614968) passed: PHP lint and unit tests, HTTP smoke, disposable MySQL installation/recovery tests and browser session revocation tests.
- The first run caught a browser test-order issue: reset rotates CSRF, so the replay test now uses a newly acquired CSRF token to independently assert that the reset token cannot be reused. This is tested, not a production security audit.
- Real cPanel, email deliverability, identity/age verification, payment processing, Pro streaming, signed restore/update workflow, independent review and production 1.0 remain incomplete.

### 0.8.6.1-design-dev theme maintenance

The four original built-in theme selections remain unchanged, with no duplicates. The Design Studio provides an explicit **No cutout — keep theme background** selection and a clear upload placeholder. Theme layout/background styling continues to render when a user does not select an image. Concept screenshots with hero models removed are provided as a **separate reference bundle**, not automatically exposed as real website media.


## Verified GitHub CI — 0.8.6.2 design cutout controls (2026-11-20)

Source commit [`bb06da70cfcfce2e18b012c139a0cf37f37980c0`](https://github.com/afterburn25/Adult_Core/commit/bb06da70cfcfce2e18b012c139a0cf37f37980c0) passed [GitHub Actions run 44830354150](https://github.com/afterburn25/Adult_Core/actions/runs/44830354150) on PHP 8.3 and disposable MySQL. PHP lint and all registered test suites passed, including the new repeat-safe cutout migration, persisted X/Y/Width, invalid POST refusal, and public CSS geometry checked through authenticated HTTP. The local Chromium pointer interaction and mobile fixture also passed. This does not certify Apache/cPanel, actual customer assets, real verification/payment/Pro services or a production 1.0 release.
