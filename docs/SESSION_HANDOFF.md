## Disposable HTTP fixture timeout stabilization — 2026-11-21

A MariaDB 10.11 run intermittently timed out while the authenticated `/admin/setup` request was applying many additive local migrations, despite earlier and later builds passing the same functionality. The test-only urllib transport deadline is now 12 seconds (previously 4) for actual loopback requests, keeping all response codes, CSRF/MFA, negative tests, deployment locks and content-access checks unchanged. This does not raise public PHP/proxy request limits or certify real cPanel/VPS installs. CI must rerun successfully to accept the updated fixture.

---

## Serialized local membership-tier labels and raw input protection — 2026-11-21

TierCatalog now locks the site's existing installation row with `SELECT ... FOR UPDATE` before either create or rename, serializing the local 50-row limit and case-insensitive name uniqueness check across concurrent administrator sessions. The installation row is not modified and no license or subscription action is introduced. Tier name validation now examines untrimmed bytes so trailing NUL/newline controls cannot be normalized into accepted labels. Unit/source checks and disposable MySQL/MariaDB regressions cover valid labels, malformed controls, duplicate case variants and zero accidental membership grants. The catalog still creates disabled labels only; approved adult payment checkout and provider-authenticated entitlement issuance remain blocked.

---

## Unicode-safe brand monograms after m20 — 2026-11-21

The public site brand emblem and decorative hero monogram now select the first UTF-8 code point of each selected name word, rather than `substr(...,0,1)` splitting accented, CJK or emoji bytes. Existing ASCII brand initials are unchanged; malformed legacy strings use safe fallbacks with escaped output. UI regression examples cover accented Latin, CJK and emoji site names. This is purely a rendering safety fix; licensed domain, site permissions, public media, age/consent and payments are unchanged.

---

## Installer and owner branding validation parity — 2026-11-21

Fresh installs now reject site names containing malformed UTF-8 or C0/DEL control bytes, checking the untrimmed raw value before any issuer request or database creation. This aligns the universal Lite/Pro installer input boundary with m20 owner's local branding editor; both accept 2–120 UTF-8 bytes of single-line public display text. Existing installed legacy values are still displayed escaped for correction rather than silently mutated. Disposable MySQL tests exercise leading/middle/trailing malicious control input and ordinary installer behavior. Neither license issuance nor host installation is claimed to be live-certified.

---

## Encrypted local branding disaster recovery test — 2026-11-21

The disposable full MySQL/MariaDB encrypted `.acfull` backup and empty-database restoration fixture now migrates m20, changes the canonical site name with a revision check, and verifies the exact brand string and revision survive the restore without an issuer request or an account/password change. This covers only the local website name, not the licensed domain. All previously added creator, draft, page history, decorative media and encrypted archive assertions remain. Actual cPanel/VPS restore drills and independent launch acceptance are still required.

---

## Owner Site identity UI, local m20 setup and real browser coverage — 2026-11-21

`/admin/site` now exposes an authenticated owner/admin-only branding editor with CSRF, strict single-line UTF-8 input and a revision token; no re-prompt for the administrator password is needed. Successful changes update the canonical installer's site name in local `ac_settings`, so existing safely escaped public header/title/footer renderers pick up the new value. The Setup screen tracks m20. The moderator role cannot access `/admin/site`, and both URL routing and UI permissions are enforced. Disposable real HTTP regressions verify valid saves, malicious control-byte denial, stale-tab rejection and XSS-safe public branding. This affects neither licensed Lite/Pro installation nor age/consent or payment workflows. Production release blockers remain open.

---

## m20 site-local identity backend — 2026-11-21

Added repeatable `SiteIdentitySchema` for the canonical `ac_settings.site_name` plus a separate local monotonic revision. `SiteIdentity` validates owner-supplied 2–120-byte single-line UTF-8 text before saving, uses a database lock and expected revision, and leaves the vendor domain/license, edition, member state and protected media untouched. Legacy installer branding is preserved and displayed escaped even if it predates new input rules. Repeating setup never resets an owner-changed name. Added unit, disposable SQL and one-package ZIP checks. The authenticated owner UI and real HTTP/backup regressions are the next development step; no live cPanel or VPS acceptance is implied.

---

## Legacy admin page-save form preserves navigation settings — 2026-11-21

An administrator's older browser tab opened before m17 may submit title/body/status without the newer menu fields. The page editor now passes null optional values to the revision-safe repository, preserving existing `show_in_menu` and `nav_position` rather than silently relisting a published unlisted page. Explicit malformed menu settings still fail validation; modern forms continue sending their checked/unchecked values. The real HTTP regression saves an unlisted page with a legacy-style form and verifies its invisible nav link and stored sort order survive. Publishing, protected media, member billing and the Lite/Pro installer are unchanged.

---

## Protected read-only page history preview — 2026-11-21

Owner/admin may now preview a specific saved page revision using a read-only `/admin/page/history?id=…&revision=…` URL. The version is selected by exact page ID and revision under the existing license, session, MFA and owner role checks. Preview renders escaped plain text only, with no private media, forced publication or live theme image; replies are `Cache-Control: private, no-store` and `X-Robots-Tag: noindex, nofollow`. Invalid/missing versions return 404, POST is 405 with valid session CSRF, and logged-out requests redirect before any private text loads. The editor links to previews beside draft-only restore forms; source snapshots remain immutable. SQL and real authenticated HTTP regressions cover these restrictions. This is not a creator publication, merchant billing or legal verification integration.

---

## m19 disposable database teardown ordering — 2026-11-21

The first m19 MariaDB run passed the real installation and upgrade checks but exposed an integration-fixture cleanup error: the new foreign-key-bound `ac_page_history` table was still present when dropping `ac_pages`. Both disposable SQL and authenticated HTTP cleanup lists now drop page history before the parent page table. Production customer database migrations or drop procedures are not changed. Rerun complete MySQL/MariaDB CI before claiming verified success.

---

## Private page revision history with draft-only restore (m19) — 2026-11-21

A repeatable additive m19 schema preserves page title/body, status, slug, menu visibility/order and revision snapshots in a local `ac_page_history` table. Existing installations backfill **only the current known state**; prior overwritten revisions cannot be reconstructed. Every subsequent page create/edit and private page duplication writes the snapshot in the same DB transaction, including Design Studio homepage edits through savepoints; if the snapshot fails the edit rolls back. The owner/admin editor lists at most 20 recent version summaries and permits a CSRF-protected, revision-checked restore of an earlier title/body. **Restoration always creates a new private draft** and never restores an earlier published state, URL slug or menu flag. The owner must separately publish again.

Site setup requires a verified backup before the additive migration; page edit routes stay unavailable until the marker is installed. Signed Lite/Pro source ZIP checks, unit/database, real authenticated HTTP, MySQL/MariaDB and encrypted backup/restore regressions cover version history, stale/replayed restores, private 404 after restore, and local recovery parity. No member entitlement, adult-media publishing, independent age/consent or merchant payment gate is enabled. Actual host rehearsals and independent release acceptance remain outstanding.

---

## Protected homepage route and unique active navigation — 2026-11-21

The site-local page repository now treats `home` as an immutable, reserved slug: the seeded homepage cannot be renamed, another page cannot claim `home`, and an unpublished duplicate cannot reuse it. Version-checked edits to the real homepage title, content, draft/published status and navigation remain supported. The owner editor shows its special slug read-only but the server enforces the restriction independently. Public pages and authenticated draft previews now mark active navigation by the route slug, never by a potentially duplicated title; at most one public-page link receives `aria-current="page"`. Unit, disposable MySQL/MariaDB and authenticated HTTP regressions cover denied mutations and duplicate-title links. Existing one-package Lite/Pro licenses, adult verification and payment release gates are unchanged.

---

## Visual preview of owner-edited homepage information tiles — 2026-11-21

The Design Studio preview now renders the same three safe information tiles as the public homepage, with live input listeners for each heading and description. JavaScript writes only textContent, never innerHTML; strict server-side UTF-8 validation and HTML escaping remain authoritative. No script/template execution or media upload is introduced. Unit checks and authenticated HTTP tests cover all six preview fields.

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

## Per-feature additive schema status in owner Site setup — 2026-11-21

The authenticated Site setup page now shows each local schema migration separately, including new page revisions and navigation, staff/MFA, creator review and feedback, member/email, design and moderation. A base page table no longer makes later migrations appear installed. GET remains read-only; the existing authenticated CSRF POST is still the only application path. A recorded schema marker is never presented as proof of age/performer consent, adult merchant approval, Pro workers or production security. Disposable HTTP checks verify missing-versus-installed display without running a real hosted update.

---

## Full HTTP regression for page listing beyond 50 — 2026-11-21

The disposable, confirm-gated HTTP fixture now creates 51 additional unpublished pages only after complete local site setup. It verifies a real authenticated owner sees Older pages on the first slice, reaches the original saved pages on the second slice, returns to the first page, and cannot publicly retrieve a fixture draft; forged zero cursors still fail with 422. The owner page view keeps the paging metadata separate from its per-row loop variable, so the Next link cannot be lost after rendering the table. No adult publishing or real hosted services are enabled.

---

## Unified source archive covers page editing migrations — 2026-11-21

The Lite/Pro source ZIP required-file inventory and reopened-archive regression now require PageRepository, PageRevisionSchema and PageNavigationSchema. A developer cannot build a seemingly complete release ZIP if either new additive page migration or the underlying page repository is absent. The archive still contains both edition descriptors, does not package customer secrets, and is not a signed production update or proof of deployment acceptance.

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

## Local moderator text-review keyset paging — 2026-11-21

Added `CreatorDraftReview::queuePage(actor, after)` with 50 private text submissions/page, timestamp+ID stable ordering, bounded opaque URL-safe cursors and mandatory fresh role check. `queue()` remains a compatible first-page helper. The moderator `/admin/creator-review` shows a Next link and first-page navigation; malformed cursor produces an explicit 422. Additive m15 migration adds an indexed `(status, updated_at, id)` lookup for supported MySQL/MariaDB. SQL regression seeds 51 submitted plans across two local creators and verifies complete, duplicate-free coverage, invalid cursor denial and no public media. This remains a self-hosted text-review tool, not provider-approved adult content publishing.

---

## Private creator review feedback timeline (m15) — 2026-11-21

- Added repeatable `CreatorFeedbackSchema::migrate()` after m14, installing local `ac_creator_feedback_history` with unique (draft_id,submitted_revision), reviewer ID, decision, private feedback and UTC timestamp. Reviewer `CreatorDraftReview::decide()` now writes the feedback snapshot **inside the same transaction** as the draft decision and numeric event. No approve/publish or vendor-side SaaS.
- `CreatorFeedbackHistory::forMember()` exposes at most 100 latest local review decisions to the **active, email-confirmed owner of the local creator profile**; `forOwner()` permits only a currently active owner/admin with `members.manage` for that site-local creator, including after creator suspension. No moderator route exposes the full private timeline. Both views escape feedback; old feedback survives creator revisions without leaking into the numeric-only event log.
- `/admin/setup` now applies m15 after m14. Member creator page, local owner private plans page and moderator review page **fail closed with setup-required messages** until migration. Source ZIP requires the new classes. Unit, disposable MySQL/MariaDB, real protected HTTP and encrypted backup/restore tests were expanded. Run latest CI before claiming green; actual Lite cPanel/Pro VPS, independent identity/consent and payment integrations still block release.
- On an m14 → m15 upgrade, the current `changes`/`rejected` feedback is backfilled only if the last numeric draft event matches that decision and proves its reviewer; `submitted_revision` is the current draft revision minus one. The unique per-revision index prevents duplicate backfill on retries. Prior overwritten notes and data without a matching provenance event are never fabricated. New decisions are snapshotted atomically.

---

## Local creator encrypted disaster recovery regression — 2026-11-21

