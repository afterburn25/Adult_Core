## ADR — Creator accounts are site-owner controlled, not a software-platform application (2026-11-21)

Adult_Core is **one self-hosted software product installed separately on each customer's own hosting**, never a SaaS creator network. A customer's **Cajun Web Pro software license** selects Lite or Pro at installation; it does **not** create a centrally hosted site, require creator approval by the Adult_Core vendor, or confer access to any shared creator network.

Creator accounts, if implemented, belong solely to the **individual installed website** and are managed locally by **that site's owner/admin**, with local roles and content permissions. A vendor-facing or mandatory creator application/approval workflow is explicitly out of scope. The unrequested experimental `/members/creator` and `/admin/creators` intake was reverted before release. Future creator dashboards can be designed as locally provisioned capabilities, without bypassing independent adult age/identity, performer consent, moderation, payment or hosting requirements.

---

## ADR — Shared public-root safety is an installer prerequisite (2026-11-21)

The exact pathname of a web document root is not sufficient to prove safe application hosting. A malicious or accidental public PHP file, symlink, copied private tree or missing Apache rule can expose private code/data even if `realpath(document_root) === realpath(public/)`. Centralize read-only source-tree checks in `PublicRootSafety`, reuse them from the deployment preflight and `HostInspector`, and make the existing Lite/Pro `public_docroot` capability conditional on those checks. Reject unsafe layouts **before transmitting the customer license key or creating the MySQL schema**, without modifying installed edition manifests or allowing Pro to downgrade. Source archive verification must require this scanner and bundled `.htaccess`. No local scan is a substitute for independent HTTPS routing and real-host acceptance.

---

## ADR — Explicitly verified private backup vault and coordinated maintenance (2026-11-20)

A backup copy is not trusted merely because the encrypted file exists or `fwrite()` succeeded. Independently authenticate and structurally validate complete files and signed installation state after transfer; delete only known-name old *verified* archives, after a new verified copy is durable. No filename or mount path can establish remote replication. To support shared hosting with no background service, provide optional CLI/cron entry points while refusing ordinary jobs unless the operator confirms traffic and writers are drained. Backup maintenance fails new HTTP routes closed and crash markers persist. No claim of automatic Pro-agent control, real point-in-time consistency or hosted recovery readiness follows from these controls.

---

## 2026-11-20 — First-party TOTP MFA, no external secret-sharing

An authenticated password login for an MFA-enrolled administrator becomes an unprivileged, expiring challenge, not a regular admin session. Use RFC 6238 TOTP and app-key-encrypted secrets, with single-use HMAC recovery codes and authenticated, replay-safe consumption. Do not outsource enrollment QR creation to a third-party service or bypass MFA during vendor-license recovery; `/admin/mfa` is explicitly allowed by the commercial-license gate. Existing installations may add the schema through the authorized setup route and a prior enrollment marker blocks table/row-deletion downgrade. No public adult media, billing or licensing verification changes.

## ADR — In-place edition upgrade and crash containment (0.8.6.5-upgrade-dev)

A Lite-to-Pro change is an **edition metadata transition**, never a new installation and never a web-form edition override. It requires a new vendor-authorized Pro SKU, the original licensed domain, verified host Pro agent/capabilities, and strict permission + step-up credential check. Since MySQL and filesystem edits cannot commit together, create a private HMAC-authenticated snapshot of existing signed install/license metadata, write a maintenance sentinel **before** DB mutation, then update the DB, private license file, HMAC receipt, and signed marker. Clear the sentinel only after consistency checks; on ordinary exceptions restore Lite, and on process crash require operator-side CLI rollback. This does not back up content/media or promise zero downtime on active clients; production users must perform an off-server full backup and maintenance window. Do not implicitly enable unimplemented Pro features or trust user-selected edition values.

## Design decisions — edition-bound monthly licensing (2026-11-20)

