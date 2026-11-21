## Private page revision history with draft-only restore (m19) — 2026-11-21

A repeatable additive m19 schema preserves page title/body, status, slug, menu visibility/order and revision snapshots in a local `ac_page_history` table. Existing installations backfill **only the current known state**; prior overwritten revisions cannot be reconstructed. Every subsequent page create/edit and private page duplication writes the snapshot in the same DB transaction, including Design Studio homepage edits through savepoints; if the snapshot fails the edit rolls back. The owner/admin editor lists at most 20 recent version summaries and permits a CSRF-protected, revision-checked restore of an earlier title/body. **Restoration always creates a new private draft** and never restores an earlier published state, URL slug or menu flag. The owner must separately publish again.

Site setup requires a verified backup before the additive migration; page edit routes stay unavailable until the marker is installed. Signed Lite/Pro source ZIP checks, unit/database, real authenticated HTTP, MySQL/MariaDB and encrypted backup/restore regressions cover version history, stale/replayed restores, private 404 after restore, and local recovery parity. No member entitlement, adult-media publishing, independent age/consent or merchant payment gate is enabled. Actual host rehearsals and independent release acceptance remain outstanding.

---

## Protected homepage route and unique active navigation — 2026-11-21

The site-local page repository now treats `home` as an immutable, reserved slug: the seeded homepage cannot be renamed, another page cannot claim `home`, and an unpublished duplicate cannot reuse it. Version-checked edits to the real homepage title, content, draft/published status and navigation remain supported. The owner editor shows its special slug read-only but the server enforces the restriction independently. Public pages and authenticated draft previews now mark active navigation by the route slug, never by a potentially duplicated title; at most one public-page link receives `aria-current="page"`. Unit, disposable MySQL/MariaDB and authenticated HTTP regressions cover denied mutations and duplicate-title links. Existing one-package Lite/Pro licenses, adult verification and payment release gates are unchanged.

---

## m18 homepage-card encrypted restore parity — 2026-11-21

The disposable MySQL/MariaDB encrypted backup and fresh-database restore regression now records all six per-site homepage card fields, including safe escaped-text examples and the m18 schema marker. It asserts byte-for-byte value parity after restoring the existing .acfull archive, alongside creator feedback, private assets, license configuration, site pages and menu state. This is only test-environment recovery; real cPanel/VPS restoration and independent launch certification are still required.

---

## Owner-editable homepage information tiles (m18) — 2026-11-21

The existing three public homepage information tiles now use a site-local additive m18 Design Studio migration instead of uneditable promotional copy. Each card has a required bounded UTF-8 heading and body, validated and HTML-escaped by the public renderer. The authenticated Design Studio exposes six fields alongside the existing theme controls, with CSRF and homepage revision checks; a stale homepage save still rolls back all visual settings. Legacy programmatic saves that omit card fields preserve existing values. The original three texts migrate as defaults and one unified Lite/Pro source ZIP requires the schema.

This is non-explicit **public informational text** only, not a content publish button, paid membership grant, age/performer consent assertion or permission to serve private media. Local unit, MySQL/MariaDB, ZIP and HTTP tests cover persistence, validation and escaping. Real hosting and production release certification remain pending.

---

## Bounded local page search across saved drafts — development (2026-11-21)

The owner-only Admin → Pages view now supports literal title/slug search across saved pages, including drafts beyond the first 50 rows. The query is retained during keyset pagination and restricted to 80 valid UTF-8 bytes without control characters. Prepared SQL substring matching does not interpret wildcard characters. Search never changes publishing, private media, license, creator or membership permissions.

Disposable SQL and authenticated HTTP tests cover exact matches, invalid controls, full 51-result pagination and privacy. This is a local site-management feature, not an adult payment or compliance release gate.

---

## Safe per-site page duplication into unpublished drafts — 2026-11-21

Local owners/admins may now copy an existing, saved public-site plain-text page into a new **draft-only** page with a separately supplied lowercase slug. This uses the protected page editor's current session, role and CSRF checks, locks the source row and rejects a stale revision. The copy contains only the original safely escaped title/body, starts with revision 1 and an unlisted menu state, and cannot be publicly accessed until a separate owner publishing action. Copying leaves the original page, menu, protected media, member accounts and creator records unchanged.

