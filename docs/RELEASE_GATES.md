## Shared signed Pro receipt admission / operator maintenance barrier — 2026-11-22

`JobInbox::accept()` holds the same `SourceHttpRequestLock::enter()` shared 0600 persistent flock used by ready dynamic HTTP requests for the complete signed receipt admission and replay path; `SourceUpdateMaintenance::begin/end` already require nonblocking exclusive ownership. `ProJobAdmission::status()` tests the same shared lock so operator contention reports intake closed. `tests/pro_job_maintenance_tests.php` now covers independent PHP process holding operator-exclusive lock, new/duplicate signed receipt refusal, symlink denial and retry after release; `tests/source_http_maintenance_tests.php` tests Pro receipt acceptance before/during/after a real token-owned source marker. `tests/pro_release_tests.php` cleans up the persistent private lock fixture. See `docs/PRO_JOB_MAINTENANCE.md` / `docs/SOURCE_HTTP_MAINTENANCE.md`. **Does not drain executable Pro workers, existing pending jobs, DB writers, static assets or remote nodes; production activation blocked, ten release gates OPEN.**

---

## Publisher-signed candidate database contract — 2026-11-22

A unified Lite/Pro source ZIP now contains signed runtime `src/Database/release-schema-contract.json`; `SignedCandidateDbContract::compare()` requires this in *both* independently signed old/new archives, refuses migration declaration rewrites, duplicate IDs, schema revision regression, or modified ZIP signatures, and conservatively flags any source PHP change for manual SQL compatibility review. `SignedCandidateDbContract::inspectInstalled()` also checks the real signed installed Lite/Pro database and required historical migration IDs. Offline CLI: `scripts/review-candidate-schema.php`. Tests: `tests/signed_candidate_db_contract_tests.php` plus real MySQL/MariaDB `tests/mysql_install.php`. See [SIGNED_CANDIDATE_DB_CONTRACT.md](SIGNED_CANDIDATE_DB_CONTRACT.md). **NO migrations execute or become certified safe, NO source activation or production signoff. Ten launch gates remain OPEN.**

---

## Read-only installed MySQL/MariaDB migration ledger audit — November 21, 2026

`InstalledMigrationLedger::inspect()` and `scripts/check-installed-migrations.php` compare the signed installed Lite/Pro state against database edition, base schema and up to 250 InnoDB tables/500 migration ledger records. They produce a redacted deterministic ledger hash, refuse invalid entries/edition mismatches, and do not execute DDL. The real disposable MySQL 8/MariaDB 10.11 installation tests cover negative cases and confirm cleanup. **Candidate schema compatibility, database writer quiescence and migration rollback are not implemented**; see [INSTALLED_MIGRATION_LEDGER.md](INSTALLED_MIGRATION_LEDGER.md). Ten production gates remain open.

---

## Encrypted recovery archive identity tied to installed release — November 21, 2026

`SourceBackupEvidence::inspect()` and `scripts/check-update-backup.php` now semantically authenticate an external `.acfull` backup and require its signed installation app-key identity, Lite/Pro edition and source version to match the current installed site. Wrong-site backups are refused even when version and edition match; encrypted archive/vault must be owner-only and outside the site. The offline AES key is passed on stdin and never printed. All success reports retain `restore_drill_completed=false`, `database_writers_quiesced=false`, `source_activation_authorized=false` and `production_ready=false`; an encrypted backup check is not a production recovery drill. See [SOURCE_UPDATE_BACKUP_EVIDENCE.md](SOURCE_UPDATE_BACKUP_EVIDENCE.md). All ten workstreams remain open.

---

## Local dynamic PHP request drain before source maintenance — November 21, 2026