Version `0.8.6.4-license-dev`: **one installable software**, key-selects Lite/Pro and either one-time or monthly term via Cajun Web Pro's four separate product SKUs. Pro eligibility is independently enforced from verified host capabilities; cross-edition license replacement is blocked until safe migration is implemented. Paid static add-ons may declare signed `license_sku` and require a distinct vendor key before enabling; runtime eligibility rechecks signed entitlement caches. Lite admin displays disabled Pro-only features with **Pro Version Only** and an upgrade link to https://cajunwebpro.com (placeholder). No customer payment or live license issuance was performed. The earlier single SKU is retired for new installs; existing installations require a matching replacement key. Operator must register catalog offerings and test actual monthly billing externally. Details: `docs/CAJUN_WEB_PRO_LICENSING.md`. Release 1.0 still blocked by production compliance, payment, streaming and security gates.

## 2026-11-20 — visual cutout geometry and safety

Store integer X/Y/Width percentages as additive design settings rather than editor-generated HTML or arbitrary CSS. Render only constants interpolated from strict validation to `/design.css` (no user-authored style strings). The editor communicates with the existing authenticated CSRF-protected route and cannot change the public-safe media flag; private images remain preview-only. Avoid per-image public endpoints, public storage exposure, unsandboxed plugins, or duplicate theme IDs. Existing images have legacy size presets converted once on upgrade.

## Visual design and decorative media separation (0.8.6-design-dev)

- Core-shipped audited theme layouts differ structurally and are CSS/escaped-PHP only. Signed external packages remain data-only: accepting a package never runs uploaded PHP/JS/HTML.
- The editor stores escaped **plain text** with existing `PageRepository`; preview `contenteditable` is only an admin UI convenience. All mutations require authenticated admin, CSRF and HTTPS.
- Decorative homepage photographs live separately in `storage/design-assets` (not `ac_media`), default private, and are public only after explicit non-explicit/rights-cleared acknowledgment, selected asset, and published homepage. This is NOT an age/performer consent approval mechanism and must not replace protected adult-media authorization.
- Custom accent CSS is server-generated from a strict `#RRGGBB` value through `/design.css`, rather than injecting inline HTML styles or scripts from stored content.

## 2026-11-20 — Unified, script-free premium UI

All web-facing layouts live in `src/UI/Layout.php`, with truthful dashboard components in `src/UI/Dashboard.php`. Controllers continue performing authorization, validation and escaping before calling layout helpers. Layout methods escape titles, nav names and site branding and only accept trusted HTML assembled by server controllers. The UI does **not** introduce client-side executable scripts, remote web fonts or external analytics. Admin sidebars are rendered only after the existing gate and authenticated role revalidation; login and errors use a guest shell. Selected verified theme CSS can still override presentation, but signed assets never execute PHP or JavaScript. The design has no capability to publish unverified content or grant entitlements.

## 2026-11-20 — Member password resets and session revocation

- Member password recovery requires a previously **confirmed** email address, not self-reported age. Only `member` roles can reset through public routes; admin MFA and admin recovery stay separate.
- Store only random reset token SHA-256 digests in MySQL, with 30-minute expiry and 5-minute issuance cooldown. GET previews do not consume tokens; CSRF POST does.
- Store per-member `session_generation` in an additive recovery table. Password-reset transactions increment the value, and member plus private-media endpoints compare it on each request to revoke older sessions. Missing table/migration fails closed.
- Use a neutral response for known/unknown recovery emails, fixed HTTPS site origin and minimal PHP `mail()` adapter until a professional mail provider is selected. No real email-delivery certification.


## 2026-11-20 — Explicit separation of email ownership and adult verification

Email confirmation is a one-time hashed token delivered to an account's email address. It never changes member status to active and never grants a paid or age-verified entitlement. Database and HTTP checks require a confirmed email before private content access. Hosts may opt in to an origin-pinned PHP mail adapter; no recipient link is derived from a request Host header. A link GET is side-effect free and POST has CSRF protection to avoid scanner-triggered confirmation. Provider-reviewed identity/age and consent remain separate workflows.
## 2026-11-20 — Verification and release safety decisions

