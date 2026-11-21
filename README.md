## Member lookup without email URLs (development)

**Admin → Members** now includes a private POST-only exact email search. Search results are read-only; account safety changes continue to require the existing 30-minute manager authorization window, and no identity, subscription or publishing approvals are available.

## Owner dashboard overview (development)

Your self-hosted administrator dashboard now summarizes pending member accounts, unpublished site pages, open reports and submitted creator text plans from the **local** database. The counters are read-only, and features not yet migrated are labeled **Not configured**. No member activation, payment authorization or adult publishing is implied.

## Sign out every member device (development)

Authenticated members can use **Account → Sign out of all devices**, re-enter their existing password, and invalidate all member sessions including the current browser. The current password remains valid for a fresh sign-in; this never grants paid or age-verified access.

## Member session security (development)

Member account security includes a transactional all-device session revocation capability. It requires a current password and invalidates existing member sessions without changing the member's password, age/consent, subscription or protected-media access. The protected browser form is part of the next feature increment.

## Export redacted Site Health checks (development)

Owners can download a local, read-only JSON self-check report from **Admin → Site health** without revealing member or licensing secrets. The export explicitly states that it does not certify production launch or authorize adult-media release.

## Member password change (development)

Signed-in members can choose **Change your password** on their local account page. They must enter the current password and confirm a new password. All existing member sessions and reset links are invalidated after a successful change. This never verifies age, activates paid access, changes the member's pending state or publishes content.

## Review local member safety (m21 development)

An authenticated site owner can open **Admin → Members → Safety review** to suspend a local member or restore a suspended account to **pending only**, with a retained private reason and security audit. The existing secure session avoids repeated passwords for ordinary changes; a high-risk member status change uses the same short manager-authentication window as staff operations. No age/consent or paid membership approval is possible here.

## Local member safety controls (m21 development)

The local account security backend supports owner-initiated member suspension and a deliberately limited restore to **pending** only. Every transition revokes previous member sessions and password-reset tokens, and retains a private audit. Apply the additive migration under **Admin → Setup** after database backup. This is not member age verification, billing fulfillment or an adult-media activation path.

## Find a local member (development)

Use the numeric account ID field under **Admin → Members** for a read-only exact lookup, optionally filtered by locally recorded status. Email addresses stay out of search URLs; the lookup never changes age/consent, payment, accounts or private media permissions.

## Page history beyond 20 edits (development)

Owners can now browse older saved page revisions using **Older page revisions** in the page editor. Each version can be previewed privately; restoring text always produces a new unpublished draft, preserving history and protected-media restrictions.

## Private moderation history navigation (development)

Authenticated moderators can now inspect older recorded moderation decisions 50 at a time. No approval, public publishing, external identity verification or payment entitlement is added; the audit remains private to each installed site.

## Site Health (local diagnostics only)

Under **Admin → Site health**, a site owner can inspect local, read-only installation and environment checks. This does not reveal credentials, contact the vendor or certify adult-content release readiness; real hosting, merchant billing, age/performer consent and independent security approval are still required.

## Pending image moderation beyond 80 records (development)

Authenticated moderators can now page through pending, private draft images without losing older items from the review list. Preview access is still separately checked and no upload, review or publication safety gate has been opened.

## Pending performer-record review (development)

Authenticated moderators can navigate older performer-roster status summaries 50 at a time, without receiving any local identity/consent approval capability. Real independent verification and release safeguards remain mandatory.

## Moderation reports across large queues (development)

The protected moderation dashboard now keeps open reports first while offering Older reports navigation when the list exceeds 50 entries. Browsing report history never approves, publishes or restores adult content; real age/consent and payment integrations remain blocked.

## Private moderation queue navigation (development)

The authenticated local moderation dashboard now pages through private content records 50 at a time. Older drafts and rejected records remain reviewable instead of disappearing from a fixed first-page list; this provides **no approval or publication capability**, and external verification and payments remain blocked.

## Find drafts and published pages (development)

**Admin → Pages** now filters by Draft or Published and can combine that with title/slug search across multiple result pages. This is an owner-only read-only list; status filtering never publishes material or grants protected adult-media approval.

## Read-only member directory (development)

**Admin → Members** uses bounded 50-row pages and a local account-status filter. Owner/admin sessions can view locally recorded membership accounts without exposing credentials or granting age verification, billing entitlements, or private media access.

## Unsaved page editing preview (development)

**Admin → Pages → Edit** now shows an immediate, browser-only plain-text preview of the headline and body while typing. It never saves, uploads, grants access or publishes a page; click **Save page** and use the protected saved preview when ready. HTML is treated as plain text.

## Edit the self-hosted site name (m20 development)

**Admin → Site identity** allows owners to change the public site name using the existing secure admin session and CSRF protection, without reinstalling or entering their password for routine changes. Concurrent stale edits are rejected. Run **Admin → Setup** after a verified backup to apply the additive m20 revision marker. License product, domain, protected adult content, payments and verification are not changed.

## Private page history (m19 development)

Admins can view the last 20 saved revisions of each local website page and restore an earlier title/body as a **new unpublished draft**. Original history is retained, and restoring never publishes or rewrites the URL/menu settings. Apply the additive migration from **Admin → Setup** after a verified database backup. Existing sites can only backfill their current revision; unavailable historical edits cannot be recreated. No protected adult media, billing or age/consent approval is implied.

## Homepage information cards (m18 development)

Design Studio owners can edit the three public homepage information cards as bounded, safely escaped text. Apply the additive **Admin → Setup** migration after a verified backup. The member/media/verification/payment release gates do not change.

## Search your saved pages (development)

Admin → Pages has a literal title/slug search across local saved drafts and published text pages, with bounded 50-row pages and retained filters. Searching cannot publish content or bypass site permissions.