Disposable SQL and authenticated HTTP tests check duplicate success, stale source, malformed slug, CSRF and public 404. This does **not** copy media, certify customer content, integrate payments or permit release of adult materials.

---

## Encrypted recovery regression for m16/m17 page data — 2026-11-21

The disposable MySQL/MariaDB full backup and empty-database restore test now covers the new page revision and navigation migrations. It backs up a published but unlisted page plus a versioned homepage, then checks exact restored row snapshots, migration markers and the distinction between published URLs and visible menu links. Existing creator feedback, media, license, installer and archive-integrity checks remain. This is local CI evidence, not independent real-host disaster recovery or adult-content production certification.

---

## Bounded page-management pagination — development (2026-11-21)

The site owner/admin public-page dashboard now uses 50-row ID keyset pages instead of silently hiding pages beyond a fixed 100-row list. The older-pages link is numeric and bound as a SQL parameter; invalid cursors receive HTTP 422 and never expose public or private content. A return-to-newest link keeps navigation simple. The underlying public website still serves only explicitly published, escaped plain-text pages; hiding a menu link does not unpublish a page. Existing shared-host Lite and VPS Pro software distribution and license selection are unchanged.

Disposable MySQL/MariaDB assertions create 51 additional saved drafts plus the homepage and an existing page, visit both slices and verify no duplicates or omissions. Real HTTP tests deny a malformed cursor. Page-list paging is local administration only, not an adult upload, verification, billing or production-readiness milestone.

---

## Owner-controlled public page navigation — m17 development (2026-11-21)

A repeatable, additive m17 migration adds local per-page menu visibility and a bounded 0–999 sort position. The authenticated Pages editor can hide an individual *published* page's navigation link or order visible links without changing its URL, text, membership access or publication state. Unlisted published pages remain accessible by direct URL—**menu hiding is not privacy**; setting a page to Draft is the way to unpublish it. The Home link remains fixed. Existing pages default to visible, sorted as before when assigned the default order, and sites not yet migrated retain their old published menu until local Site setup runs.

The Design Studio carries forward existing menu flags when saving homepage text and rejects stale page revisions. Both Lite and Pro share the same migration, installer and license key model. SQL and authenticated HTTP tests cover migration idempotency, unlisted/direct-access behavior and listing again. This is public plain-text navigation only; no private media/creator publishing, age/consent certification, merchant billing or production hosting release gate is opened.

---

## Optimistic public page and Design Studio revision protection — development (2026-11-21)

The repeatable additive m16 migration adds a monotonic `ac_pages.revision` integer, including for legacy pages. Owner/admin page edits require the revision displayed when the editor opened; the repository atomically increments it only if it still matches. A stale tab or missing revision cannot overwrite newer text, slug or draft/published status. Visual Design Studio also carries this revision and rolls back theme/image placement changes if the homepage text was edited since the form opened. Both interfaces ask to reload rather than silently lose updates. Install the new revision column via **Admin → Setup** after a verified backup; public pages continue rendering from the existing safe plain-text templates and only published records. Lite/Pro license entitlements, age/performer consent, protected media and payment release gates are unchanged.

Disposable SQL/HTTP regressions verify idempotent upgrades, stale-form denial, unchanged unpublished page privacy, Design Studio rollback and latest-revision success. Real cPanel/VPS, independent adult identity/consent providers, merchant billing and production certification remain incomplete.

---

## Verified m14 → m15 feedback migration — development-only

On upgrade, a current request-changes/rejected draft note is preserved in the private feedback history only when the *last* m14 numeric event proves the same decision, actor and review time. Older overwritten notes and data without a matching event are not reconstructed. The unique (draft, submitted revision) constraint and INSERT IGNORE make repeated migration calls idempotent. Disposable SQL tests cover both provenance-backed import and refusal to attribute unproven legacy notes.

---

## Local private creator feedback timeline — internal development acceptance

