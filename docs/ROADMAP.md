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

## Revision-specific private creator feedback retention — development only (2026-11-21)

An additive m15 local creator feedback-history table now snapshots each request-changes or reject decision, private note, reviewer, and submitted draft revision in the same transaction. An active local creator can inspect their own last 100 private decisions even after editing/resubmitting; owner/admin can see last 100 locally retained decisions in the protected read-only creator detail view, including suspended creator profiles. Text stays HTML-escaped in a no-store/noindex environment. The existing numeric-only action log remains text-free. The m15 upgrade can retain the **current** m14 review note when its last numeric decision event verifies reviewer, decision and time. It does not fabricate earlier overwritten feedback or review provenance when the corresponding event is absent. Lite and Pro remain one self-hosted software package, selected by the customer's software license.

This is private planning feedback, **not an age/consent check, media approval, public publication, payment entitlement, vendor creator application or final compliance release**. SQL, HTTP, source ZIP and encrypted restore tests guard the boundary.

---

## Private local creator text-review cycle (2026-11-21, development only)

Each **independently installed** Adult_Core site can now hand off creator-owned **text-only** planning drafts to its own moderation team. The creator saves a private plan, explicitly submits it, and cannot edit while submitted. Local owner/admin/moderator reviewers can request changes (with a private escaped feedback note) or reject. Changes require the creator to save a new private revision before resubmitting. Rejected plans stay private and cannot be edited. Optimistic draft revisions, active creator/member/role checks, CSRF and numeric-only draft action events block stale submissions, cross-account actions and unauthorized review.

A separate, repeatable `CreatorReviewSchema` m14 migration adds `review_note` and `ac_creator_draft_events` after the owner-granted creator schema; `Site setup` applies it. This is **not** a creator application, central SaaS, public publication, media moderation approval, identity/age/consent verification or subscription grant. Unverified media, approved adult merchant/payment setup, real cPanel/VPS deployment and independent security/legal signoff remain launch blockers.

---

## Owner-provisioned local creator workspace — development only (2026-11-21)

Added `/admin/creators`, a manager-only UI to grant, suspend and restore private creator planning tools for an existing email-confirmed member on the **independent installed website**, with CSRF, active-role and optimistic revision checks. The corresponding `/members/creator` view lets an enabled member save or edit their own escaped text-only planning drafts (never images/videos) while remaining a normal member account. Local profile records and draft status cannot unlock media, grant paid access, create published galleries or assert age/performer consent. Creator draft rows are separate from `ac_content` and private-to-account, and owner actions are numeric-only audited. See `docs/LOCAL_CREATORS.md`.

The next creator workstreams remain real creator onboarding/verification, secure uploading, independent age and performer consent, moderator approval/publishing, takedown and approved payments; this implementation does not address them. Existing Lite shared-host and Pro VPS capabilities remain selected by **one Cajun Web Pro software license key and one installer**, never SaaS.

---

## Persistent admin cookie and no repeated per-edit password — 2026-11-21

Login is now backed by a persistent opaque PHP session cookie (7-day expiry, 24-hour idle/7-day absolute server timeout, HttpOnly, SameSite Strict and Secure on HTTPS). The cookie survives browser restart but stores no credentials, and logout/revocation still cancel it. Routine admin actions rely on the existing verified session and CSRF protection.

Sensitive `/admin/staff` changes may be performed repeatedly for 30 minutes after a fresh login or a one-time current-password confirmation. The staff page shows the unlock form only when this bounded window has expired, and otherwise shows action forms without redundant manager-password fields. This is a convenience improvement, not infinite authentication, and unrelated higher-risk operations maintain their separate safeguards. Local security and authenticated HTTP tests must remain green on both MySQL and MariaDB.

---

## Moderator account restoration and MFA operator readiness — 2026-11-21

Additional hardening rejects reuse of the old moderator password on restoration, locks manager authorization within the staff-change transaction, and displays a nonsecret MFA enrollment indicator on the manager's staff page. This is only a status cue: MFA enrollment does not verify staff email ownership or satisfy external identity/consent obligations.