1. External verification evidence is transported through a disabled-by-default signed adapter. No real provider is included, and administrators cannot self-certify age or consent. Revocations deny access; event ordering prevents delayed verification from undoing revocation.
2. The content access predicate requires an explicit number of performers and exactly that many complete approved records. When the number is unknown, deny access rather than guess.
3. Backups may contain personal/sensitive records. Ship only authenticated encrypted snapshot foundations and never put secret keys, ID documents or plaintext DB exports under the public root. Do not call these a complete production restore solution.
4. A detached signed release manifest by itself cannot guarantee a safe automatic update. Require tested staged extraction, payload digest checking, migration rollback and full restoration before enabling 1.0 update workflows.

## ADR — Report-first quarantining and audit without publication (2026-11-20, 0.8.1-dev)

A report is treated as a high-severity safety signal. `ModerationRepository::submitReport()` locks the content row, inserts a report, marks its content quarantined and resets media review states within one database transaction. The entitlement check additionally denies access when any report is open. Unknown IDs receive the same member-facing confirmation; duplicate submissions by one member are suppressed for 24 hours. Administrator actions require a reason and append to a private review log. **There is no approve/publish action:** a human flag alone cannot establish complete performer identification/consent, verified adult status, or lawful content. Closing a report never automatically reinstates quarantined content. This prioritizes safety but creates denial-of-service potential from fraudulent reporter accounts; email verification, abuse controls, appeals and incident response are required before production.

## 2026-11-20 / New development decisions

- Plain-text public page renderer is deliberately trusted code; uploaded themes are signed CSS only and cannot include server-side executable templates.
- New member registration is pending by default and not an age verification or member entitlement.
- Private content serving defaults to denied until independent age-verification records, moderator approval, consent evidence and membership entitlement exist. No media upload/publishing UI is enabled yet.
- Pro HMAC jobs are received and deduplicated without executing shell commands or FFmpeg; 1.0 production release stays blocked pending independent validation.

# Architecture decision record

## ADR-001 — Self-hosted editions, no SaaS (2026-11-20)
Customers install Adult_Core independently with their own domains, hosting, and databases. Lite targets shared hosting; Pro targets supported VPS installations. No centralized customer billing or shared SaaS control plane is required.

## ADR-002 — Capabilities, not host labels (2026-11-20)
Environment detection uses PHP requirements and authenticated Pro-agent checks; cannot reliably tell shared/VPS from PHP metadata alone. Pro is selected only when its services are actually available. Module manifests enforce edition and capability requirements.

## ADR-003 — Removable install folder + fail-closed gate (2026-11-20)
Installation resources belong in top-level `install/`, not `public/install/`. The `public/index.php` front controller invokes them only in `setup_required`. A completed installation must have a valid marker **and** have its install folder removed before permitting frontend/admin access. This prevents the installer from staying exposed after setup and makes cleanup mandatory. M2 implemented the marker writer after schema/admin verification; production MySQL integration remains unverified locally. MySQL DDL itself is not transactional.

## ADR-004 — Web-root isolation (2026-11-20)
Only `public/` is exposed as the document root; private PHP and install state live above it. A future compatible host with no way to set a safe document root must be rejected, not silently installed into a publicly accessible application root.

## ADR-005 — One core, distinct capability-gated add-ons (2026-11-20)
Maintain shared core code and edition manifests. Installing and running add-ons must validate core version, declared edition, dependencies and actual capabilities. Package integrity/signature checks and safe runtime enforcement are still pending.

## ADR-006 — Signed completion marker and single-use fresh database (2026-11-20)

A JSON flag alone can be forged or accidentally copied. M2 generates a private application key and signs the installation completion marker with HMAC-SHA256; the access gate also requires a private DB config. Only verified completion followed by manual removal of `install/` unlocks the route guard. A fresh MySQL schema is required and existing Adult Core tables cause a hard stop. Because MySQL DDL isn't rollback-safe, partial installs require manual recovery using a new disposable database or a trusted backup; no web-based reset endpoint is provided.