For creator text plans, each new local decision now retains a separate private reviewer note with submitted revision, actor and time, and a numeric-only global audit event. Member access is restricted to an active email-confirmed local creator's own drafts; owner/admin can inspect site-local retained notes even if the creator is suspended. No unverified staff member, third-party tenant or public route can expose the timeline. Private notes are HTML-escaped and capped at 500 bytes, and queries have a 100-entry limit. Additive m15 migration and encrypted backup/restore regressions are covered in disposable CI. This does not close performer identity/consent, adult merchant billing, real-host cPanel/VPS installation, moderation publication or production legal/security signoff.

---

## Private local creator text review — not publish acceptance (2026-11-21)

The site-local text workflow has a private `submit → changes/rejected` moderator cycle. The enabled creator can edit only unsent or returned-for-changes plans and must save a revision before resubmission. Active local owner/admin/moderator permission, CSRF, no-store rendering, escaped feedback, optimistic concurrency and numeric-only draft action events govern the process. Critically, there is no `approved` transition, public ac_content/ac_media mutation, adult age/identity/performer-consent verification or paid access. This does **not** satisfy public creator media review/publish or commercial release gates and must not be used as certification of a creator or performer.

---

## Self-hosted creator planning permission — work-in-progress

An individual site owner/admin may grant private creator planning to a confirmed **member of that specific installation**. No central creator application or vendor-managed account exists. CSRF and current local permissions protect grants/suspensions; optimistic versions prevent lost status/draft edits. The creator uses the member login and sees only their own text-only private drafts, not public galleries. Audit rows contain only numeric actor/creator IDs and action types. These controls are tested in disposable MySQL/MariaDB and authenticated HTTP suites. They do not meet public creator upload, independent legal age/consent, malware/media review, publish/restore, adult payment or live-host acceptance criteria.

---

## Persistent bounded admin sessions and staff step-up usability — development only

The private admin session now supports a seven-day HttpOnly/SameSite Strict cookie (Secure on HTTPS), with a server-side 24-hour inactivity limit and seven-day absolute limit; logout and revocation remain immediate. The cookie contains only the random PHP session ID. Routine changes work across page reloads and browser restarts without retyping the password. A manager password verified at login, or once after expiry, opens a 30-minute staff-change window; staff creation/suspension/restoration still require role authorization, CSRF and server-side account checking, but not repetitive password submission on every action. Higher-risk workflows retain their own independent checks. This is not an infinite admin session or production security signoff.

---

## Restricted staff provisioning and session revocation — local development only

After `/admin/setup` additive migration, a manager can create only a restricted `moderator` identity on `/admin/staff`, suspend it and restore it with a fresh password. Every write requires manager password re-verification and CSRF, is attempt-limited and produces a numeric-only private audit record. A moderator can change their own password using the same active session/MFA gate. Session epochs increment on suspension, restore and password change, preventing the resurrection of old authenticated cookies or MFA challenges. The UI cannot promote staff, edit member status, alter license editions, publish media or grant paid entitlements.

This **does not** close staff identity verification, approved notification/delivery, end-to-end production permissions, vendor-verification, legal privacy or real-host security acceptance. The full creator safety and release workstreams remain open.

---

## Role-guarded private draft detail — development only

The moderation workspace now links unpublished, private content to `/admin/moderation/draft/ID`, a read-only, noindex/no-store protected detail view. It displays only a bounded queue of numeric pending/rejected image references, consent/identity *status cues*, and escaped private audit notes; it does not deliver private keys or original performer evidence. Individual pending-image preview remains restricted to a still-pending private draft and rechecks permission per byte request. This does not add a moderation approval/publish path or verify lawful consent; workstreams #3, #5 and #10 remain open.

---

## Restricted moderator role workflow — local development only

An independently provisioned active `moderator` account can now sign in and access a restricted navigation limited to content moderation and its own MFA/security page. Server role checks still deny manager-only site, membership tiers, licensing, modules, design and upgrades. The moderation repository checks active current role for each mutation and preserves default-deny image visibility; only pending private drafts can be staged, never published or approved. Disposable SQL and separate-cookie-jar HTTP checks verify role authorization, CSRF and restricted navigation. No owner-side staff invitation/approval or performer consent delegation is implemented; independent least-privilege review remains required.

