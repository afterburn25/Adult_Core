## Installer-free runtime-only signed source staging — 2026-11-21

`RuntimeSourceStage::prepare()` and `scripts/stage-runtime-source.php` take a complete, independently signed and verified PRIVATE source stage, then copy only `VERSION`, `src/**`, `public/**`, and `config/editions/**` into a NEW 0700 private directory, making 0600 files, rehashing all bytes against the trusted signed manifest, leaving an incomplete marker on failure and writing `.ac-runtime-stage-complete.json` on success. Explicitly omit `install/`, themes/modules, maintainer/test files, and all local customer config/storage. This **cannot** activate a site or make the subset independently runnable. Tests extend `tests/signed_runtime_preflight_tests.php`, see `docs/RUNTIME_SOURCE_STAGING.md`. Keep ten production release blockers OPEN.

---

## Independent old-source rollback material verification — 2026-11-21

`SourceRollbackReadiness::inspect()` and `scripts/check-source-rollback-material.php` reverify installed source against the publisher-signed OLD baseline, check a private staged signed OLD release for exact intact bytes, and separately reverify a private signed NEW candidate. Report `previous_source_stage_verified` but permanently `rollback_implemented=false`, `database_rollback_verified=false`, `ready_for_activation=false` and `license_authorized=false` until independent production implementation and evidence. The enhanced `tests/signed_runtime_preflight_tests.php` tests tampered/incomplete rollback source, mismatched stages and CLI with disposable fixtures. See `docs/SIGNED_ROLLBACK_MATERIAL.md`. Never claim source staging is production backup/restore; all ten release gates remain open.

---

## Signed baseline-to-candidate runtime transition preflight — 2026-11-21

`SourceUpdatePreflight::inspect()` and `scripts/preflight-source-transition.php` verify BOTH independently signed old and new ZIPs, independently verify the private candidate stage, compare installed `src/`, `public/`, `config/editions/`, and `VERSION` against signed OLD file hashes, and refuse modified/removed code, unsigned PHP, symlinks, collisions, same/older versions and deletions. The future runtime update path explicitly excludes removed `install/`, mutable themes/modules, customer config/storage, tests, and developer docs. **Read-only; readiness flags stay false** for source activation, rollback, licensing and migrations. See `docs/SIGNED_RUNTIME_TRANSITION.md`; regression `tests/signed_runtime_preflight_tests.php` constructs a real signed old/new one-package distribution. Ten production release gates remain open.

---

## Staged source re-verification before any future source switch — 2026-11-21

`SourceStageVerifier::verify()` and `scripts/verify-source-stage.php` use the independently trusted ZIP signature to recheck an inert staged directory. They require exact signed file inventory and hashes, no extra/missing files, private permissions, valid receipt, no incomplete marker and no symlinks. They do not touch the installed app or authorize deployment. Covered by `tests/private_source_stage_verifier_tests.php` in PHP CI; keep all ten release gates open.

---

## Offline private signed source staging — 2026-11-21

`SourceStage::prepare()` and `scripts/stage-source-release.php` require a trusted signed one-package Lite/Pro ZIP and existing site source root. The output is an inert, previously absent private directory beneath a 0700 parent outside the installed site. Rehash all copied files, protect subfolders/files, leave `.ac-stage-pending` on failure and create `.ac-stage-complete.json` only after all bytes match. Do not touch installed site files, DB, license, secrets or private content. Tests in `tests/private_source_stage_tests.php`, operator details in `docs/PRIVATE_SOURCE_STAGING.md`. No live updates or production approval; ten release gates still open.

---

## Read-only authenticated source update comparison — 2026-11-21

`SourceArchive::verifiedInventory()` authenticates a detached signature and the complete ZIP file inventory once. The new `SourceDelta::preview()` and offline `scripts/preview-source-update.php` examine ONLY signed source-relative paths in an explicitly selected existing application root and refuse unsafe file/directory symlinks. They expose missing/different source files and unchanged counts, never content or customer secrets. There is NO write operation, deletion assessment, database migration, license approval, production update, data transfer, or live-host certification. See `docs/SIGNED_SOURCE_UPDATE_PREVIEW.md`. Keep all ten production launch workstreams blocked.

---
## Offline signed unified source release verification — 2026-11-21

A single Lite/Pro source ZIP can now be signed **offline** with `scripts/sign-source-release.php` and independently verified with `scripts/verify-source-release.php`, using the caller's *separately trusted, pinned* public key. `src/Release/SourceArchive.php` validates all entries by streamed SHA-256 hash, refuses unsafe/duplicate path segments, case-insensitive collisions, private config PHP, storage/secrets, symlinks, unsafe Unix file modes, and oversized ZIP contents. `SignedManifest::verify()` authenticates the detached raw JSON before comparing the complete ZIP inventory. CI tests include a real unified ZIP produced by `scripts/build-release.php` as well as tampered, forged, extra, missing and malicious ZIPs. See `docs/SIGNED_SOURCE_RELEASES.md`.