`SourceHttpRequestLock` makes each dynamic HTTP front-controller request retain a shared advisory lock for its entire PHP lifetime. `SourceUpdateMaintenance::begin()` and `end()` take a nonblocking exclusive lock, refusing maintenance while an active request holds a share; concurrent new requests see HTTP 503. Real cross-process/503 tests are included in CI. This still cannot drain Pro workers, external database writers, static web traffic or remote nodes, and a distributed filesystem may not honor local flock. Production rollback and cutover remain incomplete. See [SOURCE_HTTP_MAINTENANCE.md](SOURCE_HTTP_MAINTENANCE.md).

---

## Read-only integrity and outstanding-work audit for signed Pro receipts — November 21, 2026

`ProJobReceiptAudit` and `scripts/audit-pro-job-inbox.php` scan bounded private Pro receipt files and verify both the sealed receipt MAC and the originally signed job envelope. They refuse changed status, malformed JSON, extra/symlink/insecure files and unknown job identities, reporting pending/processing/done/failed counts without exposing payloads or secrets. **A clear local inbox never certifies external workers drained, database writers stopped or safe activation.** See [PRO_JOB_RECEIPT_AUDIT.md](PRO_JOB_RECEIPT_AUDIT.md); Pro and release workstreams stay open.

---

## Pro signed-job receipt intake respects source/recovery maintenance — November 21, 2026

`ProJobAdmission` makes the existing Pro signed `JobInbox` refuse new and replayed receipts during source HTTP maintenance, backup maintenance, backup restore, edition upgrade or license update pending markers, including symlinks. `tests/pro_job_maintenance_tests.php` covers denial and safe resumption. Existing workers, queues, FFmpeg, DB writes and streaming are **not drained or stopped**; production Pro release remains blocked. See [PRO_JOB_MAINTENANCE.md](PRO_JOB_MAINTENANCE.md).

---

## Token-bound source-update HTTP front-controller maintenance — November 21, 2026

`SourceUpdateMaintenance::{begin,status,end}` requires an exclusive private operator lease. It creates a private, unreusable marker that `InstallationGate` checks before member/admin routes; during source maintenance dynamic HTTP returns a generic **503** page with retry header. Wrong token, damaged/symlink marker, and interrupted backup recovery fail closed; the coordination lease cannot be released until maintenance is ended. **Static web files, in-flight requests, DB writes, independent scripts and Pro workers are not paused; this is not a production-safe update lock.** See [SOURCE_HTTP_MAINTENANCE.md](SOURCE_HTTP_MAINTENANCE.md). All ten production workstreams remain open.

---

## Exclusive offline source-operation coordination — November 21, 2026

An operator can now use `scripts/source-operation-lease.php` to claim a private source-operation slot exclusively, check its status, and release it with a random token supplied over stdin. Interrupted claims remain blocked; raw tokens are never stored in the receipt or exposed by status. The lease **does not** enforce HTTP maintenance, pause database writes, lock Pro workers, or authorize source activation. See [SOURCE_OPERATION_LEASE.md](SOURCE_OPERATION_LEASE.md); all ten production workstreams remain open.

---

## Independent installer-free runtime stage verification — November 21, 2026

`RuntimeSourceStageVerifier::verify()` and `scripts/verify-runtime-stage.php` independently verify every signed immutable runtime file and the runtime-only receipt, refusing injected installer paths, extra PHP, missing/altered files, symlinks, unsafe permissions and incomplete stage markers. It is **read-only**, never activates the software, and leaves all ten production release workstreams open. See [RUNTIME_SOURCE_STAGING.md](RUNTIME_SOURCE_STAGING.md).

---

## Installer-free signed runtime-only staging — November 21, 2026

`RuntimeSourceStage::prepare()` and `scripts/stage-runtime-source.php` can copy **only** approved signed runtime source files (`VERSION`, `src/`, `public/`, `config/editions/`) into a new inert 0700 private destination, rechecking every file hash. The removed browser installer, mutable themes/modules, developer files and all customer config/storage remain excluded. **It is not a complete runnable installation, source switch, atomic updater, database migration, or rollback.** See [RUNTIME_SOURCE_STAGING.md](RUNTIME_SOURCE_STAGING.md); all ten release workstreams remain open.

