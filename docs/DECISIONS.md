
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