This is **only offline development-stage source verification**. Never claim an automatic signed update, deployment rollback, live cPanel/VPS acceptance, externally audited signing-key custody, adult-content launch clearance or approved executable add-ons. The ten release workstreams stay open.

---
## Creator review feedback history, keep private and site-local — 2026-11-21

`CreatorFeedbackSchema` m15 adds `ac_creator_feedback_history` after m14, enforcing one row per (draft_id, submitted_revision). Only local `CreatorDraftReview::decide()` may write a moderator's text feedback in the **same transaction** as the private draft state and numeric event; never approve, grant entitlements, modify media or publish. `CreatorFeedbackHistory::forMember()` must join local creator+member+email tables and require currently enabled ownership; `forOwner()` must require active local owner/admin `members.manage`. Render at most 100 latest entries with HTML escaping and no-store/noindex, and never move private feedback into `ac_creator_draft_events` numeric audit rows, HTTP GET query strings, or user-visible error logs.

Site setup must apply m15 after m14 and all history-dependent routes fail closed until migrated. When upgrading m14→m15, backfill **only** a current `changes`/`rejected` note whose latest numeric event has the same action and proven actor/time; determine submitted revision as current revision minus one. Never invent overwritten notes/reviewers without provenance. Re-running the migration must not duplicate rows. Keep ZIP, unit, MySQL/MariaDB, authenticated HTTP and encrypted backup/restore tests current. No centrally hosted creators, SaaS registration, public approval, age/consent verification or payment entitlement results from this feature.

---

## Creator state in encrypted self-hosted backups — 2026-11-21

Backup/restore must include **all** local creator records (m13 profile and private drafts, m14 review notes/events, local audit, confirmed email state). `SiteBackup::listTables` automatically includes ordinary `ac_` InnoDB tables; do not add exclusions or secret-copy shortcuts. Keep actual disposable encrypted `.acfull` export → wipe → empty restore tests for archived, submitted and rejected creator text (and numeric event history). The restored site must not auto-publish or grant age/consent/paid entitlement. Do not suggest real host continuity until an operator has performed a verified offsite and host-level recovery drill.

---

## Reversible private creator planning archives — 2026-11-21

`LocalCreators::changeDraftArchive()` is only for the currently authenticated, active local creator's own `private`/ `archived` draft with a matching revision, under creator-first and draft-second SQL locks. Archive is **never deletion**, retains original text and numeric events, and keeps the retained 50-draft cap. Do not let archived, submitted, rejected or changes-requested text bypass moderation or become public; restoration returns an archived plan to **private**, not submitted or published. Match existing CSRF, role, no-store, optimistic concurrency and MySQL/MariaDB/HTTP tests. Local owner audit may expose numeric event history, never public URLs or consent/identity approval.

---

## Local creator text-only review, still no approval/publish path (2026-11-21)

The `CreatorReviewSchema` m14 additive migration is applied **after** `CreatorSchema` m13. It adds a private creator-draft feedback field and numeric-only event records. `CreatorDraftReview` permits only explicit creator submission of a saved `private` draft, and moderator `changes`/`rejected` decisions. Submitted plans are read-only; for `changes`, the member must save an edited `private` revision before resubmission. Rejected plans are not editable. Require local active creator/member or active `content.moderate` actor with row locks, CSRF, revision-match, bounded UTF-8 feedback, escaped HTML and no-store/noindex. No reviewer may `approve`, publish, insert media, set age/consent, issue entitlements, expose another creator's drafts, or add cross-site SaaS identity. `ac_creator_draft_events` contains no private plan/feedback text or credentials. Preserve ZIP inventory and MySQL 8/MariaDB/HTTP regression tests; live-host and external release gates remain open.

---

## Local creator planning without central SaaS or vendor approval — 2026-11-21

Creator accounts are **locally granted private planning capabilities**, not separate software-tenant identities. Each self-hosted site's owner/admin may provision an existing email-confirmed `role='member'` only; never reintroduce an Adult_Core-operator creator application or cross-installation creator registry. `CreatorSchema` adds private `ac_creator_profiles`, `ac_creator_drafts` and numeric-only `ac_creator_actions`, not a public media/approval pipeline. The creator retains their regular member account; `/members/creator` must always derive member ID from `MemberSession`, restrict DB queries to that local ID, check active creator grant, use CSRF and reject stale edits. `/admin/creators` requires active `members.manage` permission, CSRF and optimistic status version checks; no password field on each routine change. Suspension must revoke creator workspace access without deleting stored private records, affecting other member functions or granting verified age, consent, or paid entitlements. Never add public media/upload links based on this text-only model. Maintain unified Lite/Pro ZIP and real HTTP/MySQL/MariaDB permission-denial tests.