---

## Separately signed previous source recovery material — November 21, 2026

The offline `scripts/check-source-rollback-material.php` command validates three distinct states: a clean installed runtime matching the trusted signed old ZIP, an independently intact private old-source stage, and a separate signed private candidate stage. It refuses tampered or incomplete prior source, stage collisions, untrusted signatures and version inconsistencies. **It never performs rollback**, undoes a migration, touches customer data, approves a license or activates PHP source. See [SIGNED_ROLLBACK_MATERIAL.md](SIGNED_ROLLBACK_MATERIAL.md). All ten production acceptance workstreams remain open.

---

## Offline signed runtime transition preflight — November 21, 2026

Source transition review now authenticates **both** the installed old ZIP and candidate ZIP, re-verifies the candidate's private staging tree, and compares the complete installed runtime against a signed baseline before planning any source change. It excludes `install/`, add-on modules, mutable themes, customer config, private storage, and developer-only source from runtime changes. Local code modifications, unrecognized PHP, unsafe symlinks and downgrades fail closed. **No source switch, database mutation, live update, rollback, licensing approval, or launch certification is implemented.** See [SIGNED_RUNTIME_TRANSITION.md](SIGNED_RUNTIME_TRANSITION.md); all ten release workstreams remain open.

---

## Independent private staged-source verification — November 21, 2026

`scripts/verify-source-stage.php` can independently reauthenticate the signed release ZIP and inspect every staged source file, its private permissions and signed inventory membership. Extra/missing/modified files, symlinks, incorrect receipt metadata and incomplete stages are rejected. The verifier cannot activate a stage, perform an atomic update or close any external production release gate.

---

## Private authenticated source staging — development only (2026-11-21)

The offline CLI can verify a signed unified Lite/Pro ZIP and stage every source file in a new private 0700 directory with 0600 files, per-file rehashing and an explicit complete/incomplete marker. It refuses insecure parents, existing paths, symlinks and paths inside the live application. This is NOT a live updater, atomic source swap, database migration, deployment or production acceptance. See [PRIVATE_SOURCE_STAGING.md](PRIVATE_SOURCE_STAGING.md). All ten release workstreams remain open.

---

## Signed-source update preview remains non-destructive — 2026-11-21

The authenticated source ZIP can be compared against an existing local source root with `scripts/preview-source-update.php`. The report is read-only and limited to signed paths; it does not assess deletions, version upgrade eligibility, migrations, rollback or live compatibility. See [SIGNED_SOURCE_UPDATE_PREVIEW.md](SIGNED_SOURCE_UPDATE_PREVIEW.md). Workstreams 7, 9 and 10 remain open.

---
## Offline authenticated source distributions — November 21, 2026

Maintainers can now sign a complete unified Lite/Pro source ZIP with a private, offline signing key and verify every ZIP entry with a separately trusted public key. The detached manifest is signed before verification, and bounded streaming ZIP checks refuse tampered/extra/missing files, unsafe paths, secret files, symlinks and case-fold collisions. See [SIGNED_SOURCE_RELEASES.md](SIGNED_SOURCE_RELEASES.md) for commands and trust requirements. This is **not** a signed atomic in-place updater, a managed rollback system, a production key-custody attestation or an approved executable plug-in loader. Workstreams 7, 9 and 10 remain open.

---

## Ten-workstream production release matrix — November 21, 2026

The canonical, fail-closed CLI is `php scripts/release-matrix.php --json` (machine-readable) or `php scripts/release-matrix.php` (operator-readable). Both report **all ten** currently uncompleted production acceptance workstreams and intentionally exit nonzero until verified release authorization. The existing `php scripts/release-check.php` derives its blockers from the same ten workstreams. See [WORKSTREAM_ACCEPTANCE.md](WORKSTREAM_ACCEPTANCE.md) for the concrete missing external tests and operator-owned evidence.