---

## Read-only member record status — development-only member UX

Signed-in members can now see only their own locally persisted subscription state/tier/date fields on `/members`. A locally recorded `active` membership is clearly NOT proof of payment or verified entitlement, and disabled new catalog tiers remain non-eligible in the existing `Entitlements` policy. The page contains no checkout, cancellation, subscription-grant or provider callback function; it does not reveal billing tokens. Member-ID-bound queries, escaped renderer output and non-disclosing empty state are exercised in unit, disposable MySQL and authenticated HTTP tests. Real processor-approved billing, renewal, cancellation, refund/chargeback and fulfillment remain open in workstream #4.

---

## Saved draft page preview — development only

The website page builder now links to a private `/admin/page/preview?id=ID` endpoint. This admin-authenticated, no-store and noindex read-only route displays a saved draft with the same escaping, layout and safe imagery conditions used by the public renderer, plus a prominent admin-only preview notice. Anonymous requests do not receive draft content, and `/p/slug` continues to refuse unpublished pages. This is a useful workstream #8 increment, not completed visual builder, mobile/accessibility certification or permission to publish adult media.

---

## Private draft staging and tier label configuration (2026-11-21 development increment)

New private, unreviewed draft actions support title edits, preparation of future tier assignments and **individual pending-image rejection**. Moderator and admin authorization, CSRF, locked draft/asset status checks and stale-edit rejection remain mandatory. Rejection removes preview eligibility but retains protected file records for lawful review; it does not delete evidence. Moderators may read an escaped, bounded decision history for a selected content ID, and may choose pending drafts by name for staging/roster setup.

The membership catalog UI `/admin/tiers` creates and renames names and stable IDs only. All new catalog rows start `is_active=0` to avoid accidental paid entitlement authorization. Draft tier assignment is preparatory and **does not** switch visibility, approve the draft, create a member subscription, confirm payment or charge a card. Only approved adult-content merchant integration and separately reviewed provider fulfillment could justify future activation. The four Cajun Web Pro *software license SKUs* and the one Lite/Pro installer are unrelated and unchanged.

Workstreams #3, #4, #5, #8 and #10 remain open. Do not count these interface and local test contracts as production payment, identity/consent, content-publication or real-host acceptance.

---

## Legacy image-byte and metadata audit safeguard (development only)

Member gallery bytes, moderator draft previews and public decorative images now undergo an additional MIME, size and conservative metadata-container verification at delivery. This protects against old image records that were written before upload screening existed, but it does not turn synthetic approvals into genuine entitlement or legal consent. Operators can request a non-mutating, privacy-preserving scan with `php scripts/audit-private-media.php --metadata --json`. The opt-in scan reviews at most 100 existing media files / 64 MiB, and reports `metadata_rejected` or `metadata_truncated` rather than exposing private filenames or silently deleting media. An incomplete scan is not a green release signal. Workstreams #3, #5, #9 and #10 remain open for real providers, full rights/retention lifecycle, safe re-encoding/malware scanning, actual host permission testing and independent certification.

---

## Metadata-bearing image intake protection — development only

The private-draft and Design Studio HTTP image upload paths now reject common EXIF/GPS/XMP/ICC text and unsupported PNG/JPEG/WebP metadata/container structures before storing bytes. Image content is **not** transcoded or scrubbed: callers must submit a metadata-free export. Privacy-sensitive sidecars or alternative channels are not exhaustively eliminated. Private storage symlinks are refused during upload/lookup/delivery. Unit and HTTP fixtures exercise these denials, but the production acceptance obligations in workstreams #5 and #10 **remain open**, including server-supported safe media re-encoding and malware scanning, independent provider/performer rights review, real host permission audits and lawful data-retention procedures.

---

# Adult_Core — ten-workstream implementation and release acceptance

**Status: development. Production launch is blocked.** The unified Lite/Pro application remains one installable source package, with Cajun Web Pro license-key selection. This document is a release engineering checklist, **not** a substitute for an external signoff, a hosting test or a merchant/identity provider contract.