---

## Creator workflow must remain self-hosted and owner controlled — 2026-11-21

The software is an individually installed, modular Lite/Pro membership application, NOT SaaS. A local site's owner/admin controls local staff, members and eventual creators. **Do not re-add a platform-wide creator signup/application, vendor approval process, central shared creator database, or requirement for a customer to apply to the Adult_Core operator.** The experimental creator-application classes and routes were reverted. Only implement creator features for the independent locally installed site with clearly distinct local authorization and review, and do not conflate software-license validation with creator onboarding. Independent legal/age/consent/provider/merchant checks remain separate release gates.

---

## Persistent admin sessions and bounded staff change authorization — 2026-11-21

`AdminSession` uses private server-side PHP sessions and an **opaque session-ID cookie only**, with a 7-day persistent browser lifetime, 24-hour idle expiry, 7-day hard server expiry, HttpOnly/SameSite=Strict and Secure on HTTPS. Never store a password, entitlement, privileged API key or reusable bearer credential value inside a separate client-side remember-me cookie. Session regeneration, CSRF, MFA, active-account checks, moderator epoch revocation and logout remain required. `AdminSession::managerVerifiedRecently()` must use a server-side, timestamp-bounded 30-minute window after authenticated password login. No privileged transition may renew that window without a verified password; old sessions lacking this timestamp fail closed for staff changes.

At `/admin/staff`, manager authorization, CSRF and a valid staff window are still required on every create/suspend/restore POST, but **manager password input belongs only in a separate explicit `action=verify` unlock form** after the window expires, not every routine staff form. Bound repeated unlock attempts, do not compare passwords via client cookie, and keep the role/staff-epoch/first-password-rotation checks. Preserve independent password/MFA verification for separate high-risk actions such as licence upgrade or authenticator management. Extend unit/real HTTP tests for cookie survival, server expiry and staff window reauthorization.

---

## Moderation staff lifecycle and revocable sessions — 2026-11-21

`/admin/staff` is manager-only (`members.manage` and an ACTIVE database-backed manager), every write requires current manager password step-up, CSRF and bounded attempts. `StaffRepository` may create ONLY `role='moderator'` accounts and may suspend/restore ONLY moderator IDs; no role promotion, member changes, license/edition or billing controls. Require strong unique passwords, no raw password/email in private action logs, and keep audit history under `ac_staff_actions`. Never delete staff or protected evidence as part of suspension.

On staff suspension, restoration WITH a new password and moderator-initiated password change, atomically increment `staff_session_epoch`. Every active moderator session must compare its login-bound epoch to the current database value, including after restoration. Moderator MFA pending sessions MUST capture the epoch during the password step and check it again BEFORE consuming any factor; never bind a newly current epoch to an old password challenge. `StaffSchema` must run AFTER the member status migration; preserve pre-migration first-admin login via the existing compatibility path. Keep MySQL 8, MariaDB 10.11, HTTP, role, CSRF and source ZIP regressions.

Staff email invitation/verification is not implemented. Never imply production staff compliance or adult-content release authorization. See `docs/STAFF_MODERATION.md`.

---

## Disposable MariaDB compatibility regression — 2026-11-21

`.github/workflows/php-tests.yml` now contains a separate `mariadb_compat` job running PHP 8.3 and MariaDB 10.11, in addition to existing MySQL 8 and real Apache tests. Maintain the disposable-schema confirmation environment for all SQL mutating scripts and never target a customer/staging database. This job is SQL-dialect coverage only, not an actual Lite cPanel install, live Pro VPS service test, or commercial acceptance. Keep external real-host and provider signoffs explicitly open.

---

## Private unpublished draft detail remains read-only — 2026-11-21

`ModerationRepository::privateDraftDetail()` may return only `visibility='private'` and `draft/quarantined/rejected` records; keep the fields limited to safe status, numeric image and performer record references, and bounded audit notes. No `private_storage_key`, performer identity documents or raw verification evidence reaches the route. `ModerationController::draftPage()` MUST require current `content.moderate`, allow GET only, send no-store/noindex, and HTML-escape all user-controlled text; only pending images on still-pending private drafts get links to independently authorized `/admin/moderation/image/ID`. No approve/publish/restore/upload shortcuts in this view. Preserve explicit admin/moderator/anonymous HTTP regression tests including rejection/revocation behavior and sitewide licensing gates.

---

## Restricted active moderator login — 2026-11-21