---

## Restricted staff account provisioning, suspension, restoration and password rotation — 2026-11-21

The administrator-only `/admin/staff` page now provisions **moderators only**, with fresh manager password step-up, IP-bounded request attempts, CSRF, immutable role restrictions and transactional private action history. Staff passwords are always hashed and never logged. Active moderators can rotate their own credential at `/admin/security` after proving the existing password. Suspension, reactivation with a new credential and self-password rotation each increment `ac_users.staff_session_epoch`; existing moderator sessions reject mismatched epochs, including a browser that never contacted the site during suspension. Password-verified MFA challenges store the epoch and cannot be completed after account rotation.

`StaffSchema::migrate()` adds an audit table and epoch column after the member status migration. It is additive, repeatable and shipped in the unified Lite/Pro package. Local unit, disposable MySQL 8 and MariaDB 10.11, and real authenticated HTTP tests cover denied manager CSRF/step-up, staff role boundaries, suspension, restoration, stale-session denial, password rotation and audit logs. See [staff account runbook](STAFF_MODERATION.md).

**Still open:** self-service verified-email invitations and delivery, independent security/privacy review, real-host validation, adult content identity/consent/legal checks, merchant billing, Pro worker and complete creator lifecycle. The staff workflow is a development increment, not production authorization.

---

## Disposable MariaDB shared-host compatibility CI — 2026-11-21

The PHP checks workflow now includes a separate MariaDB 10.11 service job on PHP 8.3, exercising fresh Lite installation, real HTTP authentication/member/moderation flows, private media integrity, verified-event billing inbox replay, and encrypted backup/empty-database restore against a **disposable** MariaDB schema. This supplements—not replaces—the existing MySQL 8, real Apache container, and PHP development-server checks, and is intended to catch common shared-host SQL incompatibilities. Every destructive test requires the exact disposable-DB confirmation flag. **It does not deploy to a real cPanel host, configure HTTPS, activate live licenses, connect approved merchant/identity providers, or certify Pro VPS workers.**

---

## Private draft review detail for moderators — 2026-11-21

Authorized moderators and administrators can now use `GET /admin/moderation/draft/CONTENT_ID` to inspect a saved private, unpublished draft (including quarantined or rejected drafts) as a single coherent review page. The server returns only the draft's safe metadata, allowlisted image MIME/review states and numeric image IDs, performer *evidence-status indicators* (no documents or private record keys), and a bounded private audit history. A clickable `/admin/moderation/image/ID` review URL appears only for an unreviewed pending image in a still-private pending draft; every actual image response independently enforces authentication/MFA, role, storage integrity and current draft state. Rejected/quarantined image links remain unavailable. The detail route is GET-only, no-store/noindex, requires current `content.moderate` permission and cannot change review/publication or grant member access. SQL and HTTP tests use separate administrator/moderator sessions, invalid IDs, rejection and logout.

This is a review UI increment, **not** a creator uploader, approved content publication, external ID/consent determination, scanning service or real production hosting test. Workstream #5 remains open.

---

## Restricted moderator account end-to-end workflow — 2026-11-21

Existing active accounts with role `moderator` can now authenticate through the regular administrator login and land directly in `/admin/moderation`, with an appropriately restricted sidebar. Moderators can view review queues, create private pending drafts, inspect protected pending images, reject/quarantine, record report dispositions and consult audit history. Every mutation still requires current database-backed role, CSRF and private media/content gates; the default admin dashboard, membership tier management, site design/pages, packages, license/edition upgrades and setup remain denied. Moderators may configure **their own** authenticator MFA via `/admin/security` and see only permitted navigation.

The project still lacks an owner-facing safe staff-provisioning/role-lifecycle UI; this feature currently makes an **already-provisioned** moderator account usable, including test-only disposable fixtures. It does not give moderators permission to approve/publish adult media, manage payments, view customer credentials, bypass licensing or verify performer consent. Independent role-permission/security review remains an open release gate.

---

## Immediate revocation of suspended administrator accounts — 2026-11-21