Show the canonical machine-readable blockers from the project root:

```sh
php scripts/release-matrix.php --json
php scripts/release-check.php
```

Both intentionally exit nonzero while production is not certified. Never bypass the checks by editing status JSON, creating fake keys or injecting an administrator approval.

| # | Workstream | Development progress | Acceptance evidence still needed |
|---|---|---|---|
| 1 | Cajun Web Pro software sales/licensing | Four exact license SKUs, one installer, domain-bound edition checks and local subscription simulations | Owner-approved catalog products/pricing, real staging entitlements, sandbox checkout/renewal/cancel/failure, correct domain/SKU rejections |
| 2 | Lite cPanel and Pro VPS | Capability-aware browser installer, CLI host preflight and HTTPS private-path checks | Two real isolated disposable hosts, actual DNS/TLS/Apache document root and installed Lite/Pro, Pro background service proof, failed-host rejection |
| 3 | Identity and performer consent | Signed assertion contract, private hashed evidence receipts, default-deny performer roster and admin evidence summary | Contracted age/identity provider integration, performer consent and records policy, actual provider lifecycle, jurisdictional legal review |
| 4 | Member billing | Verified-event inbox and conflict-replay-denying MySQL test; paid access policy defaults to deny | Approved processor, provider-specific signed webhooks, active subscriptions, renewal, refund, dispute, chargeback, settlement and cancellation |
| 5 | Private creator content | Quarantined draft/report/moderation foundation, admin-only pending draft image uploads and moderator-only review previews, protected media, entitled member library and guarded gallery detail with per-image authorization | Controlled uploader, permissioned review/publish/takedown, full content state lifecycle, media scanning and lawful verification |
| 6 | Pro video/streaming | Narrow signed HMAC jobs, replay-conflict protected receipt and agent preflight | Production FFmpeg worker/queues, secure segmented playback, live streaming, authorization expiration, VPS load/soak tests |
| 7 | Add-ons and updates | Signed data-only packages, edition compatibility, paid module recovery and non-mutating signed update compatibility preview | Trusted execution API/sandbox design, signed version updates with tested rollback and migration compatibility |
| 8 | Design and member UX | Responsive admin, design studio, draft pages, member accounts and private library | Complete page builder, tier/creator dashboard, usable mobile/accessibility and cross-browser acceptance |
| 9 | Operations and disaster recovery | Authenticated encrypted archive/vault, CLI restore fixture, host preflight, source ZIP checksums, read-only private-media integrity audit and maintenance gates | Real cPanel/VPS restore drills, monitoring, incident response, offsite retention, tested source/package updates |
| 10 | Security, compliance, release | Automated regressions, ten-workstream blocker CLI and fail-closed readiness checker | Independent security assessment, privacy/consent/legal review, accessibility, performance, incident and support signoff |

## Operator-controlled actions

For **#1**, complete the owner-only checklist in [CWP_CATALOG_SETUP.md](CWP_CATALOG_SETUP.md) and track the external work in [Cajun_Web issue #399](https://github.com/afterburn25/Cajun_Web/issues/399). Do not commit license keys, billing tokens, card data or staging customer records. The existing read-only staging probe is useful only after the owner has created **real** products and ordinary customer keys.

For **#2 and #9**, first run the read-only checks in [DEPLOYMENT_PREFLIGHT.md](DEPLOYMENT_PREFLIGHT.md) and [STAGING_HOST_HTTP_ACCEPTANCE.md](STAGING_HOST_HTTP_ACCEPTANCE.md), then independently witness a fresh HTTPS install and a restore drill on **each actual hosting category**. CI's localhost PHP server and disposable MySQL do not certify cPanel or VPS.

For **#3 and #4**, record the chosen contracted third-party providers and review their actual signed callback protocols and regulatory/acceptable-use terms before enabling uploads, subscriptions or adult-media access. No generic HMAC utility, local sample callback or signed receipt should count as provider integration. Apply the correct legal and operational controls before public access.

For **#5 through #8**, continue implementation in small reviewable commits with executable tests. The private library is text-only for permitted records; the signed add-on update form performs a **preflight only** and cannot replace packages. No creator upload, external verification, adult membership checkout or executable third-party add-on capability is implied. No publication or billing is enabled by this matrix.