A moderator may have `admin.access` and `content.moderate` only; NEVER grant `admin.dashboard.view`, `site.settings.manage`, `members.manage` or `modules.manage` to this role. An active moderator's post-login/MFA destination is `/admin/moderation`, and their `Layout::admin(..., role:'moderator')` navigation must omit site, billing, licensing, Pro upgrade and add-on controls. They may access `/admin/security` only for **their own MFA**, using the existing password/second-factor flow. Service methods must still check active current DB role and locked unpublished states; no approve/publish code. Keep separate-cookie moderator/admin real HTTP tests and disposable SQL/role tests. Account provisioning and commercial compliance remain unimplemented.

---

## Suspended privileged accounts must be denied immediately — 2026-11-21

`AdminAccounts::verifyCredentials()` and `authorizedById()` must both require `ac_users.status='active'` in addition to permission/MFA/session checks. A disabled admin must not be able to sign in or retain a session after a DB status change. Keep `MfaController` challenge dependent on `authorizedById()`. Maintain disposable SQL and real HTTP regression that suspends/restores only the synthetic test administrator and verifies old sessions are NOT resurrected. Do not broaden moderator login permissions without a separate fully tested role-specific navigation/provisioning design.

---

## Member subscription status is read-only, not payment verification — 2026-11-21

`MemberSubscriptionSummary::forMember()` must query `ac_memberships` ONLY for the member ID obtained from the authenticated member session, never from request parameters. Limit record counts; escape tier names, statuses and dates. The displayed `active` status is a database record, not verified merchant evidence, and the UI must prominently say so. Do not add create/grant/activate/charge/cancel controls or assume a displayed record satisfies age, consent, moderation or provider billing. New tier labels remain `is_active=0`. Keep real HTTP, unit, disposable MySQL and source ZIP checks; production billing gates remain open.

---

## Saved public-page preview remains private — 2026-11-21

`GET /admin/page/preview?id=ID` is an authenticated site-settings-manager operation handled after the global license and full admin session/MFA gate. It may render **saved drafts** using the regular escaped public layout but MUST include a clear admin preview banner, no-store/noindex headers, and must never alter `ac_pages.status` or expose unapproved decorative assets. POST/other methods cannot preview, and the public `/p/slug` handler MUST continue querying only `PageRepository::published()`. Maintain real disposable HTTP tests for unauthenticated access, XSS-safe preview, method rejection, and draft 404 on public URL. This is not permission to publish protected galleries or close the page-builder acceptance gate.

---

## Staged private draft title, media rejection and inactive tier catalog — 2026-11-21

`ModerationRepository::renameDraft()`, `rejectDraftImage()` and `assignDraftTier()` are server-side **pending private draft** actions only. Lock parent draft rows before changes, reject stale expected title/tier values, enforce current moderator/admin role, use route CSRF, keep audit notes escaped/private and never add an approve/publish fallback. Image rejection changes review state to `rejected` and immediately denies moderator preview, but NEVER deletes protected blobs or legal-retention evidence. Reject replays, unknown media IDs, and use `tests/mysql_http_auth.py` and `tests/mysql_install.php` to verify no member media release.

`TierCatalog` and `TierController` use the existing `ac_membership_tiers` schema without new grants or migrations. New tier labels MUST explicitly set `is_active=0` (despite the legacy schema default of 1), and admin UI supports only create/rename; it must not expose a tier activation/deletion action, checkout, member enrollment or payment test bypass. `assignDraftTier()` may reference a disabled tier but MUST keep content `draft/private/pending`. `Entitlements::canView()` still denies disabled tiers even with a synthetic active membership record. Maintain the unified release ZIP required files, and document all merchant/creator production gates as open.

---

## Apache direct script denial and non-production HTTP smoke — 2026-11-21

Keep `public/.htaccess` denying unexpected executable paths through its rewrite rule (exact root `index.php` remains the only Apache PHP entrypoint) and defense-in-depth `FilesMatch`. Never allow `public/router.php` or nested `assets/index.php` as Apache entrypoints, and keep source `src/config/storage/install/tests/scripts` above the document root. `tests/apache_preinstall_smoke.py` exercises an **isolated** official Apache container over a loopback ephemeral port with synthetic test-only unexpected PHP files. No customer data, license key or production payment system is involved. This test is not HTTPS/cPanel/VPS certification, and it never authorizes publishing adult content or bypassing release gates.

---

## Member catalog private keyset scan — 2026-11-21

`MemberLibrary::visible()` must not expose unentitled titles/IDs, even through pagination or cursor counts. Scan published approved candidates in bounded descending-ID batches, applying `Entitlements::canView()` **before** adding rows to the response, so newer unauthorized content does not hide an authorized older gallery. Current work limits per request to 5,000 candidates and 50 authorized results. Maintain disposable MySQL regression with >250 unauthorized newer rows; future public pagination must preserve neutral privacy and complete account-age/consent/payment gates.