`AdminAccounts::verifyCredentials()` and `authorizedById()` now require `ac_users.status='active'` in addition to existing password, role, MFA and session checks. A disabled/suspended owner or admin cannot begin a new privileged session, and the next authenticated HTTP request revokes any existing session. The MFA challenge also uses `authorizedById()` and therefore refuses a suspended account rather than finishing sign-in. Disposable MySQL tests exercise both direct login and active-session authorization; the authenticated HTTP suite temporarily suspends a synthetic administrator, confirms the dashboard is denied and old session is discarded, then restores the synthetic account and requires a fresh sign-in. This does not create user-suspension admin controls or a public moderator onboarding system.

---

## Read-only member subscription overview — 2026-11-21

The authenticated member dashboard now renders at most 20 subscription **records belonging to the signed-in member**, with tier labels, locally recorded state, subscription dates and the tier's current entitlement-active flag. `MemberSubscriptionSummary::forMember()` uses a member-ID-bound prepared statement; the renderer escapes every database-owned string. It explicitly warns that local `active` status is not proof of a completed charge and cannot cancel, purchase, grant, verify age or publish media. New catalog tiers remain entitlement-inactive, and this view has no privilege to change them.

Unit, disposable SQL and real authenticated HTTP tests cover empty and assigned records, disabled tier labeling, no cross-user record disclosure and HTML/XSS-safe output. This is a member UX increment, **not** a real billing portal or processor integration; payment lifecycle, refunds and independent age/consent checks remain blocked.

---

## Administrator-only saved page preview — 2026-11-21

A saved public-page draft can now be previewed under the authenticated `GET /admin/page/preview?id=ID` route, using the same escaped `Layout::site()` theme and plain-text body renderer as the public website. The administrator sees an explicit **ADMIN PREVIEW — SAVED PAGE** notice; the response sets private no-store caching and noindex/nofollow headers. Public URLs remain governed exclusively by `PageRepository::published()` and cannot show saved unpublished pages. Only previously saved database text is previewed (not unsaved browser form state), and unapproved private decorative images are never added. The page list/editor link to this authenticated read-only preview.

Unit and disposable HTTP tests check XSS-safe rendering, draft/public route separation, method rejection, malformed IDs, login requirement and non-cacheable responses. Real cross-browser/mobile accessibility review and a complete visual page builder remain open.

---

## Unpublished draft workflow and staging-only membership tiers — 2026-11-21

Moderators can now edit a still-private pending draft's title, prepare a future tier assignment and reject an individual staged image. Every mutation is checked server-side against a current active moderator/admin, CSRF, the locked draft's status, and (for edits) the expected old value to avoid lost updates. Rejected image database records and private bytes are retained for appropriate legal/incident handling, while their moderator preview disappears; no approve/publish route is added. The moderation queue now offers named draft selectors for upload/roster creation, and an administrator-only 50-entry audit-history view with escaped notes.

The new `/admin/tiers` admin page can create and rename tier **labels only**. New tiers are explicitly saved with `is_active=0`, so even a fabricated active membership row would not satisfy paid access. A private draft can reference one of these disabled labels to prepare future pricing, but its visibility remains `private` and its review status remains `pending`. The page cannot set prices, delete or activate tiers, sign up members, accept checkout, or create memberships. The same installable Lite/Pro software package includes both tier-admin source files. Automated unit, disposable MySQL, and authenticated HTTP tests cover CSRF, escaping, stale edits, inactive-tier entitlement denial, individual media rejection and audit history.

**Production still blocked:** independently approved merchant/age/consent providers, full creator onboarding, secure image re-encoding/malware scanning, publish/restore/takedown lifecycle, real cPanel and Pro VPS installation, video delivery and independent legal/security certification. These staging features are not evidence that a lawful paid adult-content storefront is operational.

---

## Disposable real Apache preinstall route check — 2026-11-21