For **#10**, require independent, documented acceptance of the other nine tracks and the controls in [RELEASE_GATES.md](RELEASE_GATES.md). Keep the release checker nonzero until those gates have verifiable signoffs and production incident-recovery procedures.

## Required safety rules

- Use a **disposable** development database for tests that create or remove tables. Production/staging customer databases must never be passed to CI test scripts.
- Keep private keys, API secrets, license snapshots, performer records, backups and payment metadata out of GitHub source, Actions logs and public web paths.
- A green CI workflow proves only that the tested local contract passed. It does **not** imply valid payments, performed identity checks, lawful consent or actual production deployment.
- Maintain the installed software's fail-closed routes when age, consent, moderation, payment or license state is incomplete. Neither member registration nor an uploaded file authorizes adult delivery.

## Distribution archive acceptance

The developer-only `php scripts/build-release.php /absolute/path/release.zip` command builds **one** Lite/Pro source ZIP outside the source/web root and emits a `.zip.sha256` digest file. Its CI contract reopens the archive, checks that required runtime classes and both edition descriptors are included, and checks that runtime private configuration and customer storage are not packaged. The checksum is **not** a release signature and does not establish trusted publisher identity, safely installed updates, or production host certification. The operator must independently check release provenance, final source revision, archive integrity, TLS deployment and real host behavior.

## Private member galleries — development milestone

After signing in, members can navigate from `/members` to `/members/library` and an individual `/members/library/CONTENT_ID` gallery. The application checks the same default-deny access policy **before revealing titles or media identifiers**: member status, email ownership, third-party age assertions, moderation, declared performer count with approved verified consent, and active/paid tier when applicable. An unauthorized or removed gallery responds with a uniform 404. The detail view includes only approved, allowlisted images; every `/media/MEDIA_ID` binary request checks the current session, account generation and content entitlement again. The public site does not expose storage keys, create open image URLs or enable an upload/publication endpoint.

Unit tests cover XSS-safe HTML output and MIME filtering. Disposable MySQL tests cover absent or revoked performer consent and pending/cancelled/expired/renewed **fixture** membership states. The disposable real HTTP fixture now stages a temporary 1×1 PNG and controlled sample verification state, checks gallery access and binary delivery, then revokes consent to verify that both gallery metadata and image bytes are immediately unavailable. This fixture is **not** a real provider, a real charge, or a lawful consent record.

An authenticated administrator can now use `/admin/moderation` to attach a maximum 8 MiB JPEG, PNG or WebP to a still-unreviewed private draft. The file is stored outside `public/`, its database record is set to `pending`, and the queue reports the pending image count; the operation never approves media, exposes a public URL, or creates performer evidence. The real HTTP suite covers valid multipart uploads, invalid CSRF, non-image payloads, rejected drafts, pending-media denial, authenticated administrator preview, preview removal after rejection or logout, and subsequent consent revocation.

The production creator upload workflow, EXIF/geolocation removal and malware scanning, approved age/identity and consent provider, moderation publication flow, subscription processor, and media storage/streaming lifecycle remain incomplete.


## Read-only private-media integrity checks

Operators of a **completed installation** can run `php scripts/audit-private-media.php` or `php scripts/audit-private-media.php --json` from a server terminal. It queries registered `ac_media` storage keys and scans the private `storage/private-media` directory for missing files, unreferenced blobs, unexpected file types, symlinked paths and invalid file sizes (up to 8 MiB). Output contains **aggregate counts only**, never filenames, storage keys, performer evidence, private paths or image bytes.

The audit is deliberately **non-mutating** and fails with a nonzero exit code if inconsistencies are found; it does not repair the database, delete possible evidence, authorize a public image, or bypass an incomplete installation. Run it during normal owner-controlled maintenance and investigate anomalies using a trusted backup and your legally required retention process. `tests/mysql_private_media_audit.php` performs a separate disposable MySQL and filesystem test; it does not touch customer files. This is not an external disaster-recovery drill or independent compliance certification.
