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