- Expanded the existing full `tests/mysql_backup_restore.php` integration fixture: after adding m9 email + m13 creator + m14 review migrations, the source site seeds an owner-provisioned local creator with an archived revised draft, submitted draft, rejected draft, escaped private reviewer feedback and numeric audit history. The unchanged `SiteBackup` implementation already captures all ordinary `ac_` InnoDB tables.
- After the encrypted `.acfull` archive is created, the disposable DB is wiped and restored into an empty target. The test compares the full owner-only creator snapshot, verified inbox flag, additive migration markers and private moderation queue and asserts that none of the creator plans leaked into `ac_content`.
- Full MySQL 8/MariaDB 10.11 CI must pass before claiming recovery acceptance. Real cPanel/VPS restore rehearsals, media files/consent, merchant billing and independent production release still remain incomplete.

---

## Reversible local creator planning archives — 2026-11-21

Added `LocalCreators::changeDraftArchive(memberId,draftId,revision,action)`, allowing only `private → archived → private` on an active local creator's **own** unsent text. It locks creator then draft, requires current revision, retains DB row and 50-plan slot, increments revision, emits numeric-only `archived`/`restored` draft events and creator action records; no public media, age, payment or consent state changes. `ownDrafts` and owner protected draft detail include `archived` status, while submitted/rejected/changes plans cannot be archived.

The member page has CSRF-protected archive/restore forms; archived plans are read-only until restored. SQL/HTTP tests cover denied cross-account archive, CSRF, stale revision, archived edit/submit denial, owner event visibility and retention without publication. External release gates remain incomplete.

---

## Owner-only numeric text-review history and setup guard — 2026-11-21

- `LocalCreators::privatePlansForOwner()` now returns the most recent 100 `ac_creator_draft_events` for the local creator through a foreign-key-bound query. `CreatorAdminController::drafts()` renders a bounded, escaped, no-store/noindex owner-only table with local actor/draft IDs, action and UTC timestamp; review feedback is not duplicated in the action log.
- The owner plan detail now explicitly checks m14 `CreatorReviewSchema::VERSION` and prompts the administrator to apply Site setup before attempting the review-event query. Existing owner/manager role enforcement stays intact and moderator access remains denied.
- Test assertions cover numeric event history (four-event revision/rejection sequence), owner visibility and duplicate-text avoidance. Full independent provider and real-host release acceptance is still open.

---

## Local creator text-plan withdrawal — 2026-11-21

- Added `CreatorDraftReview::withdraw(memberId,draftId,expectedRevision)` for currently `submitted` plans only. It locks the active site-local creator identity and owned draft, verifies matching optimistic revision, returns status to `private`, increments revision, clears obsolete feedback, and logs a numeric `withdrawn` event without deleting review history. Rejected or already-reviewed plans cannot be withdrawn and no owner/moderator can withdraw on behalf of another member through the member route.
- `/members/creator` now offers **Withdraw submitted text plan** and a status message, with existing CSRF and current-account gates. Added disposable MySQL/MariaDB and authenticated HTTP denial/replay/no-publish tests. No SaaS, age/consent certification, publishing or billing workflow was added.

---

## Private local creator text review — 2026-11-21

- Added `src/Creator/CreatorReviewSchema.php` with m14 additive `ac_creator_drafts.review_note` and `ac_creator_draft_events` (numeric actor/draft/action/timestamp only); it runs after the local creator migration in `/admin/setup`. Existing created drafts stay `status='private'`, public ac_content and media tables are unchanged.
- Added `CreatorDraftReview::submit()` and `::decide()`, locking active creator/member and draft rows, requiring the expected revision and only private submission / requested-changes or rejection. A moderator can **never approve** or publish. Notes are bounded, validated, escaped and stored only with the private draft, not the numeric-only event log. Resubmission must follow a saved edited private version.
- New `/admin/creator-review` under the regular role/MFA/CSRF/session gates, accessible to local owner/admin/moderator, with only submitted text in the private queue. `/members/creator` shows creator-only states and feedback, disallows submitted/rejected editing, and uses existing member CSRF. Owner read-only creator detail still shows retained text and review feedback, not a public gallery.
- Disposable MySQL/MariaDB, real HTTP and unit/ZIP regression tests cover status transitions, stale versions, XSS, disallowed review decisions, cross-creator access and no publication. **Check the latest CI after changes**; full release blockers (real age/consent/providers, media scanning, adult payments, host acceptance, independent signoff) remain open.

---

## Local creator name editing — 2026-11-21

Site owner/admin may now rename a local creator's display name (including suspended profiles) using a CSRF-protected, revision-checked `/admin/creators` action and `LocalCreators::rename()`. The operation locks local manager/profile records, audits only numeric IDs/action, and never alters member credentials, draft data, status or legal access. SQL/HTTP tests verify outdated revisions and moderator denial as well as escaped updated labels in the member creator workspace. This is locally controlled self-hosted functionality, not an application or SaaS identity.

---

## Local owner-only read-only creator planning review — 2026-11-21

Added `LocalCreators::privatePlansForOwner()` and authenticated `GET /admin/creators/drafts/ID`, with local `members.manage` role checks, bounded draft list, HTML-escaped titles/summaries, noindex/no-store, and no edit/publish methods. The manager's local creator list now links to private plans, including retained drafts after a creator is suspended. A moderator cannot view these plans, and other creators' member sessions cannot access them. Disposable MySQL and real authenticated HTTP tests check owner, moderator, stale/unknown IDs, method denial and suspended access. Still no SaaS, adult-media publishing, independent age/consent or commercial launch approval.

---

## Owner-managed creator planning — 2026-11-21

- After explicit user correction that Adult_Core is **self-hosted and not SaaS**, added `CreatorSchema` local tables, `LocalCreators` owner-only grant/suspend/restore operations and owner role checks. This is **not** a creator application or vendor authorization. It allows only email-confirmed local members to receive private text-planning tools, leaves the account `role='member'`, and never touches `ac_age_verifications`, `ac_content` or payment.
- `CreatorAdminController` provides `/admin/creators` with numeric-only audit and counts, and `MemberController` provides `/members/creator` with only personal drafts, escaped output, CSRF and optimistic version checks. Suspension denies access immediately but retains drafts. Existing session login (no per-change password) and one Lite/Pro installer remain unchanged.
- Added direct validation, disposable SQL and authenticated browser checks, updated release ZIP required files; verify latest GitHub Actions before calling tests green. Next milestones include locally managed creator uploads only after verified legal provider/consent, malware scanning, secure review/publishing and approved payments. See `docs/LOCAL_CREATORS.md`.

---

## Creator intake course correction — 2026-11-21

The user reiterated **self-hosted, not SaaS**. Two experimental creator-application commits (`813f9a5` and `08c7f8c`) were reverted with `9463c5e` to restore the previously green source tree. Do not build a mandatory platform-wide creator application or vendor-managed creator approval workflow. Any future creator tools are locally controlled by the owner/admin of each individual installed site, without shared creator tenant state, and still require distinct age/performer consent and merchant implementation before production use. The single source package, key-selected Lite/Pro licensing, independent hosting, and existing moderator controls remain intact.

---

## Persistent administrator cookies and session-scoped staff authorization — 2026-11-21

Administrator login now issues an opaque HttpOnly/SameSite=Strict session cookie with a bounded 7-day lifetime (Secure on HTTPS) backed by private PHP session storage; active authentication expires after 24 hours idle or seven days total, whichever comes first. Cookie data is only a random session identifier, **not** a password or an authorization grant beyond the server session. The cookie can survive browser restarts. Logout, account suspension/role checks and moderator epoch revocation continue to gate each request.

Manager-only staff actions no longer require the current password on each create/suspend/restore POST. A fresh password login unlocks a **30-minute sensitive staff window** in server session metadata; after expiry, `/admin/staff` shows a single CSRF-protected manager-password unlock form. After one valid re-verification, managers can perform multiple staff actions during the next 30 minutes without reentering that password. All routine mutations continue to require active authorized DB identity, CSRF and existing server-side role checks. Pro upgrade, MFA disable, other high-risk special operations keep their separate existing protections.

Tests cover cookie expiry/HttpOnly, recreated browser with cookie, idle/absolute expiry, initial and renewed step-up, invalid CSRF/password, no per-action password, and existing moderator credential-rotation/forced-first-login lifecycle. Keep the current MySQL/MariaDB GitHub Actions green before marking acceptance. No plaintext password goes into the cookie.

---

## Credential restoration and staff MFA-readiness tightening — 2026-11-21

- Restoring a suspended moderator now checks the chosen replacement password against the prior password hash, rejects reuse and locks the active manager's DB role row for each privileged transactional mutation. The current account revocation epoch is still rotated.
- `StaffRepository::moderators()` now LEFT JOINs MFA enrollment state **without selecting secrets**; `/admin/staff` shows Enrolled/Not enrolled status. Disposable SQL and real HTTP tests check that new staff begin without an authenticator assertion. The owner still must communicate credentials securely; no email identity verification or production compliance is inferred.
- Confirm the latest two-job MySQL/MariaDB CI run before using this build. External release gates remain open.

---

## Administrator-managed moderator accounts — 2026-11-21

- New `StaffSchema` adds `ac_staff_actions` and `ac_users.staff_session_epoch` via repeatable `/admin/setup` migration. `StaffRepository` permits manager-only create/suspend/restore of **moderator** accounts with private audit, plus moderator-only self-password rotation (requires current password). There is no manager role grant, account deletion or automatic invitation email.
- New `StaffController` at `/admin/staff` checks `members.manage`, CSRF, password step-up via existing `AdminAccounts`, bounded per-IP attempts, and never echoes credentials. Its sidebar is manager-only; moderators still see only Moderation and Account security. `MfaController::security()` supports a moderator changing their own password and then signs out. 
- Suspension, restoration and self-rotation all increment the epoch; `AdminController::currentAdmin()` denies pre-rotation moderator sessions even after restoration. `AdminSession::beginMfa()` stores the epoch at the password step and `MfaController::challenge()` checks it before consuming a factor, blocking stale MFA after restoration.
- Expanded unit/MySQL 8/MariaDB 10.11/browser integration tests, source ZIP mandatory-class inventory and cleanup; check latest CI on `main` before stating all green. See `docs/STAFF_MODERATION.md`.
- No email identity assertion, external age/performer consent, merchant billing, production creator publishing, actual cPanel/VPS deployment or independent release review has been completed.

---

## Disposable MariaDB 10.11 CI coverage — 2026-11-21

Added `mariadb_compat` to `.github/workflows/php-tests.yml` using the official MariaDB 10.11 service, PHP 8.3 and a temporary port 3307. It runs private media/billing replay, fresh Lite MySQL-style installation, real localhost authenticated HTTP, and encrypted backup/restore suites against a **disposable** MariaDB database. The existing MySQL 8 + Apache container job is unchanged. This may expose SQL incompatibilities; **check both workflow jobs on latest Actions before declaring success**. Actual cPanel and Pro VPS deployment, DNS/TLS, software license issuer acceptance, independent adult-content verification/payment and security release gates remain incomplete.

---

## Moderator private draft detail UI — 2026-11-21

- Added `ModerationRepository::privateDraftDetail(id)` returning bounded summary for only `visibility='private'` unpublished `draft/quarantined/rejected` records: image ID/MIME/review state (never storage keys), performer ID/status/timestamps cues (never evidence documents), and private audited action notes.
- `AdminController` dispatches `/admin/moderation/draft/ID` to `ModerationController::draftPage()` after current account session/MFA/license gates. The new action enforces `content.moderate`, GET-only, no-store/noindex and escaped HTML. Pending preview links are included ONLY for still-pending images on a still-pending private draft, and image bytes are independently guarded by the existing review endpoint.
- The moderation queue now links eligible private records to the detailed review page. Disposable MySQL and real authenticated HTTP tests check administrator + restricted moderator access, invalid IDs, rejected media review-link denial, no storage key leakage and logged-out denial. Verify new CI before claiming green. All external compliance and payment/hosting launch gates remain open.

---

## Moderator sign-in and least privilege — 2026-11-21

