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