---

## Pro job receipt authenticated metadata — 2026-11-21

Keep `JobInbox::receiptMac()` bound to the entire `received_at/job/signature/status` record using the separate `adult-core-pro-receipt-v1` HMAC domain. Idempotent retries must reject tampered status or timestamps, changed jobs/signatures, corrupted or unsigned legacy receipts; never auto-delete legal/incident-sensitive job records. Future production workers MUST provide a separately reviewed atomic receipt state transition and supervision/authorization model, not direct writes that bypass MAC verification. Existing `JobEnvelope` expiry and allowlisted payload checks remain in force. The current inbox NEVER executes video, shell or media delivery.

---

## Pro job inbox private path guard — 2026-11-21

`JobInbox::accept()` must reject a symlinked/missing `storage/` parent and symlinked `pro-jobs/` before creating signed receipts. A valid HMAC job cannot authorize private filesystem redirection. Preserve `tests/pro_release_tests.php` linked-parent negative control. This inbox only records signed jobs, it never executes shell, FFmpeg or publishes video; the production VPS worker and streaming gate remain open.

---

## Legacy image serving and optional operator metadata audit — 2026-11-21

Approved database status does not override byte-level privacy checks. `PrivateMedia::safeToServe()` is required after member entitlement and moderator role/draft checks on **both** `/media/ID` and `/admin/moderation/image/ID`. `DesignImages::safeToServe()` similarly gates public and administrator decorative images. Mismatched/metadata-bearing legacy bytes return 404, never media headers. Rechecking is conservative and not a substitute for safe transcoding or malware scanning.

`PrivateMediaAudit::inspect(true)` and `scripts/audit-private-media.php --metadata [--json]` are read-only, aggregate-only metadata inventories with at most 100 checked files and 64 MiB per pass. `metadata_truncated` must fail closed, not silently report a complete clean scan. Do NOT output storage keys, file names, private directories, creator identities or content bytes, and NEVER auto-delete possible evidence. Maintain disposable MySQL tests including over-budget/malformed images. Real production incident handling, retention, scanning, age/consent and release certification remain separate.

---

## Uploaded image metadata and private storage safeguards — 2026-11-21

Keep `ImageMetadataPolicy::assertAllowed()` in **both** private draft upload (`PrivateMedia::storeVerifiedImage()`) and Design Studio image validation (`DesignImages::inspect()`) before any binary move or database write. Policy is a bounded reject-only PNG/JPEG/WebP container screen: EXIF/GPS/XMP/ICC, text, animation/unknown chunks and malformed containers must fail, while clean fixtures pass. This is not image re-encoding, malware scanning, adult-performer consent verification, or permission to publish. The authenticated HTTP suite checks refusal before an inserted `ac_media` row and before public decorative approval. Preserve no-source-secret output and max byte/dimension checks.

Never follow symlinked `storage/` parents, private media/design asset directories or individual files on upload/lookup/delivery. Maintain `tests/image_metadata_tests.php` (including symlink path cases), `tests/design_tests.php`, `tests/mysql_http_auth.py`, and `tests/release_distribution_tests.php` in CI. The developer source ZIP must include `src/Media/ImageMetadataPolicy.php`. These checks do not close actual cPanel/VPS media security or independent legal/security release gates.

---

## Installer safe-public-tree invariant — 2026-11-21

Keep the read-only `src/Capability/PublicRootSafety.php` shared between `HostInspector` and `DeploymentPreflight`. `InstallService::install()` MUST reject missing `public_docroot` **before** contacting the CWP issuer or creating MySQL tables. The public tree must not contain linked paths, copied `install/config/storage/src/tests/scripts` directories, hidden sensitive files, unexpected executable scripts, unscannable/deep paths, or missing/invalid bundled `index.php`, `router.php`, `.htaccess` guards. Do not let an operator-selected expected edition bypass this requirement; only the verified license SKU chooses Lite/Pro. Keep the HTTP denial test, disposable MySQL installer and recovery fixtures, ZIP packaging checks and actual-host acceptance separate. This is not proof that real Apache, cPanel or VPS web routing is safe.

---

## Read-only private-media integrity audit — 2026-11-21

`PrivateMediaAudit::inspect()` and `scripts/audit-private-media.php [--json]` read registered `ac_media` identifiers and `storage/private-media` to report **aggregate counts only**: missing files, orphaned blobs, unsafe/symlinked paths, invalid storage rows, out-of-range sizes and truncated scans. They **never log or display storage keys, private paths, user media or verification evidence** and never modify/delete files or database rows. A completed installation is required for the CLI. Nonzero exit codes indicate anomalies or unavailable configuration. Keep `tests/mysql_private_media_audit.php` on disposable MySQL and temp storage only, and keep both the audit class and CLI in the single Lite/Pro release ZIP.