The bundled `public/.htaccess` now rejects direct execution of unexpected PHP/script filenames (including a nested `assets/index.php`) while keeping the root `public/index.php` as the only Apache PHP entrypoint. New `tests/apache_preinstall_smoke.py` runs the preinstall application through a disposable official PHP 8.3/Apache container with `mod_rewrite` enabled, host-ephemeral loopback port and a temporary synthetic source copy. It checks the installer route and static CSS, confirms private paths remain behind the setup gate and verifies that deliberately injected executable files are **403** rather than executed. This does not use real licenses, production files, a customer database, HTTPS or external hosts.

**Still open:** actual customer-style cPanel hosting and Pro VPS deployments, real DNS/TLS and PHP extension compatibility, standalone Pro worker, customer lifecycle, independent security and commercial release acceptance. The local Apache container is supplementary test evidence, not cPanel certification.

---

## Private library bounded keyset scan — 2026-11-21

`MemberLibrary::visible()` now fetches published/approved candidate records in stable descending-ID batches of 250 until the requested authorized limit is satisfied or a 5,000-candidate safety budget is exhausted. Every candidate still passes `Entitlements::canView()` before its title or ID reaches the member UI. This fixes older entitled galleries disappearing solely because the newest 250 records belong to other tiers or lack performer evidence. Disposable MySQL tests insert 260 newer unentitled galleries and verify the older approved fixture remains listed. Full cursor-based multi-page member browsing, large-library performance and production consent/payment acceptance remain unfinished.

---

## HMAC-authenticated Pro job receipts — 2026-11-21

Accepted signed Pro job receipts now include an additional domain-separated HMAC over the complete stored `received_at`, original signed `job`, `signature` and `status` fields. Replaying an identical signed envelope requires a matching stored receipt MAC; changed status/timestamps, missing MACs and corrupt fields fail closed instead of being treated as a valid duplicate. This is an internal receipt integrity safeguard, not job execution, background supervision, video transcoding, streaming, idempotent processing or deployment readiness. Legacy unsigned receipts need controlled operator review, not automatic deletion. No production Pro worker has been deployed.

---

## Pro job receipt storage ancestry — 2026-11-21

`JobInbox::accept()` now refuses a symlinked or absent `storage/` ancestor before creating `storage/pro-jobs`. Disposable tests assert that a valid signed Pro envelope cannot redirect a private receipt into a linked destination. This extends existing replay/corruption checks **without executing a job or starting a worker**. Real VPS service provisioning, durable queue processing, FFmpeg isolation, signed HLS and load/recovery acceptance remain open.

---

## Opt-in bounded legacy image metadata audit — 2026-11-21

`php scripts/audit-private-media.php --metadata [--json]` now performs a read-only metadata inspection of registered private images, in addition to existing reference/path/size checks. It examines **at most 100 files and 64 MiB** per invocation, reports `metadata_checked`, `metadata_rejected` and `metadata_truncated` aggregate values, and exits nonzero when legacy image metadata/unsupported structures are found or the inspection budget is exceeded. Default audit remains a fast file/reference scan. No output contains storage keys, filenames, evidence, user identities or file content, and no file is modified or deleted. Disposable MySQL tests exercise clean/metadata-bearing rows and truncation. **A passing bounded result does not certify antivirus protection, media rights, production hosting or external security/compliance release.**

---

## Private image metadata and storage-path safeguards — 2026-11-21

Private draft image staging and Design Studio image uploads now invoke `src/Media/ImageMetadataPolicy.php` after server-side MIME/dimension validation and before moving the HTTP upload. The bounded, conservative PNG/JPEG/WebP container inspection refuses common embedded textual/EXIF/GPS/XMP/ICC metadata and unknown chunks, rather than preserving those structures in a private binary or potentially public decorative asset. It does **not** re-encode images, eliminate every conceivable covert data channel, perform malware scanning, certify rights/consent, or enable publishing. Images requiring metadata cleaning must be exported again by the uploader through trusted tooling.