Development work now includes a private entitlement-filtered member library, a read-only performer-evidence summary, stricter verified-billing-event replay integrity, and signed Pro job receipt tamper detection. **None** enables unverified adult uploads, member charges, user entitlement grants, production FFmpeg execution, or a claim of independent age/consent compliance. A green CI run certifies none of those external dependencies.

---

## Administrator MFA milestone (0.8.7-mfa-dev)

New authenticator MFA and recovery paths are implemented for review and CI, but do not certify production authentication. Audit RFC 6238 behavior, recovery and backup of private application key, real HTTPS cookie settings, manual recovery policy, device compromise, independent browser and database tests, and administrator role changes before release. Other production gates (age/identity and performer consent, permitted billing, streaming, backups and release upgrade) remain open.

## 0.8.4-dev status

The member password-recovery security increment is implemented, but does NOT close the production email-deliverability, administrative MFA, compliance, payment, Pro-worker, backup/restore or hosting-certification gates. The release check must continue to refuse production 1.0.

# Release gates — proposed versions 0.6 through 1.0

**This is a development snapshot, NOT Adult_Core 1.0.** No user account, adult content or card processing should be launched from this code. `php scripts/release-check.php` intentionally exits nonzero until all production gates are closed.

## Completed in this development iteration

- `0.6` **alpha**: additive `ac_pages` schema, safe plain-text editor, draft/published page lifecycle, published public pages with HTML escaping, header/navigation; selected signed theme CSS applied. Only the application core's trusted template code renders content. No arbitrary uploaded PHP/JavaScript.
- `0.7` **alpha**: separate member sessions, CSRF, password hashing and rate limiting, registration and login. All new accounts are `pending`. No age verification is inferred from registration; there is no self-approval route.
- `0.8` **foundation + safety-triage prototype**: additive private content tables, default-deny `Entitlements`, moderation and consent evidence predicates, private image key and storage logic, guarded `/media/{id}` endpoint. Quarantine/report/admin rejection are now present; no content upload or moderation approval workflow or end-to-end consent verification; do not use for public adult media.
- `0.9` **foundation inherited from 0.5**: verified-event inbox and HMAC utility. No approved processor, checkout, recurring billing, refunds, settlement reconciliation or payment webhook endpoint.
- `0.10` **foundation**: signed HMAC job envelopes, replay-safe private Pro job receipt with narrow payloads. No FFmpeg worker, VPS provisioning, streaming or WebSocket runtime.
- `1.0` **release gate**: `ReleaseReadiness` and `scripts/release-check.php` intentionally block claims of a certified production release.

## Before release 1.0

- Independent assessment of every route and session boundary (CSRF, auth, role checks, IDOR, SSRF, media delivery, upload processing, secrets, logs, database errors).
- Mandatory adult age/identity verification for all applicable jurisdictions, records and consent workflow for every depicted performer, moderation, takedown and complaint workflows, retention, audit and review.
- Compatible adult-content payment provider approval, correct processor-specific signed webhooks/replay windows, refunds and chargebacks, reconciliation and entitlement revocation.
- Full Pro deployment, transcode workers, adaptive streaming, authentication on media segments and live-stream security.
- Reliable package update/rollback, integrity-protected encrypted backups, restore drills and installer recovery across shared hosting and VPS.
- Mail verification, administrative MFA/recovery, abuse controls, accessibility, privacy, logging and performance tests.
- Reproducible distribution package (assets, dependencies and checksums), Apache/cPanel installations, shared-hosting limitations and real VPS tests.

## Manual verification

`php tests/run.php`  
`php tests/package_tests.php`  
`php tests/advanced_tests.php`  
`php tests/site_member_tests.php`  
`php tests/pro_release_tests.php`  
`php tests/moderation_tests.php`  
`python3 tests/http_smoke.py`  

For disposable MySQL CI, see `docs/TESTING.md`. Testing a localhost PHP development server is not proof of Apache/cPanel compatibility.

Never add payments or adult uploads until the relevant requirements are implemented and independently verified.