- Updated `RolePolicy` so an existing active `moderator` can enter the authenticated admin area but still lacks `admin.dashboard.view` and all site management/edition/license/package permissions. `AdminController` and MFA success route redirect moderators to `/admin/moderation`; `ModerationRepository::requireModerator()` accepts the current active moderator for actual private-only review actions.
- `Layout::admin()` now optionally accepts a server-supplied role and renders only Moderation + Account security for moderator sessions, omitting Pro and billing/site links. `MfaController::security()` allows each authenticated privileged user, including moderator, to manage their own MFA with step-up password/code protection.
- Added role boundary unit/UI checks, disposable MySQL moderator role/draft test, and real HTTP test with **separate admin and moderator cookie jars**, proving cross-role admin controls return 403. Test-only CLI `seed-moderator` does not create production account-management workflows.
- Moderators still cannot approve/publish media, issue paid entitlement, change license or perform actual legal consent verification. No staff invite/provisioning interface yet. Confirm CI on the latest commit before declaring complete.

---

## Privileged account active-state check — 2026-11-21

`AdminAccounts::verifyCredentials()` and `authorizedById()` now query only `status='active'` privileged users. This prevents suspended accounts from logging in or retaining previously established admin sessions; the existing `AdminController::currentAdmin()` revokes the session when its DB-backed identity disappears, and `MfaController` uses the same authorized lookup. `tests/mysql_install.php` checks both login/current authorization, and the real HTTP suite uses disposable CLI-only `suspend-owner`/`reactivate-owner` commands to verify session invalidation and fresh login. Verify the latest CI result before claiming green. Moderator-specific login provisioning/navigation remains unimplemented: `RolePolicy` does not currently grant moderator `admin.access`, and no role widening was performed.

---

## Member-scoped read-only subscription overview — 2026-11-21

- Added `src/Membership/MemberSubscriptionSummary.php` to display only the logged-in member's recorded subscription rows under `/members` after the membership schema migration. The controller obtains the member ID from its protected session, not HTTP input. Rows show tier name, recorded status, dates and inactive/active tier flag, with escaped output and explicit **no verified payment claim**. No billing action is enabled.
- Added direct escaping unit tests, SQL membership scoping tests and a real HTTP synthetic inactive-tier record fixture. The source is required by the unified release ZIP checks. Latest CI result must be verified on the current commit.
- No approved merchant checkout, real invoice/refund, lawful age/consent process, creator publishing, cPanel/VPS acceptance or independent security certification has been completed.

---

## Administrator saved page preview — 2026-11-21

- Added `/admin/page/preview?id=ID` through existing `AdminController` current-user, MFA, HTTPS/loopback, license and site-settings permission gates; `SiteAdminController` accepts GET only, requires an existing saved page and emits private no-store/noindex. The preview uses `Layout::site(..., adminPreview: true)` and an explicit nonlive banner, with existing safe theme and no private image exposure.
- Page list/editor provide preview links for saved IDs, but the public `/p/slug` route still serves only `PageRepository::published()`. Tests exercise draft public 404, escaped HTML, authenticated preview, logged-out refusal and read-only method behavior. Verify latest CI before declaring green.
- Existing Lite/Pro licensing and all independent payment, identity, performer consent, publishing and real hosting release gates remain outstanding.

---

## Private draft editing, single-image rejection and tier label admin — 2026-11-21

- Added `ModerationRepository::renameDraft()` with an expected-title concurrency check, `rejectDraftImage()` for one pending draft image (private bytes retained), and `assignDraftTier()` for future tier references only. Each checks current admin/moderator DB role and locked private/pending status, persists private audit history, and cannot approve/publish.
- `ModerationController` exposes authenticated CSRF-protected POST forms, safely escaped audit-history viewer, and named private-draft selectors. Confirmed HTTP/SQL test coverage includes invalid CSRF, stale state, XSS display, pending preview revocation and unrelated-image preservation.
- Added `src/Membership/TierCatalog.php` + `src/Http/TierController.php` at `/admin/tiers` for owner/admin catalog **label management only**. New labels are explicitly `is_active=0` and lack any activation, pricing, billing or entitlement grant action. Disabled labels can be assigned to drafts without changing `draft/private/pending`; the SQL test proves disabled tiers refuse even a synthetic active membership. Added tier unit tests, live authenticated HTTP controls and release ZIP class inclusion checks.
- Existing one-codebase license-key-selected Lite/Pro contract and every production release gate remain unchanged. CI on the latest commit must be independently verified; genuine CWP merchant/identity providers, public publishing, media antivirus/re-encoding, cPanel/VPS hosting and commercial signoff remain outstanding.

---

## Real Apache container preinstall smoke (not actual cPanel) — 2026-11-21