## ADR-007 — Shared-host security envelope (2026-11-20)

The installation form requires HTTPS except for loopback development; CSRF, HttpOnly SameSite installer sessions, filesystem-backed attempt rate limiting, initial password hashing, and private atomic configuration files. Do not expose database diagnostics, session secrets or raw credentials to HTTP responses or audit logs. PHP `PASSWORD_DEFAULT` currently uses bcrypt; installation passwords are limited to 72 bytes to avoid silent truncation. Production app/session security remains part of M3.

## ADR-008 — Capability gate is distinct from a deployable Pro runtime (2026-11-20)

A successful Pro-agent probe qualifies a provisional Pro edition, but `scripts/pro-agent-demo.py` is *not* a production worker/streaming system. Do not advertise advanced Pro features as ready until M7 has been implemented and tested independently. Add-on manifests remain a compatibility contract, not a complete signed package loader.

## ADR-009 — Server-verified administrator authentication (2026-11-20)

The first `ac_users` admin created by installation authenticates with the existing password hash. A private server-side PHP session stores only the user ID/timestamps, never a role claim: the role and account existence are checked in MySQL on each protected admin request. Unknown roles and permissions default to denied. Login rotates the session ID; idle/absolute timeouts and POST CSRF prevent stale-session reuse and logout CSRF. Filesystem-backed login throttling runs without Redis or long-lived workers so shared hosting can support Lite. No admin access is possible until the existing mandatory installer removal gate is satisfied.

The M3 dashboard is intentionally read-only. It does not imply that private content, age verification, billing, moderator administration or production membership access are ready. PHP session and rate-limit stores may need redesign if the product later supports multiple PHP web nodes sharing a session pool.

## ADR-010 — Signed data-only extensions first; no upload-and-execute PHP (2026-11-20)

M4 uses publisher allowlisted public keys, OpenSSL SHA-256 verification over **exact** `package.json` bytes and per-asset SHA-256. Packages are private, stored outside `public/`, and never executed or served as live themes. Uploads use `PharData` ZIP parsing to avoid requiring `ext-zip` on shared hosting. File-size/entry-type constraints and explicit path validation guard against unsafe archives. Install/activate/runtime eligibility checks are separate and fail closed for Lite/Pro incompatibility, corrupt packages, invalid dependencies and expired capabilities. Trust anchors can only be installed by the site operator outside the browser upload flow.

This deliberately leaves executable plugin APIs, theme rendering, migrations and update/rollback until later milestones. A package publisher signature is **not** a sandbox, and auto-executing signed third-party PHP without review would be inappropriate for a shared-host deployment.

## 2026-11-20 — Defer production launch pending security integration

Theme runtime serves only signed static CSS; neither PHP nor JavaScript from uploaded packages runs in the core. Member data migrations are deliberately not installed automatically until disposable-MySQL and recovery coverage is in place. Billing provider adapters and media streaming are separate, reviewed integrations; cryptographic helper classes do not confer production readiness.

## ADR — Reuse Cajun Web Pro's software licensing authority (2026-11-20)

Adult_Core does not fork the Cajun_Web license generator. It calls Cajun Web Pro's fixed HTTPS `POST /api/validate.php` with the exact catalog SKU `SOFTWARE-ADULT-CORE`, key and canonical registered domain. Do not authorize missing-SKU/unknown-product responses, or treat HTTP200+`authorized:false` as an outage. Product SKU and vendor-issued entitlements require a separate owner configuration on Cajun Web Pro; don't write to its complex payment infrastructure to bootstrap or fake a license.

First-install vendor positive proof precedes MySQL DDL; a private file and HMAC-signed last-success cache enable bounded rate-limited revalidation without hammering vendor APIs. A known denial blocks immediately; transient vendor failures permit up to 72h from the last trusted success and otherwise block. A legacy installation must activate via the authenticated `/admin/license` repair route; the top-level `install/` deletion requirement and signed completion marker remain independent prerequisites. License keys never belong in Git, public HTML, JS, release ZIPs or audit logs.