`PrivateMedia::locate()`, `PrivateMedia::storeVerifiedImage()`, and Design Studio upload/serve paths now refuse symlinked `storage/` parents and symlinked private asset folders. Automated tests cover real HTTP rejection of metadata-bearing PNGs, clean PNG/JPEG/WebP, malformed/trailing content and symlinked private blobs/roots. The unified source distribution requires the new policy class. **Real-host/private-storage permission audits, reliable re-encoding/malware scanning, lawful content retention and independent security certification remain unfinished.**

---

## Installer public-root guard — 2026-11-21

The installer, Lite-to-Pro hosting inspection and operator deployment preflight now reuse `src/Capability/PublicRootSafety.php`. A license-valid host must have exactly the intended `public/` document root, bundled front controller/router/Apache guards, and no public symlinks, unexpected executables or copied private directories. `InstallService` rejects an unsafe public layout **before** asking Cajun Web Pro to validate a customer key and before any MySQL schema mutation. Disposable PHP and localhost HTTP tests cover these cases; the unified source ZIP explicitly requires the guard source and Apache routing file.

**Still open:** real shared-host cPanel/Apache and Pro VPS installation with DNS/TLS evidence, live licensed key/domain acceptance, production Pro service supervision, offsite restore and independent legal/security certification. This local guard cannot prove that Apache/NGINX actually honors the intended routing and private-file protections.

---

## Private media storage integrity and release packaging — 2026-11-21