Do not turn this audit into an automatic cleanup or retention bypass. Orphans may require legal/incident preservation, and real backup/restore and offsite checks remain independently outstanding.

---

## Private member galleries and pending draft images — 2026-11-21

Member pages `/members/library` and `/members/library/ID` must **never reveal titles, media IDs or storage keys** without independent `Entitlements::canView()` authorization. `MemberLibrary::detail()` only lists approved allowlisted MIME records, and every `/media/ID` request rechecks member identity, password generation, age, email, paid tier, moderation and complete performer consent. A missing/unauthorized gallery returns uniform 404. Never treat a local synthetic verification fixture as an actual age or consent check.

Admin `/admin/moderation` may now accept only **actual HTTP-uploaded** JPEG/PNG/WebP files into **private, unpublished, unreviewed** drafts. `ModerationRepository::attachPrivateImage()` checks active moderator role and locks the draft, caps it at 40 images, stores a random private-media key, writes only a **pending** media row, audits and tries to clean up a moved blob on SQL failure. `PrivateMedia` enforces 8 MiB, max 8192×8192 and max 32 million pixels; no public URL or automatic approval is allowed. The `/admin/moderation/image/ID` preview is **GET-only, no-store, role/session/MFA-protected**, and shows only pending media for currently private unpublished draft content. After quarantine/rejection/logout, previews must fail closed.

Preserve the real multipart upload, invalid CSRF, member denial, administrator preview, rejected-draft refusal, consent revocation and logout tests in `tests/mysql_http_auth.py`. Production still requires a contracted verification provider, formal consent/recordkeeping, image metadata stripping/scanning, moderation approval/publication and a real adult-appropriate billing processor. Never add an approval/publish shortcut to accommodate fixture tests.

---

## Source ZIP distribution integrity (2026-11-21)

Keep `scripts/build-release.php` as a **developer-only**, single Lite/Pro source ZIP builder with an output directory outside the project/public root. The ZIP must contain all runtime classes, both edition manifests and no private `config/license.php`, `app-key.php`, customer database or private media. `tests/release_distribution_tests.php` in CI reopens the archive and checks a generated `.sha256` checksum. This checksum is **not a digital signature** and does not authorize an update or claim production release acceptance. Preserve the explicit fail-closed updater and host-certification gates.

---

## Ten-workstream security and release boundary (2026-11-21)

Keep `src/Support/ReleaseMilestones.php`, `scripts/release-matrix.php` and `ReleaseReadiness::blockers()` synchronized: **all ten production gates remain open** until independently evidenced. `docs/WORKSTREAM_ACCEPTANCE.md` records the real owner-controlled tests. Never infer merchant approval, a completed adult age/consent workflow, Pro FFmpeg operation, cPanel certification or independent audit from a GitHub green run.

`WebhookInbox::recordVerified()` accepts only exact verified-event retries; same provider/event ID with a different payload digest or event type must throw. It records verified metadata and is **not** a real payment adapter. `MemberLibrary::visible()` must filter every candidate through `Entitlements::canView()` before revealing content titles; `/members/library` is authenticated and text-only without unguarded media links. Performer evidence in the moderation dashboard is a read-only coverage indicator, not permission to publish.

`JobInbox::accept()` must reject conflicting job ID replays and corrupt persisted signatures, never execute jobs. `PackageStore::previewUpdate()` is **read-only** and verifies exact signed publisher, paid SKU, disabled target, increasing version, dependencies and edition; it does not replace or execute extensions. A future production updater requires a separate fail-closed signed rollback implementation.

---

## Paid-module licensing requires valid core — 2026-11-21

In `src/Licensing/ModuleLicenseManager.php`, a separately licensed module must never function merely because it still has a positive add-on receipt. Paid add-on activation and runtime checks require both `InstallationGate::READY` and a currently authorized core license (including the established signed core-cache and grace/denial semantics). Reject symlinked `storage/packages/module-licenses` and its ancestors, `.lock`, and either module license output file; preflight both outputs under lock. Before changing either add-on file, activation creates a private `.pending-MODULE_ID` marker; module status then refuses a cached grant until both files have been rewritten by a freshly issuer-validated key and the marker is cleared last. The admin package manager must offer an explicit **Repair paid module license** form for enabled-but-blocked modules; `PackageStore::changeStatus(..., true, ... , key)` performs the authorized retry. No silent auto-cleanup or manual deletion of journals. Backups refuse a pending activation and exclude nested module-license journals and lock files. `tests/package_tests.php` models signed core installation, paid add-on receipt, revoked core, pending license replacement, interrupted add-on activation, authenticated repair and other recovery markers. Preserve these fail-closed checks if modifying package/runnable paths. All tests are simulated, not real product issuance or purchases.

