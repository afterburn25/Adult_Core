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
