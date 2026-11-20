# Adult Core — Self-Hosted Lite & Pro Membership Platform

**Version: `0.8.2-dev` (development preview, not a production release).** The 0.6 public page editor and 0.7 pending member-account workflows are implemented; 0.8 restricted-media authorization is a foundation only. Milestones 0.9 through 1.0 are **not complete**. See [release gates](docs/RELEASE_GATES.md) and [roadmap](docs/ROADMAP.md).

[GitHub: afterburn25/Adult_Core](https://github.com/afterburn25/Adult_Core)

**CI verification:** [GitHub Actions run 44714670111](https://github.com/afterburn25/Adult_Core/actions/runs/44714670111) passed with disposable MySQL, but not real cPanel/VPS.

**Never deploy this release as a real adult content, membership billing or streaming website.** Payment integration, independent age verification, consent evidence review, complete external verification and consent review, Pro streaming, safe updates and production security are unfinished.

## What's new since 0.5.0-dev

- `GET /` and `/p/{slug}` serve only published, escaped **plain-text** pages. Site owners can apply additive schema updates at `/admin/setup` and create/edit drafts at `/admin/pages` and `/admin/page`. Existing signed CSS themes can style these pages; arbitrary executable templates are never loaded.
- `/join`, `/members/login`, `/members` and POST-only `/members/logout` offer **pending** member accounts with separate sessions, password hashing, CSRF and throttling. Pending signups do not unlock content. Email verification and independent age verification are NOT integrated.
- A guarded image endpoint `/media/{id}` is present but strictly denies access without age evidence, media/content moderation approval, consent evidence (when required), and authorization. Private image storage stays outside `public/`. There is not yet an administrator upload/consent review workflow.
- Additive `MembershipSchema` and `MemberSchema`, test coverage for Lite+Pro job contracts and a production-readiness gate; authenticated administrators can initiate migrations on a test installation. They are not automatically enabled for existing installations.
- HMAC Pro job **receipt** supports deduplication but performs no processing or streaming. Generic billing-event receipt remains a scaffold with no actual processor connection.

## New in 0.8.2-dev — safety triage (development-only)

- `/admin/moderation` now provides private content draft creation, an HTML-escaped report queue, quarantine/reject actions and administrator review notes. All decisions are audited in `ac_moderation_actions` after applying `/admin/setup` again.
- `/members/report` accepts CSRF-protected member safety reports. Reporting automatically quarantines content and prevents serving associated images; closing a report does not restore access.
- **No media upload, production verification, performer approval, or publish button exists.** No customer adult content or payments should be launched. See [`docs/MODERATION.md`](docs/MODERATION.md).

## New in 0.8.2-dev — verification and release safety foundations

- An **opt-in, disabled-by-default** signed verification callback scaffold with replay/timestamp checks and durable receipts; no independent provider is connected yet. Revocations invalidate evidence. It never activates accounts or publishes content. See [`docs/VERIFICATION.md`](docs/VERIFICATION.md).
- The moderator can declare the number of performers and create individual **pending** verification records. The private-media guard denies unknown or incomplete performer counts. Real identity/consent documents must not be uploaded to this prototype.
- `EncryptedSnapshot` (AES-256-GCM), a **limited CLI encrypted database export**, and an offline signed release-manifest verifier are implemented and tested. Neither a complete backup/restore system nor a production updater exists. See [`docs/BACKUPS_AND_UPDATES.md`](docs/BACKUPS_AND_UPDATES.md).
- **0.9 billing, 0.10 video and 1.0 production release remain unfinished**, pending approved external providers and external deployment/security testing.

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

## Verified CI — 0.8.2-dev moderation (2026-11-20)

GitHub Actions run 44733014757 passed PHP lint, moderation input validation, disposable MySQL installation and authenticated browser tests. Real Apache/cPanel hosting, identity/performer consent verification, payments and production streaming remain unverified.

Verified PHP/MySQL CI: [run 44738983959](https://github.com/afterburn25/Adult_Core/actions/runs/44738983959) (0.8.2-dev, not production certification).