The new `src/Media/PrivateMediaAudit.php` and CLI `scripts/audit-private-media.php [--json]` provide a **read-only** audit of `ac_media` references and `storage/private-media` files, identifying missing blobs, unreferenced/orphaned files, unexpected paths/symlinks, invalid file sizes and truncated scans. Output contains only aggregate counts; there is **no automatic deletion, exposure of storage keys, repair, media approval or permission to bypass legal retention**. The CLI requires a ready installed site and exits nonzero for anomalies. `tests/mysql_private_media_audit.php` uses a disposable database and temporary filesystem; it passed in [GitHub Actions 44895957609](https://github.com/afterburn25/Adult_Core/actions/runs/44895957609). The unified Lite/Pro source ZIP now requires both the audit class and CLI.

Real server permission audits, backup retention agreements, offsite disaster recovery, lawful content deletion/appeals and independent security review remain unfinished, as do CWP payment staging and actual Lite cPanel/Pro VPS acceptance.

---

## Private draft imaging and authenticated galleries — 2026-11-21

The member library now links to `/members/library/CONTENT_ID`, a **GET-only** gallery detail route that independently checks current member status, verified age/email, complete performer consent, moderation, active paid tiers and safety reports. The gallery displays only approved safe image references from `ac_media`; each `/media/MEDIA_ID` request repeats authorization before returning bytes. An unauthorized or missing gallery returns a neutral 404. The responsive gallery has a report link; titles are HTML escaped and storage keys never appear in markup. Disposable MySQL tests cover roster/consent revocations and pending, cancelled, expired and renewed **test fixture** memberships. Real HTTP integration checks a temporarily authorized 1×1 PNG and immediate revocation, not a real provider or sale.

Administrators with content-moderation permission can now attach **JPEG, PNG or WebP** images (maximum 8 MiB; dimensions at most 8192×8192 and 32 million pixels) to **unpublished, unreviewed private drafts only** from `/admin/moderation`. Images are moved to `storage/private-media` with opaque keys and a **pending** `ac_media` review state; the pending count is visible in the moderation queue. The action uses admin CSRF, upload validation, locked draft state, a 40-image cap, audit history and best-effort orphan cleanup if the database write fails. The admin queue now provides permission-checked, non-cacheable **private pending-image previews** that disappear when the draft is rejected or the administrator signs out. A real multipart HTTP test rejects bad CSRF, non-images and uploads to rejected drafts and verifies that unreviewed image bytes remain unavailable to members.

**Unfinished:** contracted age/performer consent provider, verified payment events granting members, creator onboarding, metadata stripping, malware detection, moderation approval and publication decisions, controlled video processing, live streaming, real cPanel/Pro VPS deployment and independent security/compliance release signoff. These engineering features are not permission to publish adult media or accept payments.

---

## Ten-workstream development increment — 2026-11-21

The owner requested implementation across **all ten** workstreams. The single unified Lite/Pro product, edition-selected by the four Cajun Web Pro SKUs, remains unchanged. This increment delivers **code and development tests, not commercial launch**:

- **#4 Member billing:** `WebhookInbox::recordVerified()` now treats an exact verified event retry as idempotent while refusing conflicting payload or type under the same provider event ID. A new disposable real-MySQL contract test runs in CI. This never charges a card or grants a membership; a contracted adult-approved processor adapter remains missing.
- **#5 / #8 Private member UX:** `/members/library` displays a text-only entitled catalog to logged-in members and hides unverified age, incomplete performer consent, failed moderation, unpaid tiers and all unpublished titles. Tests use actual MySQL and a browser HTTP session. It is not a creator upload or approved adult gallery launcher.
- **#3 Identity/consent status:** The read-only admin moderation queue summarizes expected performer records and evidence coverage, explicitly stating that local evidence is not independent publication approval.
- **#6 Pro foundation:** The signed job inbox rejects a conflicting reused job ID, corrupted stored records and tampered persisted signatures; no video transcoder or streaming worker is installed.
- **#7 Signed package updates:** The administrator can run a trusted-publisher signed ZIP **compatibility preview** for an installed, disabled, data-only add-on or theme. It rejects downgrades, product/publisher changes and unsupported editions without writing installed assets or registry. Actual package replacement, rollback and executable extension runtime remain **unimplemented**.
- **#10 Release control:** `ReleaseMilestones`, `scripts/release-matrix.php --json` and the existing `release-check.php` uniformly track ten open production gates. Neither CI nor simulation can mark the app ready.
- **#1 / #2 / #9:** Existing four-SKU license validation, host preflights, HTTPS audit and encrypted backup/restore are preserved. The developer source ZIP builder now checks required Lite/Pro and runtime source files, refuses output under the web/source root, and emits a verified SHA-256 sidecar. CI's `tests/release_distribution_tests.php` checks ZIP contents and secret exclusions. This digest is **not a digital signature**, signed upgrade or real host disaster recovery. Actual owner-controlled CWP product/payment staging, real Lite cPanel/Pro VPS installation and real disaster drills remain untested; see [WORKSTREAM_ACCEPTANCE.md](WORKSTREAM_ACCEPTANCE.md).

**Not completed:** real member payments, age/identity contracts, verified performer consent policy, public adult uploads, production video/streaming, executable third-party extensions, complete page builder, signed upgrade/rollback installation lifecycle, and independent security/compliance acceptance. These remain explicit production blockers.

---

## Paid add-on license recovery safety — 2026-11-21 (development only)

Signed, separately purchased module entitlements now require a valid core license and ready installation before use. New add-on activations create a private per-module pending marker before rewriting the separate module key/configuration and HMAC receipt; an interrupted update makes only the affected add-on unavailable until an authenticated administrator revalidates the add-on key through **Repair paid module license**. Backup creation refuses module-activation pending markers; encrypted archives exclude nested module-license journals/locks. Test coverage includes simulated interrupted writes, tampered/symlinked paths, UI repair forms, reactivation and backup behavior. [GitHub Actions 44886306977](https://github.com/afterburn25/Adult_Core/actions/runs/44886306977) passed for source/test commit `f2e6b73a`.

The pair of module license files is **not transactionally atomic** and an in-flight request may finish despite a newly created marker. This is NOT real purchased-module activation, production cPanel/Pro VPS deployment, processor approval, contracted verification, or release certification.

---

## 0.8.12 staging-host HTTPS acceptance (development-only)

Added an opt-in read-only HTTPS routing/private-path check for an operator-controlled staging domain. CI uses offline fake transports and a localhost PHP preinstall route round trip; this **does not satisfy** the pending real cPanel/VPS hosting, real CWP catalog license, subscription sandbox, Pro worker or compliance acceptance. See `docs/STAGING_HOST_HTTP_ACCEPTANCE.md`.

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