---

## Interrupted license replacement recovery (2026-11-21)

Preserve `src/Licensing/LicenseUpdateRecovery.php`, `scripts/recover-license-update.php`, and the `storage/.license-update-pending` fail-closed marker. A same-edition key replacement takes both license update/refresh locks, records an HMAC-signed private snapshot of the *previous* license and cache, writes both new private files, and clears the marker last. Lite-to-Pro upgrades acquire edition → license update → license refresh locks, so they cannot overlap key replacement or cache-refresh writers; do not reverse that order or follow symlinked locks. Any interruption blocks **all** web/admin routes and license refresh, and `InstallationGate::eligibleForBackup()` returns false. Recovery is **CLI-only** with `php scripts/recover-license-update.php --rollback`; do not delete a marker manually, bypass a MAC or restore from unsigned data. The snapshot contains the old key and must remain owner-only inside ignored private storage. Run `php tests/license_update_recovery_tests.php`, HTTP smoke, full licensing tests and disposable MySQL GitHub Actions before editing these paths. This is NOT live Cajun Web Pro billing, a fully atomic filesystem transaction, or release certification.

---

## Current 0.8.12 staging HTTP audit (2026-11-20)

Read `docs/STAGING_HOST_HTTP_ACCEPTANCE.md` before changing `scripts/staging-host-audit.py` or `tests/staging_host_audit_tests.py`. This read-only HTTPS probe runs only with explicit staging opt-in, rejects private/reserved/IP-literal DNS targets, pins verified public addresses, validates TLS and hostname, never follows redirects or reads response bodies, and checks app routes, security headers and private-path denial. No license keys, subscription mutation or adult content. The offline tests use synthetic responses and a localhost PHP development server; they do NOT certify real cPanel/VPS or merchant integration. Preserve all Lite/Pro key-selected editions, local deployment check, installer cleanup gates, backup/MFA and compliance release blocks. Run the new Python test in CI as well as the existing suites.

## 0.8.11 host deployment preflight (2026-11-20)

Additive, read-only CLI `scripts/deployment-preflight.php` with `src/Capability/DeploymentPreflight.php` and local fixture/loopback agent tests. This scans requested `public/` docroot, PHP requirements, accidental public executable/symlink/private tree exposure, installed signed edition and optional Pro agent. **`--expected-edition` is an operator assertion, not authority**: the Cajun Web Pro product-specific key remains the sole edition selector. No real cPanel/shared or VPS environment was configured; the included Pro demo only proves the local handshake. Follow `docs/DEPLOYMENT_PREFLIGHT.md`, include both new test suites in CI, and do not mark 1.0 ready.

## Latest development: 0.8.10-cwp-staging-dev

The CLI-only `scripts/audit-cwp-staging.php` and `src/Licensing/CwpStagingAudit.php` add an explicit, read-only, 3-request operator acceptance probe for an already-issued staging key; key file must remain outside checkout and have private permissions. Only HTTPS Cajun Web Pro hosts are accepted; the primary host requires a second explicit opt-in. Offline tests in `tests/cwp_staging_audit_tests.php` run in CI. This is NOT a real issued license, renewed subscription, merchant transaction, or catalog provisioning, and must never be silently run in CI with customer credentials. Read `docs/CWP_STAGING_PREFLIGHT.md`. Do not weaken any signed license, installer, backup, MFA or Pro gates.

## Current milestone: verified backup vault + controlled cron-safe operations — 0.8.9-backup-ops-dev

Preserve the original AES-GCM `.acfull` format, strict *empty-target-only* restoration, signed installation and Cajun Web Pro edition/product licensing. `src/Backup/BackupVerifier.php` deeply authenticates, checks file checksums, schema/rows and signed install state without restoring or exposing secrets. `BackupVault::ship()` copies to a separate operator-provided private directory, verifies after copy and prunes only recognizable older verified files; it deliberately cannot certify that a path is genuinely offsite. `scripts/backup-job.php` adds a 503 maintenance marker blocking **new** HTTP and requires explicit site-writes-paused acknowledgment; **it cannot stop already-running PHP requests/Pro workers**, so actual quiescence is still an operator prerequisite. Hard-crash markers stay fail-closed; never delete them before incident review. Run `php tests/backup_vault_tests.php`, all local suites, disposable MySQL backup/restore CI and real host drills before release. Details in `docs/BACKUP_OPERATIONS.md`. **No production 1.0 or billing certification**.

---

## Current security increment: full-site encrypted disaster recovery — 0.8.8-backup-dev