- `public/.htaccess` now denies unexpected direct PHP/script resources at the Apache layer, including nested `assets/index.php`, while preserving root `public/index.php` and existing rewrite/private-file guards. This prevents accidental public executable files from running even before installation.
- New `tests/apache_preinstall_smoke.py` creates a disposable copy of the source and runs it under official `php:8.3-apache` with `AllowOverride All`, `mod_rewrite` and a host-loopback ephemeral port. It checks installer GET, safe CSS, private path routing and refusal to execute synthetic test-only PHP scripts. The GitHub Actions job includes the test, which requires Docker on the runner. **Check the latest Actions run** before claiming success; the prior parent main was green at [run 44930838879](https://github.com/afterburn25/Adult_Core/actions/runs/44930838879).
- No actual cPanel/VPS, TLS or production Pro worker is claimed; all ten workstreams remain open.

---

## Private member-library keyset scanning — 2026-11-21

`MemberLibrary::visible()` now uses a bounded (5,000 total candidates per request), descending-ID keyset scan in 250-record batches; every result remains subject to `Entitlements::canView()` before returning titles or IDs. A real disposable MySQL fixture adds 260 newer unentitled entries ahead of an older authorized item, then verifies the authorized item remains visible and cleans up the synthetic entries. This corrects the former fixed top-250 cutoff but is not complete member-library pagination/large-site performance acceptance. Pending authentic age/performer consent/payment remain fail-closed. Check CI for the latest commit.

---

## Pro receipt status/timestamp integrity — 2026-11-21

`JobInbox` persists `mac` over the complete receipt using a domain-separated HMAC keyed by the already validated job secret. Existing signed original envelopes remain unchanged; receipt status, `received_at`, payload, envelope signature and their serialized field ordering must match the stored HMAC on idempotent reacceptance. Tampering, missing receipt MACs and legacy unsealed records fail closed; **do not silently delete old receipts or mark jobs completed**, and future worker status transitions require an authenticated atomic update contract. `tests/pro_release_tests.php` adds status/timestamp/missing-seal regressions. Verify the latest GitHub Actions result before claiming green. This does not run FFmpeg or close Pro hosting/streaming release gates.

---

## Metadata audit mode visibility — 2026-11-21

The private-media audit now includes `metadata_scan_enabled` in its text/JSON aggregate report. The default quick scan explicitly reports `false`; an operator using `--metadata` sees `true`. This prevents a zero metadata-rejection count in a **non-metadata** quick scan from being mistaken for verification. The bounded scan still refuses to certify truncated or risky data, and never outputs filenames, keys or private content. Unit/CI acceptance should be checked against the latest commit.

---

## Pro signed job inbox storage-path guard — 2026-11-21

`JobInbox::accept()` now checks the private `storage/` parent as well as `storage/pro-jobs/` for unsafe symlinks before creating a signed receipt. `tests/pro_release_tests.php` verifies a valid signed job cannot be redirected through a linked parent. No media worker, FFmpeg execution or streaming capability has been deployed; production Pro acceptance remains pending. **Verify the CI result on the latest commit.**

---

## Legacy media delivery safety and opt-in metadata audit — 2026-11-21

- `PrivateMedia::safeToServe()`, `DesignImages::safeToServe()` now recheck MIME, size and metadata policy on delivery; protected member media and moderator preview call the former after their independent authorization checks, and Design Studio asset serving calls the latter. Previously accepted unsafe legacy files cannot be served solely due to a positive DB status. The disposable synthetic gallery fixture was updated to a correctly encoded PNG.
- `PrivateMediaAudit::inspect(true)` and `php scripts/audit-private-media.php --metadata [--json]` report read-only aggregate metadata risk and fail nonzero when a file is rejected or 100-file/64-MiB limits truncate the inspection. Default `inspect()` retains existing fast path. No secrets, filenames, identity evidence, private paths or bytes are printed, and no auto-remediation or deletion is performed.
- `tests/mysql_private_media_audit.php` uses only disposable MySQL plus temporary paths and verifies safe images, metadata rejection, privacy-preserving counts, no deletion and scan truncation. **Check CI for the latest commits before calling this green.**
- Real adult-appropriate merchant/identity/consent providers, legal retention, approved publication decisions, video encoding, cPanel and Pro VPS deployment, and independent security/compliance review remain open. No software/payment launch.

---

## Image metadata intake and symlink storage hardening — 2026-11-21

- Added `src/Media/ImageMetadataPolicy.php`: a bounded reject-only PNG/JPEG/WebP container inspector. It refuses common EXIF/GPS/XMP/ICC, PNG text/unknown chunks, JPEG APP1+ and comment markers, WebP EXIF/XMP/animation and unsupported/malformed/trailing structures before uploading to private media. It does not transform or virus scan pixels.
- Integrated pre-move into `PrivateMedia::storeVerifiedImage()` and `DesignImages::inspect()`, covering both pending private draft images and optionally public homepage decorative cutouts. Upload UI explains metadata-free export requirement.
- Added dedicated PHP format tests, real authenticated multipart PNG metadata refusals for both upload routes and source ZIP packaging expectations. Metadata-rejection PHP/HTTP code/test commit `dc19549fb` passed [GitHub Actions 44929628103](https://github.com/afterburn25/Adult_Core/actions/runs/44929628103), and the Design Studio integration commit `48e14d487` passed [GitHub Actions 44929729980](https://github.com/afterburn25/Adult_Core/actions/runs/44929729980).
- Added symlink-parent checks to `PrivateMedia` and `DesignImages` storage access, with no deletion or migration; corresponding CI requires confirmation on the latest commit. **No real hosted environment, adult ID/consent contract, billing processor, approved publication decision or independent certification is complete.** All ten release gates remain open.

---

## Installer pre-issuer public layout refusal — 2026-11-21

`InstallService::install()` now checks the shared `HostInspector::inspect()` `public_docroot` capability before calling `LicenseManager::verifyForInstall()`; it refuses an unsafe public tree even when the domain/docroot path matches, with no network license request or MySQL setup. Edition selection still occurs only after verified Cajun Web Pro issuance; there is no Lite downgrade fallback. The direct HTTP issuer-boundary test now copies bundled routing files and injects an unexpected public `.phtml` file, confirming fail-closed refusal before issuer contact. This is local/CI assurance, not actual Lite cPanel or Pro VPS certification; **check the new CI result**.

---

## Shared installer/public-root layout guard — 2026-11-21

The installer host inspector and operator preflight share `src/Capability/PublicRootSafety.php` for read-only detection of public symlinks, unexpected executable/secret files, copied private application directories, scan-depth truncation and unreadable scans. `HostInspector::inspect()` now grants the already-required `public_docroot` capability only when the declared document root is the source `public/`, the bundled index/router/Apache guards are present and safe, and the public tree scan passes. This keeps the original license-selected Lite/Pro manifest semantics. The disposable installer-MySQL fixture now copies the real public routing files. Added regression checks for a correct docroot containing an unexpected executable, a copied private directory and broken Apache guard. These local checks cannot certify actual cPanel/Apache or VPS behavior, merchant/provider approval or production readiness; **verify CI on the resulting commit**.

---

## Source ZIP audit packaging correction — 2026-11-21

- The **read-only private media audit** was implemented and passed disposable MySQL [run 44895957609](https://github.com/afterburn25/Adult_Core/actions/runs/44895957609). To ship the CLI with the unified Lite/Pro source release, `scripts/build-release.php` and `tests/release_distribution_tests.php` were extended to require `src/Media/PrivateMediaAudit.php` and `scripts/audit-private-media.php`.
- The first release build **failed** because its historical blanket `str_contains($name, 'private')` filter accidentally dropped the safe source filename `audit-private-media.php`. Fixed at [`5c05f06bc`](https://github.com/afterburn25/Adult_Core/commit/5c05f06bc08163ba40c21d82ea4757f856672a85): the builder now excludes actual `private/` directory paths and secret file extensions but retains code filenames containing that word. This is not a customer privacy incident or runtime data change.
- As of this handoff edit, the corrected release ZIP and full CI are **still being verified** (see [run 44896136878](https://github.com/afterburn25/Adult_Core/actions/runs/44896136878)). Do not claim final `main` green until the associated GitHub Actions run completes with `success`.
- All ten production acceptance workstreams remain open. The audit is **read-only** and never authorizes media, deletes legal evidence, or certifies real hosting/merchant/provider approvals.

---

## Private galleries, pending administrator uploads/previews and storage integrity — 2026-11-21

- `src/Membership/MemberLibrary.php` now supports `/members/library/ID` read-only details for currently authorized galleries, showing only approved images and guarded `/media/ID` routes. The library does not leak unapproved titles, storage keys or media records. Missing/unauthorized gallery details respond with a uniform 404. Responsive layout, safety-report links and XSS escaping are tested. Real MySQL exercises draft/consent denial plus pending, cancelled, expired and renewed **synthetic** membership states.
- `src/Moderation/ModerationRepository.php::attachPrivateImage()` and `/admin/moderation` now stage actual HTTP-uploaded JPEG/PNG/WebP into **unpublished private drafts only**, with role/CSRF validation, 8 MiB limit, 8192px edge/32Mpx area bounds, max 40 pending images per draft, locked draft check, append-only audit and best-effort orphan cleanup on DB failure. All inserted `ac_media` records are `pending`, not approved/published.
- The authenticated `/admin/moderation/image/ID` GET-only preview permits an active admin/moderator to view only pending images in still-private unreviewed drafts, with non-cacheable binary headers and no storage key in rendered links. Rejection/quarantine/logout immediately blocks further preview access. Member-facing `/media/ID` independently remains denied for pending drafts, and verified consent revocation quarantines protected album access.
- `tests/mysql_http_auth.py` uses real multipart HTTP and a **disposable** MySQL/temp media fixture to check valid/invalid uploads, CSRF, pending states, role/session gated private preview, draft rejection, logout, binary delivery to properly entitled synthetic member and revocation. A test originally expected 404 after a pending member's media request, but `PrivateMediaController` correctly returns 403 and **revokes the pending session**. The test was reordered to check pending media after the verified test fixture without interrupting the member account lifecycle. Full [run 44895776560](https://github.com/afterburn25/Adult_Core/actions/runs/44895776560) passed on `bc8c9efc7`; later docs builds also passed.
- `src/Media/PrivateMediaAudit.php` and `scripts/audit-private-media.php --json` provide **read-only, aggregate-only** missing/orphan/symlink/invalid-size auditing of private media files and database references. No deletion, keys, evidence or path names in output. New `tests/mysql_private_media_audit.php` passed with disposable MySQL/temp files in [run 44895957609](https://github.com/afterburn25/Adult_Core/actions/runs/44895957609); the release ZIP requires the audit class and operator CLI. Later packaging/docs commits require separate green CI verification.
- Production launch still **blocked**: real CWP four-SKU products/staging payments and license renewal; legal adult identity/performer consent/record retention and actual verification provider; approved adult payment checkout and entitlements; malware/metadata scanning, moderation **approval/publication**, safe video/Pro streaming, executable add-ons, real shared-host/Pro VPS deployment/recovery and independent security/privacy/legal/accessibility acceptance. Only the **one** license-key-selected Lite/Pro package exists, not two distributions or SaaS.

---

## Ten-workstream follow-up — verification replay and release ZIP integrity — 2026-11-21

- The **one unified Lite/Pro source ZIP** remains license-key-selected. `scripts/build-release.php` now refuses an output destination in the source/public tree, checks that required runtime classes and both edition manifests are included, validates every archive write/closure and emits a SHA-256 checksum sidecar. `tests/release_distribution_tests.php` verifies the ZIP by reopening it, checksums, presence of required source files and exclusion of private customer config/cache/journals. The digest is **not a digital signature**; a trusted signed updater/rollback is still unimplemented.
- `VerificationRegistry::apply()` now treats a provider event ID as idempotent only when subject, claim type, decision, reference hash and timestamp match; conflicting reuse is rejected. Latest decision ordering now spans **all configured providers**, so changing providers cannot replay an older verification over a newer revocation. `tests/mysql_install.php` adds disposable MySQL positive/negative controls, without connecting a real provider.
- `PackageStore::previewUpdate()` and `/admin/packages` provide **read-only** compatibility preview for disabled installed data-only signed packages with the same identity, publisher, module license SKU, newer version and edition/dependencies. This never changes files or registry and cannot be misrepresented as finished update/rollback. Signed update preflight tests passed in [run 44888530913](https://github.com/afterburn25/Adult_Core/actions/runs/44888530913).
- The **entire PHP/HTTP/disposable MySQL and new ZipArchive distribution suite passed** [GitHub Actions run 44888846590](https://github.com/afterburn25/Adult_Core/actions/runs/44888846590) on code/test commit `41429a2aebcd9ce4ab6c51a7e41c1cd4801419af`. Subsequent operator-documentation commits require their own green CI confirmation.
- Ten release workstreams remain open in `ReleaseMilestones` and `ReleaseReadiness`. For owner-controlled tasks, see `docs/WORKSTREAM_ACCEPTANCE.md` and `afterburn25/Cajun_Web#399`. No real CWP pricing/license issuance/processor sandbox, age/identity/consent provider, adult content upload, live Pro video worker, production signed updater, real cPanel/VPS install or independent security/compliance signoff was performed.

---

## Ten workstreams — billing, member library, moderation, Pro receipts, signed ZIP preflight — 2026-11-21

- Work progressed on all ten requested launch tracks where repository-only work was possible. **Real owner-controlled merchant/verification provider accounts and actual shared/VPS hosting were not accessible and were not simulated as complete.** `docs/WORKSTREAM_ACCEPTANCE.md` and `php scripts/release-matrix.php --json` enumerate the exact ten open production release gates, with `ReleaseReadiness::blockers()` now using the same list. The JSON/CLI remains nonzero until independent external acceptance.
- **Billing foundation (#4):** `src/Billing/WebhookInbox.php` now rejects conflicting duplicate provider event IDs (different verified digest or event type), even though exact retries remain idempotent. `tests/mysql_billing_inbox.php` runs real disposable MySQL in CI; no adult-approved payment provider, charges, or subscription activation has been added.
- **Private membership and design (#5/#8):** `src/Membership/MemberLibrary.php`, `/members/library` and the member dashboard link render only titles passing `Entitlements::canView()` (age, email, moderation, consent, tier and payment predicates). Anonymous/pending members see no protected titles; no unverified adult media or upload route is exposed. Real MySQL and HTTP tests verify default denial and escaping.
- **Verification/moderation (#3):** the admin content queue now shows expected/recorded/evidenced performer count and explicitly labels local evidence as **not** independent publication approval. Actual contracted provider and lawful recordkeeping checks are still required.
- **Pro infrastructure (#6):** the job inbox stores the signed original job envelope and refuses same-ID different-payload replays, malformed on-disk jobs and changed signatures. It still only records jobs and never runs FFmpeg or shells.
- **Signed add-ons (#7):** `PackageStore::previewUpdate()` and a CSRF-protected `/admin/packages` form verify a proposed signed ZIP for the same disabled package, trusted publisher, same paid SKU, increasing semantic version, edition/host/dependencies. They **do not** mutate installed files, change the package registry, apply the update or provide rollback. Executable extension runtime remains out of scope.
- **Installer/host/ops (#1/#2/#9):** existing universal key-selected Lite/Pro installer, preflight, private deployment, encrypted backup and restore fixtures were retained. Actual staged four-SKU catalog/renewal/merchant payment, real cPanel/VPS and production disaster-recovery exercises remain open.
- **Release (#10):** existing `release-check.php` and new `release-matrix.php` both deny launch while any of ten workstreams is incomplete. Independent security/legal/age/consent/processor approval is still required. No public adult-content launch or production 1.0 claim.
- **Latest green development/test checkpoint:** signed-update preview UI/test commit `db7cb5f34` passed full [GitHub Actions run 44888530913](https://github.com/afterburn25/Adult_Core/actions/runs/44888530913), and package documentation `589c3dce9` passed [run 44888567380](https://github.com/afterburn25/Adult_Core/actions/runs/44888567380). Later roadmap/agent/handoff documentation commits need separate verification.

---

## Paid add-on activation recovery and admin repair UI — 2026-11-21

- `src/Licensing/ModuleLicenseManager.php` creates `storage/packages/module-licenses/.pending-MODULE_ID` using private 0600 atomic storage **before** updating either module license config or signed cache. Runtime `status()` refuses a positive cached grant while its pending marker exists or is a symlink, so an interrupted write cannot enable the module. An issuer-verified activation retry rewrites both private files and removes the marker **last**. Unexpected storage failure retains the marker. No raw key is stored in the marker.
- `src/Installer/InstallationGate.php::eligibleForBackup()` refuses snapshots while any paid module activation marker exists, and `SiteBackup::safePath()` excludes nested module-license `.pending-*` markers and `.lock` from portable encrypted archives.
- The authenticated `/admin/packages` page now offers **Repair paid module license** for enabled paid modules whose module entitlement is blocked. It submits an ordinary CSRF-protected `enable` action with a freshly entered key, retaining the enabled flag; the module key is never displayed. Disabled paid modules retain their regular Enable/key form. This UI does not authorize cross-edition or incompatible packages.
- `tests/package_tests.php` covers simulated interrupted activation, invalid positive cache, backup refusal/recovery, symlinked marker refusal without issuer request or receipt alteration, reactivation through `PackageStore::changeStatus`, and repair UI HTML rendering without a key leak. `tests/backup_tests.php` asserts nested journals/locks are excluded while regular paid-module receipt files remain included.
- Complete GitHub Actions [run 44886306977](https://github.com/afterburn25/Adult_Core/actions/runs/44886306977) **passed** on code/test commit [`f2e6b73a`](https://github.com/afterburn25/Adult_Core/commit/f2e6b73a4a7396204c87108dd88c4a0e0fcb1595), including the package/backup regression suite, HTTP checks and disposable MySQL. Follow-on documentation commits still require their own verification.
- This is a fail-closed recovery mechanism, **not** a two-file filesystem transaction. Keep issuer keys private; neither a live CWP paid add-on, real customer subscription, actual cPanel/Pro VPS install, nor independent adult/financial/privacy compliance release has been certified. CWP four-SKU and staging/billing tasks remain open at `afterburn25/Cajun_Web#399`.

---

## Paid add-on core-entitlement and private-storage security — 2026-11-21

- `src/Licensing/ModuleLicenseManager.php` now checks that a paid add-on has a **ready signed core installation** and a currently authorized core license, in addition to its own distinct module SKU license. A revoked core, interrupted core license update, incomplete install, interrupted edition upgrade, backup restore, or backup maintenance blocks paid module activation/runtime—even when the module's own signed cache is freshly positive. Local validated core cache and established bounded outage grace are respected, not bypassed.
- The module manager rejects symlinked storage parents, private module-license directories, `.lock` and output files. It checks known-unsafe targets **before sending a module key** to the CWP issuer, preflights config/cache together under lock, then rechecks the storage ancestry before writing.
- `tests/package_tests.php` now builds a signed local core fixture and checks core denial, pending operations, loss of signed marker, file/directory/lock symlinks, refusal before a module issuer request, and preservation of the opposite private file on a rejected update.
- The complete PHP/HTTP/disposable MySQL workflow passed [run 44881459776](https://github.com/afterburn25/Adult_Core/actions/runs/44881459776) on commit `7d4207d03` for the initial core/issuer checks. Later maintenance-state, storage preflight and ancestry-recheck commits require their **own green CI verification**; latest at this checkpoint is `2c44e26ea` ([GitHub Actions](https://github.com/afterburn25/Adult_Core/actions/runs/44881683563)), which was queued at handoff authoring.
- Do **not** claim real paid-module purchase, CWP merchant sandbox testing, production-safe activation or supported live cPanel/VPS hosting. Actual owner-controlled four-SKU catalog, staging licenses, recurring payment acceptance and real hosting checks are still open in `afterburn25/Cajun_Web#399`.

---

## Edition upgrade / license replacement concurrency hardening — 2026-11-21

- **Shared private file locks:** `EditionUpgrade::locked()` now acquires `.edition-upgrade.lock`, then `.license-update.lock`, then `.license-refresh.lock` (nonblocking). The same-edition `LicenseManager::replaceLicense()` and `LicenseUpdateRecovery::rollback()` already hold the last two in that order. This serializes migration/recovery, license replacement and signed-cache refresh writes, and refuses conflicting operations instead of racing private license files.
- Symlinked licensing lock targets now fail closed in `EditionUpgrade`, `LicenseManager` and CLI rollback. `LicenseManager::status()` refuses an unsafe lock even with a fresh cached positive license receipt.
- `tests/upgrade_tests.php` checks that an externally held update or refresh lock prevents edition recovery. `tests/license_update_recovery_tests.php` checks replacement, installed-site runtime and CLI rollback refusal for symlinked shared locks; no private cache or marker is silently bypassed.
- GitHub Actions [run 44875613275](https://github.com/afterburn25/Adult_Core/actions/runs/44875613275) **passed** for code/test commit [`456c83e`](https://github.com/afterburn25/Adult_Core/commit/456c83e471ec023246fe2b9ba2a2a87bd20c3606), covering PHP/unit/licensing/recovery/upgrade, HTTP smoke and disposable MySQL acceptance. Later documentation commits need their own verification.
- This is local/CI concurrency proof, not certification of a live hosting filesystem, actual Pro VPS workers, Cajun Web Pro staging issuance, or sandbox payments. All real licensing and installer acceptance prerequisites remain open.

---

## License recovery final hardening and backup test determinism — 2026-11-21

- `src/Licensing/LicenseUpdateRecovery.php` now refuses a recovery marker readable by group/other users, because its signed snapshot contains the previous customer's license key. A bad mode, symlink, corrupt signature or incompatible edition remains locked for operator investigation and CLI recovery only. Unit tests verify owner-only permissions, unsafe-marker refusal, valid rollback and preservation of a prior **denied** license receipt.
- `tests/http_smoke.py` verifies that `storage/.license-update-pending` blocks public, admin login, package manager and installer routes with HTTP 503. `InstallationGate::eligibleForBackup()` also refuses while pending; the `SiteBackup::safePath()` regression now explicitly excludes the snapshot from portable backups.
- An unrelated intermittent CI failure in `tests/backup_tests.php` occurred because it blindly replaced a random ciphertext character with `0`, which occasionally made **no change**. The test now deterministically flips an actual AES-GCM ciphertext byte and separately modifies a tag byte, verifying both reject on every run. No crypto or archive acceptance policy was weakened.
- Full GitHub Actions [run 44875312976](https://github.com/afterburn25/Adult_Core/actions/runs/44875312976) **passed** on commit [`7782eab`](https://github.com/afterburn25/Adult_Core/commit/7782eabfdb838cf5c874e54c0a3aeff48700a64c), including all new license-rollback tests, deterministic archive-integrity tests, HTTP integration and disposable MySQL.
- **External gates remain open:** real CWP product catalog and non-master staging keys, authorized merchant sandbox payments/renewals, shared-host cPanel setup and Pro VPS installer/agent deployment. Nothing in this change accessed production or a real customer license.

---

## Signed interrupted license replacement recovery — 2026-11-21

- **New CLI-only recovery:** `src/Licensing/LicenseUpdateRecovery.php` and `scripts/recover-license-update.php --rollback`. Same-edition license replacement now takes both `.license-update.lock` and `.license-refresh.lock`, stores a private HMAC-signed snapshot of the previous license identity and cache at `storage/.license-update-pending` **before** rewriting either private file, and unlinks the marker only after both updates finish.
- `InstallationGate::status()` reports `license_update_pending` and blocks the **entire website and admin**, with a console recovery message. A marker also blocks backups via `eligibleForBackup()`, new key-replacement calls and direct `LicenseManager::status()` refresh. Do not delete the marker by hand.
- The server-console rollback requires a valid installed signed edition and untampered snapshot. It restores the prior license config and signed cache, or restores a prior absence of cache, under both locks; malformed snapshots or symlinked targets leave the marker and site locked. It does not contact Cajun Web Pro or grant authority. Restored explicit **denial** remains denied.
- `tests/license_update_recovery_tests.php` exercises a successful replacement, simulated crash after a license-config update, marker lockout, signed rollback, tampering, wrong edition, symlinks, 0600 marker permissions, missing cache, denial-preserving rollback and refusal when no pending update exists. `tests/http_smoke.py` covers public/admin/installer 503 lockout. The new PHP recovery suite is required in `.github/workflows/php-tests.yml`.
- GitHub Actions [run 44875004750](https://github.com/afterburn25/Adult_Core/actions/runs/44875004750) **passed** on commit [`17885e68`](https://github.com/afterburn25/Adult_Core/commit/17885e68d4dff31b385830739af676bc69079240), including the recovery tests, offline CWP licensing, HTTP gate and disposable MySQL suite. Later doc-only commits require their own green CI check.
- This is fail-closed recovery, **not** a fully transactional two-file filesystem update, a live issued key, an actual payment renewal, or a real Lite shared-host/Pro VPS deployment. The Cajun Web Pro catalog, staging/merchant sandbox and real hosting acceptance prerequisites remain open in `afterburn25/Cajun_Web#399`.

---

## License replacement private-target preflight — 2026-11-21

- `LicenseManager::replaceLicense()` now validates both `config/license.php` and `storage/license-cache.json` destinations **under the update lock before writing either**. Existing nonregular, symlinked or unwritable destinations abort without attempting a partial license-key replacement.
- `tests/licensing_tests.php` injects symlinked cache and symlinked license-config paths to ensure replacement is refused and the original counterpart remains unchanged. This specifically guards common failure paths; the two filesystem writes are **not a true atomic transaction**, so an unexpected I/O failure mid-update still requires operator recovery.
- CI [run 44874337290](https://github.com/afterburn25/Adult_Core/actions/runs/44874337290) passed with cache-target regression coverage on commit `04e50a295`. Reconfirm CI on any later commits containing the additional config-target test.
- No live payment, hosted installation, or actual staging license is involved; owner-operated CWP product provisioning and real test environments remain required.

---

## Exhaustive Cajun Web Pro SKU staging audit — 2026-11-20

- The operator-only `scripts/audit-cwp-staging.php` still uses owner-approved, private staging keys and an HTTPS-allowlisted issuer; it never issues licenses or initiates billing.
- `CwpStagingAudit::run()` now makes **five** bounded read-only calls instead of three: the expected product/domain, **all three** other Adult_Core SKU/domain combinations, and the unregistered-domain negative control. A grant tied to a second SKU cannot falsely pass simply because a different competing SKU was the sole negative check.
- `checks.other_products` now reports a separate verdict for each other SKU; `api_calls` is 5. Any transient response for any of the five controls yields `inconclusive` (not `passed`).
- `tests/cwp_staging_audit_tests.php` checks all twelve unexpected second-grant pairs, every per-SKU transport failure, exact call count/order, negative domain, no private key in reports and existing CLI/endpoint protection.
- GitHub Actions [run 44872344387](https://github.com/afterburn25/Adult_Core/actions/runs/44872344387) **passed** on code/test commit [`1e788a8`](https://github.com/afterburn25/Adult_Core/commit/1e788a8db30248c50388d3610e9c4e3c36365f57). Documentation in `docs/CWP_STAGING_PREFLIGHT.md` and `docs/CWP_CATALOG_SETUP.md` has been updated. Because CWP's default per-IP allowance is ten/minute, avoid running this five-call probe concurrently or twice in one minute without accounting for other traffic.
- **Do not infer live acceptance.** The real Cajun Web Pro catalog, non-master staging licenses, merchant sandbox checkout/renewal/cancellation, and cPanel/Pro VPS deployments have still not been exercised or certified.

---

## Installation recovery and runtime Host-validation checkpoint — 2026-11-20

- The installation gate now treats a missing signed completion marker alongside any generated private app-key, database config, license config or license cache as **invalid partial installation**, not a fresh setup. A symlink or directory occupying the marker path is invalid; an app-key or database configuration symlink is also rejected. Normal license-repair entry points remain available when a valid installation marker exists and the license itself is missing.
- Unit tests in `tests/run.php` now cover orphaned private configuration, license cache, symlinked app secrets and unexpected directories at the marker path. `tests/http_smoke.py` verifies that removing the completion marker from an otherwise installed fixture locks all routes with the invalid-state message rather than offering the installer.
- GitHub Actions [run 44872012796](https://github.com/afterburn25/Adult_Core/actions/runs/44872012796) **passed** on code/test commit [`2e677bed`](https://github.com/afterburn25/Adult_Core/commit/2e677bed651559fcf1512c6f9777d43d30d6aaf4). Earlier intermediate commits failed because pre-existing HTTP assertions expected the obsolete “Installer missing” state; those assertions were corrected.
- The installed-site license manager now rejects nonnumeric, missing, zero, overflow and malformed HTTP Host ports instead of ignoring them when the hostname otherwise matches. It retains valid host+port and www/apex behavior. Unit tests cover successful normalized hosts and negative malformed hosts.
- GitHub Actions [run 44872112110](https://github.com/afterburn25/Adult_Core/actions/runs/44872112110) **passed** on runtime-host validation test commit [`e9387e6f`](https://github.com/afterburn25/Adult_Core/commit/e9387e6f332e5d08826c4919df0a2e2aa8c03d86).
- **Release still blocked** by real CWP catalog/merchant sandbox/issued staging keys, live cPanel and VPS installs, and independent security/compliance acceptance. No real customer system or payment authorization was accessed.

---

## Service-level installer input and HTTPS safeguards — 2026-11-20

- Fresh installation now calls `InstallInput::validateForService()` inside `InstallService::install()` after taking the installation lock and checking existing state, **before** any vendor request, capability probe or MySQL creation. This validates normalized password, identity, database host/port/name/user fields even if a caller bypasses the browser form. Browser password-confirmation remains a UI concern; the service receives a single password and an integer port.
- Direct web service calls also enforce HTTPS for non-loopback requests; local loopback fixtures are permitted. The normal browser controller retains its own separate HTTPS, CSRF and rate-limit checks.
- `tests/run.php` exercises accepted normalized service fields and rejects malicious DSN host, invalid database name/port/type, missing or short administrator credentials, and invalid license host.
- `tests/installer_service_http.py` uses an isolated PHP server plus a mock denying issuer to verify unsafe DSN and wrong host are rejected before issuer traffic, a simulated non-local insecure request is denied, and simulated HTTPS or localhost requests reach the denying issuer but never finish installation. The simulated `HTTPS=on` fixture **does not constitute a real TLS test**.
- GitHub Actions [run 44871465911](https://github.com/afterburn25/Adult_Core/actions/runs/44871465911) passed on commit [`58acec8`](https://github.com/afterburn25/Adult_Core/commit/58acec8a54629be95ec30cd9a4bf00a83e501c4a), including real disposable MySQL, HTTP and licensing tests. Confirm the workflow again for any later documentation commit.
- **Still open:** actual CWP four-SKU catalog setup, owner-issued staging keys and merchant-sandbox payment/renewal acceptance, real shared-host and Pro VPS installation tests, and independent security/compliance certification. No production payment or real customer-server claims.

---

## Latest installer licensing/hostname guard checkpoint — 2026-11-20

- The one-package installer still derives Lite/Pro **exclusively** from the Cajun Web Pro product-bound license key, never from the host or a posted edition.
- Browser `install/index.php` and the underlying `InstallService::install()` now independently reject a licensed-domain versus HTTP Host mismatch (including deceptive hostnames and invalid ports) **before** any vendor validation or MySQL schema write. CLI-only disposable integration fixtures have no HTTP host and remain separate.
- `tests/installer_service_http.py` starts a real local PHP HTTP server with a direct service-call fixture and mock-denying issuer. It verifies the wrong hostname is rejected without contacting the issuer or writing private installation state, while a matching hostname reaches the mock issuer but its denial still blocks installation. The test is mandatory in `.github/workflows/php-tests.yml`.
- GitHub Actions [run 44871138011](https://github.com/afterburn25/Adult_Core/actions/runs/44871138011) **passed** on commit [`38966381`](https://github.com/afterburn25/Adult_Core/commit/38966381e9a5a81b2be7a04bffde687a6b789a06), including the direct-service HTTP test, existing browser HTTP tests, disposable MySQL installation checks and licensing contract tests.
- This is **not** a real Cajun Web Pro key, a merchant sandbox, cPanel acceptance, or live Pro VPS certification. Real owner-controlled SKU setup, staging issuance/renewal, and Lite/Pro host deployment remain the unresolved release prerequisites tracked in `afterburn25/Cajun_Web#399`.

---

## Current staging-host HTTPS acceptance — 0.8.12-host-https-audit-dev (2026-11-20)

`src/Capability/DeploymentPreflight.php` remains local-only and the new `scripts/staging-host-audit.py` adds opt-in **remote HTTPS read-only** checks. It rejects private/IP targets and pins a verified public DNS answer; no redirect, response-body read, license key or payment calls. It compares preinstall/installed gate routes, public stylesheet, security headers and 10 private URL paths. `tests/staging_host_audit_tests.py` tests negative URL/DNS controls, invalid responses and a *local PHP HTTP* preinstall fixture (not live HTTPS certification). CI must run this in addition to the existing gates. Owner must still perform real shared-host and Pro VPS staging, Cajun Web Pro licensed activation/renewals and production compliance review. See `docs/STAGING_HOST_HTTP_ACCEPTANCE.md`.

## Latest checkpoint — 0.8.11-host-preflight-dev

Added nonmutating `DeploymentPreflight` and `scripts/deployment-preflight.php`: explicit actual absolute document root, operator-expected Lite/Pro, preinstall vs installed phase, safe text or JSON statuses, unexpected public executable/symlink/private tree guards, authenticated Pro loopback capability and locally signed installed license edition matching. The key still selects edition at installation, not script switches. Added fixture and real local Pro demo handshake/negative token tests; included in CI. No actual host, production Pro worker or CWP paid subscription sandbox was accessed or certified. Read `docs/DEPLOYMENT_PREFLIGHT.md` and test real cPanel/VPS independently. Verify GitHub CI before claiming external acceptance.

## Latest continuation — 0.8.10-cwp-staging-dev

Added `src/Licensing/CwpStagingAudit.php` and CLI `scripts/audit-cwp-staging.php` for an operator-authorized, **read-only** three-check probe against an already-issued staging license. Key is input from a private Unix file outside repo, never argv/output; endpoints constrained to HTTPS Cajun Web Pro domain with opt-in for main host. `tests/cwp_staging_audit_tests.php` is offline, and CI retains existing MySQL tests. This does NOT provision any license product or test actual merchant transactions. See `docs/CWP_STAGING_PREFLIGHT.md`. Next actual gate is owner-controlled staging catalog and merchant sandbox, plus host recovery drills.

## Latest checkpoint — 0.8.9-backup-ops-dev (verified copy + maintenance)

`src/Backup/{BackupVerifier,BackupVault}.php`, `scripts/{verify-backup,ship-backup,backup-job}.php` and `tests/backup_vault_tests.php` extend v0.8.8 encrypted portable recovery. Existing archive format and fresh-empty-target restore semantics stay unchanged. Job must only run after operator actually pauses web/database writes, uploads and Pro workers; `storage/.backup-maintenance` blocks new site/admin HTTP, but not previously admitted PHP requests. Only recognizable older archives that successfully verify are removed after the latest private vault copy is verified. No cloud transfer, hosted cPanel/VPS drills, safe production automated quiescence, CWP real merchant sandbox, age/consent approvals or 1.0 certification is implied. Check GitHub MySQL integration CI before claiming a verified source. Read `docs/BACKUP_OPERATIONS.md`.

---

## Current implementation handoff — 0.8.8-backup-dev (portable encrypted disaster recovery)

`src/Backup/StreamArchive.php` is a versioned AES-256-GCM streaming record envelope with per-archive HKDF, monotonic sequence and authenticated end marker. `src/Backup/SiteBackup.php` strictly whitelists private paths, stores InnoDB schema/rows and private files, authenticates and stages all recoverable files before writing to an EMPTY new database, and leaves `storage/.backup-restore-pending` to lock HTTP on partial DB restore. `scripts/backup-site.php` and `scripts/restore-site.php` are CLI-only and accept a separate offline 32-byte key; target DB config is provided separately and must remain untouched. `install/` remains until manual removal after operator verification. Existing legacy `.acbak` is unchanged. CI must include `tests/backup_tests.php` and `tests/mysql_backup_restore.php` (disposable real MySQL). **Not production ready** until independent shared-host + Pro VPS restore drills, external backups/PITR and security acceptance. Read `docs/PORTABLE_BACKUP_RECOVERY.md`.


---

# Current handoff — Administrator MFA (0.8.7-mfa-dev)

Implementation extends the published 0.8.6.7 subscription HTTP-contract milestone in `afterburn25/Adult_Core`. New `src/Auth/{Totp,AdminMfa,AdminMfaSchema}.php` and `src/Http/MfaController.php` implement optional but enforcement-persistent admin MFA. Login checks `AdminMfa::enabledFor()` only after verifying password and starts a 5-minute challenge session if enabled; login never calls `authenticate()` before a valid second factor. `/admin/security` requires role permission, CSRF and password reauthentication. Rate-limited TOTP/recovery consumption runs in a MySQL row-lock transaction. `/admin/setup` migrates older installs; `Schema::create` creates MFA table for fresh installs. Recovery codes are displayed only once; private key is required to recover encrypted secrets. See `docs/ADMIN_MFA.md` for deployment controls. CI and exact GitHub result must be checked after publishing. Do not claim license billing, backups or production acceptance completed.

---

# 0.8.6.7 continuation — HTTP subscription contract simulation (2026-11-20)

Added `tests/cwp_subscription_contract.py` plus CLI-only fixture `tests/fixtures/cwp_subscription_client.php`, CI step and `docs/SUBSCRIPTION_ACTIVATION_TESTING.md`. Four separate CWP SKU products remain the sole source of edition/term; no new installer, local license minting or payment system. Covers initial paid-through activation, expiry, renewal recovery, confirmed revocation, wrong domain, vendor outages, outage grace expiration and verified domain move via actual PHP licensing classes over loopback HTTP. **Important:** CWP real products, prices, invoice settlement, recurring charges and issuance are still untested and unprovisioned. Do not call this end-to-end *live billing*. Admin warning: swapping a key does not cancel auto-renewal. Keep `install/` physical cleanup requirement; no public adult content or unverified payment activation. Run all CI and check published result before claiming GitHub verification.

## Latest milestone — 0.8.6.6-license-health-dev (2026-11-20)

One installer and the four existing exact Lite/Pro × once/month SKUs remain unchanged. `src/Licensing/LicenseManager.php` allows a privileged forced check while respecting the same signed vendor receipt and fail-closed domain/edition guard. `/admin/license` has two separate CSRF POST actions: `check` revalidates without revealing the key; `replace` keeps the existing same-edition restriction. Only `site.settings.manage` may POST; GET remains protected by dashboard access. Display the issuer's last response separately from effective outage-grace access. Tests: `tests/licensing_tests.php`, `tests/mysql_http_auth.py`, all established CI. Cajun Web Pro catalog pricing, publication, staging issuance and real subscription lifecycle remain unverified, owner-controlled follow-up; follow `docs/CWP_CATALOG_SETUP.md` and Cajun_Web #399. No production billing or adult-content release gates cleared.

## Current handoff — 0.8.6.5-upgrade-dev in-place Lite → Pro (2026-11-20)

**Repository:** `afterburn25/Adult_Core`, branch `main`. Added `src/Licensing/EditionUpgrade.php`, `scripts/recover-edition-upgrade.php`, admin `/admin/upgrade`, maintenance sentinel in `InstallationGate`, operator docs `docs/LITE_TO_PRO_UPGRADE.md`, unit and disposable-MySQL fault-injection tests. **One installer, no second database/software.** Existing signed Lite marker + DB must agree. Pro one-time/month SKU must validate for same domain, and a real Pro agent/capability check is required on production. Step-up password, CSRF, rate limit and explicit external backup acknowledgement protect the browser action. DB edition metadata and private vendor key/cache/HMAC marker change in place, retaining installed_at and all member/page/theme/media records. A private, MAC-authenticated snapshot covers only three metadata files. Crash sentinel blocks all HTTP requests; operator CLI `php scripts/recover-edition-upgrade.php --rollback` restores original Lite metadata if the upgrade is interrupted. If rollback fails, keep the gate locked and restore a separately tested external backup. **Local/disposable CI doubles do not establish real Pro agent, paid subscription or production certification.** Do not advertise video streaming as enabled. Verify entire CI on exact published GitHub commit, then document result.

## Session handoff — edition-bound monthly licensing (2026-11-20)

Version `0.8.6.4-license-dev`: **one installable software**, key-selects Lite/Pro and either one-time or monthly term via Cajun Web Pro's four separate product SKUs. Pro eligibility is independently enforced from verified host capabilities; cross-edition license replacement is blocked until safe migration is implemented. Paid static add-ons may declare signed `license_sku` and require a distinct vendor key before enabling; runtime eligibility rechecks signed entitlement caches. Lite admin displays disabled Pro-only features with **Pro Version Only** and an upgrade link to https://cajunwebpro.com (placeholder). No customer payment or live license issuance was performed. The earlier single SKU is retired for new installs; existing installations require a matching replacement key. Operator must register catalog offerings and test actual monthly billing externally. Details: `docs/CAJUN_WEB_PRO_LICENSING.md`. Release 1.0 still blocked by production compliance, payment, streaming and security gates.

# Handoff — Cajun Web Pro mandatory product licensing (0.8.6.3-license-dev)

- Inspected the **actual** `afterburn25/Cajun_Web` default branch: `site/api/validate.php`, `site/api/handlers/license.php`, `site/app/licenses/service.php`, `site/app/licenses/software_grants.php` and owner licensing documentation. Product-bound POST requires `license_key`, canonical `domain` and exact `product_id`; HTTP200 `authorized:false` is definitive denial, all non-200 replies are inconclusive. `version` is optional and is omitted until `SOFTWARE-ADULT-CORE` is registered and released. The target product was NOT found in source search.
- Added `src/Licensing/CajunWebClient.php`, `LicenseState.php`, `LicenseManager.php`. Browser installer now requires a key/domain, validates remotely before MySQL creation, writes owner-only `config/license.php` and a signed cache **before** the existing signed completion marker. `public/index.php` enforces runtime license before all regular routes, with hourly positive cache, 72h temporary-outage grace and immediate explicit-denial lock. `/admin/login`, `/admin/logout` and authenticated `/admin/license` remain available while locked; authenticated repair validates remotely again and stores state privately.
- Test doubles are **in-process only**: tests/licensing_tests.php, tests/mysql_install.php and tests/mysql_http_fixture.php simulate Cajun Web Pro without a real key or live charge. GitHub CI must verify MySQL/browser integration after publication. Never claim real Cajun Web Pro hosted activation, SKU provisioning or production readiness without external acceptance.
- Next: create `SOFTWARE-ADULT-CORE` as a software-family catalog product in Cajun Web Pro and publish a release; issue staging license and register hostname; verify hosted activation/revocation/timeouts; establish edition-specific licensing terms and version-based update rights.
- Keep `config/license.php` excluded from Git/ZIP, `storage/license-cache.json` excluded by the storage ignore, installation cleanup gate first, and developer suite tests green. Never add a second license generator/database to Adult_Core.

---

# Immediate handoff — 0.8.6.2-design-dev live drag-and-resize (2026-11-20)

Target `afterburn25/Adult_Core`, self-hosted Lite shared hosting / Pro verified VPS (not SaaS). Four built-in themes retained unchanged; **no new themes created**. This change adds `src/Design/CutoutPlacement.php` with validated 0–100 X/Y and 20–100 width, additive migration `m12_cutout_placement_v1`, new controls in `DesignController.php`, live pointer/touch/keyboard manipulation in `public/assets/design-editor.js`, positioned image CSS in `style.css`, persistent repository coordinates and matching public `design.css` output. `Layout::site` nests approved public hero art in a `cutout-positioner`. CSS and JavaScript require no CDN, inline scripts or executable plugin install.

**Deployment:** back up site DB, apply `/admin/setup`; until M12 is present `/admin/design` fails closed with a setup link. Existing M11 and selected cutouts remain private/public subject to original approval controls. No change to public web root or mandatory removal of `install/`.

**Tests:** PHP core/security/UI/design suites, Python HTTP smoke, PHP lint and Node JS syntax. Real MySQL CI integration verifies migration twice, saved X/Y/Width, invalid POST rejection, and public `design.css` values on a disposable database. Chromium interaction tests were run locally using a static fixture with the actual shipping CSS/JS; these test the pointer and slider UX without requiring authentication or MySQL. Record the actual CI outcome and commit SHA after publishing; do not claim CI passed until verified.

**Not implemented:** production member billing/media verification/Pro streaming, MFA, backup/restore certification, automatic background removal or arbitrary page-wide layering. Remain development-only.

---

# 0.8.6.1-design-dev theme-design maintenance

The four original `ThemeCatalog::THEMES` keys remain unchanged: `noir-gold`, `velvet-plum`, `midnight-teal`, `rose-ivory`. The studio has a clearly labelled no-cutout state and editable empty picture slot; it does **not** seed any model photos or change the existing CSS layouts. No schema migration is added. Separate edited concept previews (4 individual PNGs plus original references) are packaged outside the runtime ZIP, since gallery photographs in concept art are illustrative and unverified for public deployment. The runtime currently saves a single global selected hero image. Run `php tests/design_tests.php`, all prior unit/HTTP smoke checks and CI after publishing. See `docs/DESIGN_STUDIO.md`. Production blockers remain unchanged.

**Verified 0.8.6-design-dev CI:** [run 44826489627](https://github.com/afterburn25/Adult_Core/actions/runs/44826489627) passed PHP 8.3 lint/unit/security tests, disposable MySQL installation and authenticated browser checks (including real PNG upload and private/public decorative-image gating) for source commit [`c427cf9`](https://github.com/afterburn25/Adult_Core/commit/c427cf957a132749555cf5f777419b024cf240b6). Apache/cPanel production deployment, real adult verification, payments and streaming are NOT certified.

# Development server routing note

Run `php -S 127.0.0.1:8080 -t public public/router.php` for local tests. PHP's default static handling may return 404 for `/design.css`, so `tests/mysql_http_auth.py` and HTTP smoke tests now use this explicit router. Apache uses `public/.htaccess`; dynamic asset URLs must never bypass `public/index.php`.

# Current handoff — 0.8.6-design-dev Visual Design Studio

Target `afterburn25/Adult_Core`, **self-hosted** Lite (shared hosting) / Pro (verified VPS); **NOT** SaaS. Complete and manually remove top-level `install/` before any route unlocks. Only `public/` is web-exposed.

- Added `src/Design/{ThemeCatalog,DesignSchema,DesignRepository,DesignImages}.php`, `src/Http/DesignController.php`, `public/assets/design-editor.js`, new `public/assets/style.css` layout variants and `/design.css` / `/design/asset/{id}` routes. `/admin/setup` applies the additive `m11_visual_design_v1` migration. Admin `/admin/design` is gated by existing authentication, `site.settings.manage`, HTTPS and CSRF; uploads land in private `storage/design-assets/`.
- Four audited layouts and their palettes, custom validated accent, live homepage title/body plaintext edits, image choice and left/right/size placement. For now only homepage gets inline preview; additional pages use `/admin/page`. Automatic image background removal and drag-and-drop blocks remain future work.
- Public design assets only appear when explicitly public-safe, selected in settings, and home page is published. No real adult content or identity documents may be stored here. This declaration does not replace independent age/consent review. Signed third-party packages still CSS/data-only.
- Run PHP tests, `tests/design_tests.php`, HTTP smoke, disposable-MySQL integration (CI), browser visual review and the entire security suite. Record a **verified** GitHub commit / CI run, never assume a local artifact was pushed.
- Remaining blockers: administrator MFA, independent age/consent provider, adult media approval, approved payments, Pro video, signed updates/complete restore, host and security audit. **Not a production release.** See `docs/DESIGN_STUDIO.md`, `docs/SECURITY.md` and `docs/RELEASE_GATES.md`.

---

**0.8.5-ui-dev CI verification:** GitHub Actions [run 44777593038](https://github.com/afterburn25/Adult_Core/actions/runs/44777593038) passed PHP lint, all local-style UI tests and disposable-MySQL installer/admin/member browser integration checks for source commit [`f886e8684fa8be5c39ea953ff2c1373ea09bd937`](https://github.com/afterburn25/Adult_Core/commit/f886e8684fa8be5c39ea953ff2c1373ea09bd937). Production cPanel/VPS and security/compliance verification remain outstanding.

# Immediate handoff — 0.8.5-ui-dev (premium UI refresh)

**Repository:** afterburn25/Adult_Core; **edition model unchanged:** self-hosted Lite shared hosting and Pro verified VPS, not SaaS. **Status:** Development-only; no real adult media publishing, payment settlement or production Pro video.

**Implemented:** `src/UI/Layout.php` standardizes public, member, guest admin and authenticated admin shells. `src/UI/Dashboard.php` builds admin overview from existing authenticated session and MySQL-sourced metrics. `public/assets/style.css` adds premium dark design tokens, editorial hero/abstract CSS art, sidebar nav, form/editor/table layouts, mobile breakpoints, reduced-motion and focus behavior. `src/Website/PublicSite.php` loads and escapes the configured site name; `src/Http/{AdminController,SiteAdminController,ModerationController,MemberController}.php` delegate rendering to shared layouts. Routes, access gates, CSRF, DB schema and permissions are unchanged.

**Test:** `php tests/ui_tests.php`, all prior PHP/HTTP suites and PHP lint. CI definition includes UI tests; record actual GitHub run after publishing. The screenshots are generated with hard-coded **sample content only**, from production HTML generator methods, outside the web root—not live MySQL or authenticated browser screens.

**Next:** administrator MFA, accessible visual QA with real data on a disposable Apache/cPanel HTTPS deployment, complete publishing/verification and billing/Pro/release blockers. Avoid falsely claiming this UI refresh fulfills production 1.0. Keep manual installer-folder deletion gate and `public/` web root.

---

# Current handoff — 0.8.4-dev

`afterburn25/Adult_Core`: self-hosted Lite/shared + Pro/verified VPS. Not SaaS. This increment builds on 0.8.3-dev email ownership. **MISSING:** certified verification provider, performer consent release, production content uploads, payments, video worker, admin MFA, full restore/updater, cPanel audit.

**New:** `src/Member/{PasswordRecovery,PasswordRecoverySchema}.php`, `/members/recover`, `/members/recover/reset`, `EmailDelivery::sendPasswordReset`, per-user `session_generation` in `ac_member_password_resets`, enforced by `MemberController::member()` AND `PrivateMediaController`. Session generation invalidated on password reset; all pending accounts remain pending. `SiteAdminController::setup` migrates schema. Fixture and disposable MySQL/HTTP tests cover expiry, rate limits, replay, neutral responses, password hash changes, stale sessions. Read `docs/PASSWORD_RECOVERY.md`.

**Deployment:** back up DB; apply `/admin/setup` additive migration on a disposable test site before member routes work. Keep private `config/email.php` outside Git; mail() delivery remains unverified. Required root `install/` folder deletion gate unchanged. No direct public storage. All public adult uploads remain disabled.

**Next:** verify exact GitHub commit CI; test real Apache/cPanel and mail service on approved shared host; implement independent identity-provider integration, administrative MFA and trustworthy consent record review, then checkout and Pro services. Do not label 1.0 ready.

---

# Latest handoff — 0.8.3-dev (2026-11-20)

**Codebase:** `afterburn25/Adult_Core` (independently installed Lite shared hosting / Pro provisioned VPS, not SaaS). **Development only: NOT 1.0.**

- Member email ownership: `src/Member/{MemberEmailSchema,EmailConfirmations,EmailDelivery}.php`, opt-in `config/email.example.php`, browser `/members/email` and `/members/email/confirm`. Form uses GET preview and CSRF-protected POST, SHA-256 token hashes, 30m TTL, 5m resend cooldown, replays rejected. Registration stays pending, neither age nor identity verified. Mail delivery is optional and untested on real hosting.
- Additive migration `m9_member_email_v1` runs under `/admin/setup` after a backup; `Entitlements::canView` now requires email confirmation for members. Existing users without proof remain denied.
- Tests: local PHP/HTTP, `tests/mysql_install.php` email replay/expiry/cooldown, `tests/mysql_http_auth.py` browser confirmation using disposable **test-only** CLI token issuer. Check CI after the GitHub publish and record run result.
- Unchanged non-negotiables: remove `install/` manually to unlock site; protect public web root; signed add-on Lite/Pro compatibility; verification provider disabled by default; no adult content approval/publication; no payment or Pro streaming; `ReleaseReadiness` continues blocking 1.0.
- Next: replace generic verification HMAC scaffold with contracted provider adapter and verified subject binding, secure performer evidence retention and release procedures, admin MFA/recovery, payment onboarding, Pro worker, signed restores/updates and real cPanel/VPS certification.
- Read `docs/EMAIL_CONFIRMATION.md`, `docs/VERIFICATION.md`, `docs/RELEASE_GATES.md`, `docs/ROADMAP.md`, `AGENTS.md` and preceding handoffs below.

---

# Latest handoff — 0.8.2-dev (2026-11-20)

Worktree target: `afterburn25/Adult_Core`, self-hosted Lite/Pro, NOT SaaS. This build must **NOT** be labeled 1.0 or used with real adult media/payments.

Read `docs/VERIFICATION.md`, `docs/BACKUPS_AND_UPDATES.md`, `docs/MODERATION.md`, `docs/RELEASE_GATES.md` before extending these features.

- Added verification webhook scaffold disabled by default (`config/verification.example.php`); signatures use 32+ byte secret, five-minute time window, 24h event recency, idempotent provider-event receipt, same-subject monotonic ordering. `VerificationSchema` must be applied at `/admin/setup`. An actual provider-specific signed adapter and provider approval are NOT present.
- A signed third-party member-age receipt creates/revokes age records but never activates pending members; performer receipts reset status to pending and quarantine formerly published content. No media may be approved/published. `expected_performers=0` denies access when consent is required; pending roster can be declared from admin moderation (1–20 unique records), each still needs independent verification and legal review.
- Added limited private AES-GCM snapshot codec, CLI-only small-database export and signed release manifest verifier. No media backup, full restore, updater or rollback yet.
- Tests: `php tests/run.php`, `php tests/package_tests.php`, `php tests/advanced_tests.php`, `php tests/site_member_tests.php`, `php tests/pro_release_tests.php`, `php tests/moderation_tests.php`, `php tests/verification_tests.php`, `php tests/release_security_tests.php`, `python3 tests/http_smoke.py`; `tests/mysql_install.php` and `tests/mysql_http_auth.py` run only with **disposable** MySQL in GitHub CI. Record actual CI status after publication.
- Non-negotiable: install folder must be manually deleted before any website/admin access; no trusted code autoexec; add-on edition compatibility is fail-closed; do not change `ReleaseReadiness::productionReady()` to bypass unresolved gates.
- NEXT: independently audited provider adapter, binding performer consent to media, member email confirmation and MFA, legal review, real private uploads with safe moderation, approved billing, Pro worker, complete encrypted restore and versioned updates, actual cPanel and VPS tests.

---

# Latest handoff — 0.8.1-dev (2026-11-20)

Repository `afterburn25/Adult_Core` is self-hosted Lite/Pro, NOT SaaS. This continuation adds `/admin/moderation`, `/members/report`, `src/Moderation/{ModerationSchema,ModerationRepository}.php`, a reasoned audit trail, content quarantine/rejection, default-deny open-report entitlement check, and tests. The only supported next step for database upgrades is `/admin/setup` **after a backup**. Re-running it installs the new moderation schema.

**Very important:** no publish/approve endpoint, media upload UI, external age verification, complete performer consent review, adult payment processor, real Pro streaming or signed update/backup exists. Do not launch paid/adult content. `ac_moderation_actions` must be dropped before `ac_content` in disposable test cleanup. `tests/mysql_install.php` and `tests/mysql_http_auth.py` now exercise moderation/reporting in GitHub CI; local PHP environment lacks `pdo_mysql`. Check the latest GitHub CI result before saying these pass.

Next priorities: implement external age/identity and performer-consent verification interfaces; introduce safe evidence capture outside ordinary report notes; email verification and rate limiting for abuse, appeal and takedown processes; member and administrator MFA; then consider restricted-media publishing only after verification and security review. Continue to preserve installation folder cleanup gate and Lite/Pro compatibility. See `docs/MODERATION.md`, `docs/ROADMAP.md`, `docs/RELEASE_GATES.md` and `AGENTS.md`.

---

# CURRENT HANDOFF — v0.8.0-dev / 2026-11-20

**Repository:** `afterburn25/Adult_Core`. **Target:** independently installed Lite (shared hosting) and Pro (verified VPS). **NEVER SaaS.**

The latest changes are from the 0.6–1.0 development request, but **0.9, Pro runtime and production 1.0 are NOT complete**.

## Current additions

- `src/Website/{SiteSchema,PageRepository,PublicSite}.php`, `src/Http/SiteAdminController.php` and admin routes: additive schema, safe published plain-text pages, homepage, signed CSS styling, edit/publish tools.
- `src/Member/{MemberSchema,MemberAccounts,MemberSession}.php` and `src/Http/MemberController.php`: registration/login with rate limiting, separate member sessions and **pending** user status. `/join` is not adult age verification. No activation or paid content routes.
- Repaired `src/Membership/{MembershipSchema,Entitlements}.php` to support additive content/tier tables and default-deny age/moderation/consent checks; `src/Media/PrivateMedia.php`, `/media/{id}` authorization. No public adult media uploads or complete performer verification. Physical assets must stay outside public root.
- `src/Pro/JobInbox.php` safely verifies/stores deduplicated signed job messages, NO FFmpeg execution; `src/Support/ReleaseReadiness.php`, `scripts/release-check.php` intentionally block 1.0 claim.
- Tests: `tests/site_member_tests.php`, `tests/pro_release_tests.php`, expanded `tests/mysql_install.php`, `tests/mysql_http_auth.py`, and `tests/http_smoke.py` plus GitHub CI workflow.
- **Install gate remains mandatory**: valid signed completion state AND physical deletion of top-level `install/` before any site/admin route. All production services remain unverified.

## Tests and limitations

Run `php tests/run.php`, `php tests/package_tests.php`, `php tests/advanced_tests.php`, `php tests/site_member_tests.php`, `php tests/pro_release_tests.php`, `python3 tests/http_smoke.py`, and syntax lint. For real DB tests, use CI or an explicitly disposable MySQL instance; local PHP environment does not have pdo_mysql. CI verification of this snapshot must be recorded **after** run results are checked.

**Next:** Verify new MySQL/browser tests and cPanel, develop full protected upload/moderation/consent workflow, provider-specific age verification and payments, then Pro worker, backup/update/restore and security certification. Never imply the release is ready for customer deployment.

---

## Historical session notes

# Current session handoff — read before editing

**Date:** 2026-11-20  
**Target:** `afterburn25/Adult_Core`  
**Distribution:** self-hosted Lite on shared hosting and Pro on provisioned VPS; **not SaaS**.  
**Status:** M4 partially implemented: signed data-only ZIP package management + admin interface. Real MySQL/cPanel verification remains pending. Nothing has been pushed to GitHub from this session.

## Read first

`AGENTS.md` → `README.md` → `docs/ROADMAP.md` → `docs/DECISIONS.md` → `docs/SECURITY.md` → `docs/TESTING.md`.

## Non-negotiable product decisions

1. One universal browser installer. **Lite** requires PHP/MySQL plus safe hosting checks. **Pro** requires an authenticated provisioned Pro agent/capability probe; do not infer VPS via hostname or `exec()` availability.
2. One common core; add-ons declare edition, core-version, dependencies and capabilities and must be refused when incompatible. M4 now accepts signed **data-only** ZIP add-ons. Full runtime PHP plugins, upgrade lifecycle and theme renderer are not implemented.
3. Installation assets live in deletable **top-level `install/`**, outside the document root. The website and admin stay inaccessible until a valid signed completion marker exists AND the whole folder has been manually deleted.
4. The **only** public document root is `public/`. Keep credentials and private storage above the web root. Public content/memberships remain disabled until compliance and security controls are actually developed.
5. Update roadmap, handoff, changelog and tests after each development session. Never claim a GitHub push without remote confirmation.

## Implemented through M4 (partial)

- M1/M1.1: capability scanning, edition manifests, module compatibility checks and mandatory cleanup state machine.
- M2: browser install form, CSRF/session/rate limiting, strict database validation, initial schema and first `ac_users` administrator, generated private config and signed install marker. Local PHP & HTTP tests pass; actual MySQL install not locally verified.
- **M3:** `src/Database/Connection.php` opens only installer-generated private MySQL config. `src/Auth/AdminAccounts.php` verifies hashed credentials and checks admin role on each request. `src/Auth/RolePolicy.php` denies unknown permissions/roles by default.
- `src/Auth/AdminSession.php` creates private file sessions with 30-minute idle and 12-hour absolute expiry, login session rotation and CSRF token rotation, POST-only logout and cookie removal.
- `src/Auth/LoginRateLimiter.php` locks hashed-IP and hashed-email counters; admin login responses do not disclose whether an email exists. Requires HTTPS outside loopback.
- `src/Http/AdminController.php` handles `/admin/login`, `/admin` (read-only overview), and `/admin/logout`. The front controller applies `InstallationGate` **before** dispatching. GET `/` still serves the intentional development 503.
- `tests/run.php` now covers role/session/throttle; `tests/http_smoke.py` covers install gate and unauthenticated admin/CSRF; opt-in `tests/mysql_install.php` tests real MySQL auth/role revocation. New opt-in `tests/mysql_http_auth.py` covers real HTTP login/logout, session cookie, HTML-escaped site name, via `tests/mysql_http_fixture.php`. GitHub Actions jobs configured but not executed locally.

## Tests last verified here

```text
php tests/run.php                → 62 checks passed
python3 tests/http_smoke.py       → 20 HTTP checks passed
find src install public tests -type f -name '*.php' ... php -l  → all PHP files valid
```

**Local limitation:** PHP 8.4 CLI exists, but `pdo_mysql` and a MySQL server are unavailable. Do NOT call M2/M3 real database or HTTP login integration verified until tests pass on disposable MySQL/cPanel. There is no production login claim.

## Immediate next engineering work

1. Run `php tests/mysql_install.php` and `python3 tests/mysql_http_auth.py` with explicit `AC_TEST_DB_CONFIRM` and disposable MySQL credentials (see `docs/TESTING.md`). Fix any real MySQL/session defects and test on Apache/cPanel with safe web root.
2. Add administrator MFA, account recovery, security logging/alerting and hardened account management after integration. Consider first-admin `owner` migration separately; initial role is `admin` by design.
3. Implement signed add-on uploads/lifecycle (M4) with archive traversal/symlink controls, manifests, dependency/edition enforcement and signature checks. Do not execute untrusted PHP from manifests.
4. Only then implement private galleries, member auth, age and consent workflows, payment integrations and Pro features under the roadmap.

## M4 partial — signed extensions and themes (2026-11-20)

- Implemented `src/Package/PackageVerifier.php`: `PharData` ZIP read without ext-zip, max 8 MiB compressed/16 MiB uncompressed, no symlinks or path traversal, allowlisted static asset types, signed manifest + SHA-256 file hashes with operator-provisioned publisher PEM public keys. No signing private keys are in repository.
- Implemented `src/Package/PackageStore.php`: private staging under `storage/packages/`, serialized and atomic registry updates, default-disabled installed packages, module enable/disable, theme selection, dependency guards, Lite/Pro and current-capability checks, and runtime fail-closed `isRunnable`.
- Implemented `/admin/packages` (GET/POST): admin-authenticated upload/enable/disable UI with existing HTTPS, session, CSRF and installation gates. **Not locally tested with real MySQL authentication and file upload**; requires disposable MySQL/cPanel validation.
- Added `scripts/build-signed-package.php`, `examples/gallery-addon`, `examples/basic-theme`, and `docs/PACKAGES.md`. The offline builder was exercised with a temporary private RSA key; the resulting ZIP verified successfully.
- **No executable PHP/JS plugin support, theme rendering, updates, uninstall, backup/rollback, or safe public content features.** Admin package enablement only updates private registry state. The built-in example manifests in `modules/` remain metadata-only. Never claim functional gallery or video modules.
- Local tests: `php tests/run.php` (62 tests), `php tests/package_tests.php` (29 checks), `python3 tests/http_smoke.py` (23 checks), plus PHP syntax lint.
- Remote `afterburn25/Adult_Core` not updated from this environment; publish local release ZIP manually or after a verified GitHub write connection. Do not claim a push without success.

## Next tasks

1. Run opt-in MySQL installer and admin HTTP tests on disposable database, and test admin `/admin/packages` with actual authenticated cookies and file upload under real Apache/cPanel HTTPS.
2. Extend M4 with versioned package upgrade/rollback/restore, safe module runtime API, public theme renderer, trust store administration and security audit. Do **not** auto-execute user-uploaded PHP.
3. Add recovery/MFA, then M5 membership/content/consent controls; keep `/` disabled until compliance and protected media authorization are implemented.
4. Maintain `AGENTS.md`, `docs/ROADMAP.md`, `docs/SESSION_HANDOFF.md`, `docs/DECISIONS.md`, `docs/CHANGELOG.md`, and `docs/SECURITY.md` each milestone.

## 0.5.0-dev continuation (2026-11-20)

- Added `src/Theme/ThemeAssets.php`, `/theme.css` (installation-gated; only selected signed CSS served), `src/Membership/{MembershipSchema,Entitlements}.php`, `src/Billing/WebhookInbox.php`, `src/Pro/JobEnvelope.php`, and `scripts/build-release.php`.
- The draft membership schema is **not wired into existing installation migrations**: do not apply to a production database until a safe forward migration, restart-safe journal, and disposable MySQL tests are written.
- The webhook verifier is illustrative HMAC only; payment processors require provider-specific signatures, replay windows, state reconciliation and approved accounts before handling real events. The inbox must not directly grant membership.
- The Pro envelope is a protocol proof-of-concept, not a worker. Replay detection, asset lookup, authorized input storage, FFmpeg isolation, and segment authorization all remain undone.
- `scripts/build-release.php` requires PHP ext-zip, may only be used as a developer source archive, and does not create an install-ready signed release.
- Run `php tests/run.php`, `php tests/package_tests.php`, `php tests/advanced_tests.php`, and `python3 tests/http_smoke.py`. Real MySQL/cPanel/Pro coverage remains pending.
- Publish each change to GitHub and record the verified commit hash; do not claim GitHub is synchronized unless all files are confirmed.

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