## Duplicate a page without publishing (development)

An authenticated owner can copy a saved plain-text public-site page from its editor into a new **unpublished and unlisted** draft with a different URL slug. Stale source revisions are refused, and the original is never modified. Copying cannot publish media or change member billing or age/consent status.

## Clear owner migration checklist (development)

Admin → Setup now distinguishes installed from pending additive local schemas—including page editing, creators, moderation and member account foundations—without implying production approval. Back up the website before running migrations. Real provider, hosting and security release workstreams remain open.

## Page dashboard navigation (development)

Local owners with over 50 saved pages can browse all their drafts and published pages through bounded, numeric keyset pagination under **Admin → Pages**, rather than losing older records from the page editor list. No public authorization, billing or content verification rules change.

## Local public-page navigation (m17, development)

The authenticated Pages editor can hide a published page's menu link or assign a menu order (0–999); saved pages are still safely escaped and a hidden link **does not protect the direct public URL**. Draft status is required to unpublish. Apply the additive **Admin → Setup** migration after backup. Site owners on Lite/Pro manage their own navigation; this is not SaaS, adult media publication or release certification.

## Safer concurrent page editing (m16, development)

The public-page editor and homepage Design Studio now require a current revision token for each existing page. An older browser tab cannot silently overwrite a newer title, body, published/draft status or visual design changes. Apply the additive **Admin → Setup** migration (after backup) before editing existing pages. No age/consent verification, payments, public adult media or production signoff is implied. See [site release acceptance](docs/WORKSTREAM_ACCEPTANCE.md).

## Private creator feedback timeline

Each local site's text-only creator review retains separate private feedback snapshots by submitted draft revision. Only that site's enabled creator and owner/admin can inspect their permitted review history; no SaaS creator accounts or public media are involved. Apply the additive site-setup migration first. See [Local creator management and review](docs/LOCAL_CREATORS.md).

## Local creator text-plan review, without public publication

Owner-provisioned creators can submit private **text-only** draft plans to the moderator of that particular self-hosted website. Moderators can request changes or reject (no approve/publish button). Creators see private feedback in their own account, make revisions and resubmit. It does not provide an adult media approval, payment, identity/age or performer consent workflow. Apply the local creator review migration under **Admin → Setup**. See [Local creator workflow](docs/LOCAL_CREATORS.md).

## Local creator planning — per installation, not SaaS

Each site's owner/admin can enable private planning for an existing confirmed member at `/admin/creators`. That member then manages text-only private drafts at `/members/creator`. This does not approve creators, publish any content or share accounts between websites. [Local creator setup and permission boundaries](docs/LOCAL_CREATORS.md).

## Persistent signed-in administrator sessions

The admin browser keeps only a secure opaque session cookie, not a password. It survives browser restarts for up to seven days (24-hour idle expiry). Staff actions are unlocked for 30 minutes after sign-in or one manager-password confirmation, without repeating the password on every edit. Signing out and account revocation still terminate access. See [staff session and lifecycle guidance](docs/STAFF_MODERATION.md).

## Staff and moderation-team accounts — development

Administrators can now manage restricted moderators under `/admin/staff` with password re-verification, suspension/credential rotation and protected audit trails. Moderators can change their initial password in `/admin/security`; old session cookies and pending MFA challenges are invalidated by staff status/credential changes. **No automated staff-email identity checks or public adult-content approvals are provided.** See [staff setup and safety runbook](docs/STAFF_MODERATION.md).

## HTTPS staging host acceptance — 0.8.12 development

Run the new **read-only remote HTTPS probe** only on an operator-controlled staging host, before and after installation. It checks TLS-verified public routing, installer cleanup, security headers and private-path disclosure, with explicit opt-in and protections against private/loopback DNS targets. It never reads private response bodies or handles licenses/payment credentials. `python3 tests/staging_host_audit_tests.py` verifies its safety rules and a real local PHP preinstall route round trip. This **does not certify** actual cPanel/VPS hosting or monthly billing. See [`docs/STAGING_HOST_HTTP_ACCEPTANCE.md`](docs/STAGING_HOST_HTTP_ACCEPTANCE.md).

## Development host deployment preflight — 0.8.11

Run `php scripts/deployment-preflight.php --document-root=/actual/host/document/root/public --expected-edition=lite --phase=preinstall` (use `pro` for a VPS, or `installed` after installation). The new read-only triage checks unsafe web-root placement, private tree exposure, PHP/server capabilities, Pro-agent authentication and signed-edition consistency. **The Cajun Web Pro key still selects the real edition**; the command never installs, issues, upgrades or bills a license. Even a successful check does **not** certify HTTPS, actual cPanel/FPM, Pro workers, backups or adult-content compliance. See [`docs/DEPLOYMENT_PREFLIGHT.md`](docs/DEPLOYMENT_PREFLIGHT.md).

## Read-only Cajun Web Pro staging audit (0.8.10 development)

For approved staging-only verification of already-issued Lite/Pro one-time and monthly license keys, `scripts/audit-cwp-staging.php` performs three bounded HTTPS product/domain checks without issuing a key, enrolling or cancelling a subscription, or charging anything. It requires a private key file outside the repo and explicit opt-in; see `docs/CWP_STAGING_PREFLIGHT.md`. Live Cajun Web Pro catalog onboarding and merchant sandbox acceptance are **still pending**.

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


**Current development version: `0.8.12-host-https-audit-dev` (not a production release).** The 0.6 public page editor and 0.7 pending member-account workflows are implemented; 0.8 restricted-media authorization is a foundation only. Milestones 0.9 through 1.0 are **not complete**. See [release gates](docs/RELEASE_GATES.md) and [roadmap](docs/ROADMAP.md).

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