Do not weaken the `SiteBackup` fresh-empty-database restore boundary, `Storage/.backup-restore-pending` fail-closed marker, independently stored backup key, encrypted streaming record verification, private-file traversal limits or MySQL DDL nontransactional warning. Restore **never** merges into an existing installation. Keep `install/` present after restoration for operator verification; manually delete it before normal access. `config/database.php` must be newly provisioned on the destination and excluded from archived files. Real MySQL CI and independent Lite/Pro host drills are mandatory before production use. See `docs/PORTABLE_BACKUP_RECOVERY.md`.


---

## Current milestone: administrator MFA — 0.8.7-mfa-dev

Keep `src/Auth/{AdminMfa,AdminMfaSchema,Totp}.php`, `src/Http/MfaController.php`, `/admin/mfa`, `/admin/security`, and the login/session enforcement together. Password success NEVER authenticates an enrolled administrator until a TOTP or single-use recovery code is consumed. Maintain app-key AES-GCM encryption, `FOR UPDATE` replay protection, post-enrollment enforced marker, reauthentication and CSRF controls, and signed commercial-license gate exception for the *challenge only*. Existing-site setup is additive, and owner cannot bypass via membership recovery. See `docs/ADMIN_MFA.md`; production and Cajun Web Pro real billing gates stay open.

---

# Subscription contract test handoff — 0.8.6.7 development

`tests/cwp_subscription_contract.py` and `tests/fixtures/cwp_subscription_client.php` run a real localhost HTTP simulation of the CWP `POST /api/validate.php` wire contract without accessing live services or modifying shipping production URLs. This is **not merchant or live publisher acceptance**. Include the new Python test in CI. Preserve four exact SKUs, private signed caches, 72h bounded issuer-outage grace, fail-closed definitive denial, and manual installer deletion. The admin license view must warn that key replacement does not cancel subscriptions; the upgrade URL remains the CWP homepage pending owner approval. Details in `docs/SUBSCRIPTION_ACTIVATION_TESTING.md`.

# Current licensing-health milestone — 0.8.6.6-license-health-dev

`/admin/license` now includes a CSRF-protected, rate-limited, site-management-only **Check license now** action. `LicenseManager::status($host, true)` deliberately bypasses the one-hour cache but uses the existing lock, domain/edition checks and signed HMAC receipt; `lastAuthorityResponse()` reports only `valid`, `denied`, `transient` in-memory (never persists a key or vendor response). A 5xx/429/TLS failure is inconclusive, and existing grace is bounded; a positive result after revocation restores status only if explicitly verified again. See `docs/CWP_CATALOG_SETUP.md`; four live catalog products/prices remain owner-controlled and unprovisioned. Run `php tests/licensing_tests.php`, `python3 tests/http_smoke.py` and GitHub disposable-MySQL browser CI. Never claim live checkout or subscription readiness.

# Current upgrade milestone — 0.8.6.5-upgrade-dev

One installer, four Cajun Web Pro edition/term SKUs, plus a **same-installation** Lite-to-Pro upgrade at `/admin/upgrade`. `src/Licensing/EditionUpgrade.php` verifies a Pro SKU/domain, exact signed Lite state, existing MySQL row and host Pro capabilities before changing edition metadata. Never trust a submitted edition, fake a Pro-agent capability, or create a second database. Step-up administrator password, HTTPS, CSRF, rate limiting and explicit external-backup acknowledgement are required. A private HMAC-authenticated snapshot of only **three license/edition metadata files** and a `storage/.edition-upgrade-pending` sentinel protect partial state; a persistent sentinel locks both site and admin until the **CLI-only** `scripts/recover-edition-upgrade.php --rollback`. That script restores prior Lite metadata; it is NOT a full database/website backup. No production Pro video or adult content approval is activated. Tests: `php tests/upgrade_tests.php`, `php tests/licensing_tests.php`, `php tests/mysql_install.php` on disposable MySQL, browser suite, and all normal CI. Do not claim live Pro agent or Cajun Web Pro keys were tested.

## Agent notes — edition-bound monthly licensing (2026-11-20)

Version `0.8.6.4-license-dev`: **one installable software**, key-selects Lite/Pro and either one-time or monthly term via Cajun Web Pro's four separate product SKUs. Pro eligibility is independently enforced from verified host capabilities; cross-edition license replacement is blocked until safe migration is implemented. Paid static add-ons may declare signed `license_sku` and require a distinct vendor key before enabling; runtime eligibility rechecks signed entitlement caches. Lite admin displays disabled Pro-only features with **Pro Version Only** and an upgrade link to https://cajunwebpro.com (placeholder). No customer payment or live license issuance was performed. The earlier single SKU is retired for new installs; existing installations require a matching replacement key. Operator must register catalog offerings and test actual monthly billing externally. Details: `docs/CAJUN_WEB_PRO_LICENSING.md`. Release 1.0 still blocked by production compliance, payment, streaming and security gates.

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
