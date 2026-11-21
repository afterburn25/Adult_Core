## 0.8.9 backup vault and scheduled-job controls

Cryptographically verified archives remain sensitive, encrypted containers. A read-only inspector checks authenticated record order, row encoding, media hashes and the signed installation state without printing recovered secrets. Vault shipping uses private staging, postcopy validation and byte identity; only older verified, expected-name archives can be pruned after a new copy is durable. A site maintenance marker causes 503 on public/admin routes and survives hard crashes, but is **not** a database/filesystem transaction or guarantee that earlier requests and Pro workers have stopped. Production use requires independently arranged write quiescence, offsite replication, key escrow, alerting and recovery drills; do not keep keys under `public/`, in Git or in crontab. See `docs/BACKUP_OPERATIONS.md`.

---

## Encrypted recovery hardening — 0.8.8-backup-dev

CLI-only `.acfull` backup uses an **independent offline AES-256-GCM key**, authenticated sequenced streaming records, mandatory final record, file hashes, and strict source/destination path policy. Encrypted archives include sensitive user data, license keys and MFA secrets; store offline under controlled retention and never expose via `public/`. Restore is deliberately destructive only to a fresh, empty dedicated DB/target; MySQL DDL cannot be rolled back, so a pending marker blocks every HTTP route after an interrupted import. Preserve the marker until the failed target is wiped and rebuilt; never delete it to bypass the safety gate. See `docs/PORTABLE_BACKUP_RECOVERY.md`.


---

## 0.8.6.6 administrator license health check

Only an existing authenticated administrator with `site.settings.manage` may submit the CSRF-protected `/admin/license` recheck or replacement form. Both use an IP-based limiter; no license key is echoed or logged. Manual forced revalidation bypasses the one-hour client cache, but keeps the domain check, per-installation nonblocking refresh lock, HMAC-signed receipt, direct issuer denial, and bounded signed last-positive outage grace. Inconclusive vendor 429/5xx/TLS failures never become explicit `denied`. No external payment account or key is created by a check. Live issuer/catalog acceptance and independent security review remain open.

## Edition transition safety — 0.8.6.5-upgrade-dev

`/admin/upgrade` is HTTPS-only, owner/admin settings permission required, CSRF-protected and rate-limited. Reenter the **current** administrator password and acknowledge an independently verified off-site backup. A Pro key must validate with Cajun Web Pro for the same canonical hostname and exact Pro SKU, and the host must pass the independently verified Pro-agent check. No request value can set the edition directly. Backup files for private license/marker metadata are stored above the web root with restricted permissions and authenticated hashes; none contain member database dumps or media. The `storage/.edition-upgrade-pending` sentinel makes `InstallationGate` deny every HTTP request after an interrupted transition; CLI rollback validates backup integrity and restores Lite. Never delete the sentinel manually; restore a trusted full backup if recovery fails. No encryption at rest guarantee is implied by a private file. Production host certification, realistic backup/restore exercise, concurrent-request shutdown and independent security review remain outstanding.

## Security decisions — edition-bound monthly licensing (2026-11-20)

Version `0.8.6.4-license-dev`: **one installable software**, key-selects Lite/Pro and either one-time or monthly term via Cajun Web Pro's four separate product SKUs. Pro eligibility is independently enforced from verified host capabilities; cross-edition license replacement is blocked until safe migration is implemented. Paid static add-ons may declare signed `license_sku` and require a distinct vendor key before enabling; runtime eligibility rechecks signed entitlement caches. Lite admin displays disabled Pro-only features with **Pro Version Only** and an upgrade link to https://cajunwebpro.com (placeholder). No customer payment or live license issuance was performed. The earlier single SKU is retired for new installs; existing installations require a matching replacement key. Operator must register catalog offerings and test actual monthly billing externally. Details: `docs/CAJUN_WEB_PRO_LICENSING.md`. Release 1.0 still blocked by production compliance, payment, streaming and security gates.

## Licensing trust boundary (0.8.6.3-license-dev)

Cajun Web Pro's HTTPS licensing API is the only authorization authority. Its 200/boolean verdict is distinct from temporary 429, input and 5xx/network failures; fresh installs always require a positive verdict. A signed cache binds the product SKU, key fingerprint and domain to the private application secret. HTTP runtime blocks when unlicensed, explicitly revoked, wrong-domain or beyond 72 hours since the last authoritative success; no background worker may rely solely on a UI label. The administrator recovery route is reachable while the frontend is locked but remains protected by revalidated role, HTTPS, CSRF and rate limiting; it cannot generate keys. The private license credential is never rendered and belongs above the web root. This is licensing enforcement, **not** a verification, content-safety, billing or production-certification control. See `docs/CAJUN_WEB_PRO_LICENSING.md`.

## 0.8.6.2 design cutout safety

New `CutoutPlacement::validate()` rejects arrays, negatives, decimals, noncanonical numeric strings, values outside 0–100 X/Y and 20–100 image width, and CSS code; coordinates are emitted into the public stylesheet as integers with `%` only. Data writes remain behind admin role and CSRF checks. The additive migration is explicitly applied through `/admin/setup`, and the editor refuses to run on old schema rather than silently discarding coordinates. Public decorative image serving remains restricted to a current selected asset with `public_ok=1` and a published homepage; drag preview cannot approve an upload or publish a draft. The visual editor does not make adult media or unverified performer content safe for publication.

## 0.8.6-design-dev: public site illustration vs protected adult media

Visual Design Studio accepts only small verified PNG/WebP/JPEG uploads under an admin-authenticated POST form. Assets use private randomized filenames outside `public/`, 0700 storage and 0600 files. A public decorative asset must be explicitly marked rights-cleared/non-explicit, selected in the saved layout and attached to a **published** homepage; the separate public route refuses all others. Admin preview is session/role gated. Personal/exif metadata stripping is not automatic; remove identifying metadata before upload. No raw adult performance or verification evidence should be uploaded to this feature. The unimplemented adult media upload and identity/consent workflow remain blocked. CSS templates are trusted fixed layouts and user text is escaped. `script-src 'self'` permits only the local static design-editor enhancement, not inline/third-party JS; scripts in uploaded images/packages are not served as executable code.

## 0.8.5-ui-dev visual refresh security scope

- UI markup has a shared escaped layout and uses plain HTML/CSS, with no remote scripts, remote fonts or tracking calls.
- The backend sidebar is **presentation**, not an authorization mechanism. `AdminController` still enforces installed-state, authenticated-account and role checks, and all mutating forms retain CSRF tokens and POST-only actions.
- Public nav slugs are whitelist-validated and titles/site names are escaped. CMS content continues to be escaped plain text; this is not an HTML theme/template execution engine.
- The dashboard shows real account count/edition and clearly states what does **not** work. CSS screenshots use illustrative data and do not constitute real host or security verification.
- Missing MFA, provider-reviewed age/consent and paid-media publishing remain security/release blockers.

## 0.8.4-dev member password recovery security

- Recovery is member-only, with email confirmation prerequisite, one-use SHA-256-digested tokens, 30-minute expiry, five-minute cooldown and separate per-IP/account throttle. Unknown and known addresses receive the same request response.
- Token consumption requires CSRF-protected POST; GET is a non-consuming preview. Password changes invalidate all active member sessions on their next request via a database `session_generation`, checked even on `/media/` binary delivery.
- Reset never modifies membership status or grants adult-age/identity, media moderation, consent or paid privileges. Administrator password recovery is **not implemented**. Production SMTP delivery and administrative MFA are outstanding.


## Member email ownership (0.8.3-dev)

`ac_member_email_confirmations` stores only SHA-256 token hashes, never the plaintext link secret. Token TTL is 30 minutes, resend cooldown 5 minutes, and consuming POST requires a member-session CSRF token. A GET from an email scanner is side-effect free. Email sender address and HTTPS base URL come from a private site-operator config, not the Host header. Private content is denied if member email ownership is not confirmed. This is **not adult age/identity verification**. The native PHP mail transport and mail deliverability have NOT been tested in production.
## 0.8.2-dev notes — proof of age, consent and release security

- New `SignedAssertion` requires HMAC with five-minute freshness, limited canonical claims, 24h event recency and replay/out-of-order protection. The generic contract is **not a certified provider integration**; enabling requires a contracted provider and a reviewed, private adapter. No raw ID documents or biometrics are collected.
- `expected_performers=0` or mismatch denies access; pending roster records are not sufficient to authorize content. Consent receipt changes re-quarantine published assets and reset approval state.
- Provider callbacks remain behind the universal installation gate. `config/verification.php` is ignored by Git and must never be web exposed. No generic admin self-certification route exists.
- AES-GCM snapshot tooling is small DB-only and CLI-only; no restore, media backup, offsite rotation or disaster recovery. Signed manifest verification is not an updater. 1.0 remains blocked.

## 0.8.1-dev moderation change (test use only)

Content reports can now be submitted by logged-in members with CSRF protection. All reported items are quarantined and associated media approval is reset. Reviewers can quarantine/reject and close a report with a reason, but cannot approve or publish media; append-only database audit log records who acted. The content access policy also denies while an open report exists. Real evidence and age/consent provider integrations remain absent. Pending accounts may abuse reporting to cause quarantine; production requires email verification, report throttling, investigations/appeals and an emergency removal protocol. Sensitive evidence must not be uploaded in free-text reports. Details: `docs/MODERATION.md`.

# Current security status — 0.8.1-dev

**Do not enable adult uploads or paid subscriptions.** All new members remain `pending` and cannot self-verify age. `/media/{id}` requires an active member, an independent age-verification record, approved media and content review, consent evidence when needed, and entitlements. The approval of *every* performer is NOT proven merely by checking one approved consent record; production publishing must establish completeness before any real adult content. Admin preview and moderation UI are unfinished.

Current CSS themes are signed assets, not executable templates. Private image keys are random opaque identifiers and stored outside the web root, with allowlisted media types. No multipart upload route is enabled for adult media.

Remaining critical gaps: email verification, external age/identity verification integration, performer identification/consent auditing, payment approval/refunds/reconciliation, moderation/takedowns, MFA and account recovery, real cPanel/Pro testing, update rollback and encrypted backup restore. See `docs/RELEASE_GATES.md`.

---

# Security notes (M4 development build)

**Not production-ready.** Do not permit real adult-content uploads, membership access or payments yet. The PHP/MySQL auth feature has only local no-database unit/smoke coverage; a disposable MySQL/HTTP CI integration suite is configured but not verified from this environment.

## Access control and authenticated routes

- Every dynamic route runs through `public/index.php` and `InstallationGate` first. `install/` must be manually removed after a signed, verified installation; deleting it prematurely never grants access.
- Admin login: `GET/POST /admin/login`; dashboard: `GET /admin`; logout: `POST /admin/logout`. The public `/` remains an intentional HTTP 503 until membership/content/compliance layers are implemented.
- Administrator permissions are explicit and deny by default in `src/Auth/RolePolicy.php`. First installer account has role `admin` and may use the initial dashboard. `moderator` and `member` **cannot log into the administrator console**.
- A session-stored user ID is *not* sufficient authorization: every protected request re-queries the user by ID from MySQL and verifies the current role. A deleted or demoted admin loses access. All database errors fail closed.
- CSRF tokens are stored server-side and required for login and logout POSTs. Login rotates the session ID and CSRF. Logout destroys session state and its cookie. No GET sign-out endpoint exists.
- PHP sessions use a private `storage/sessions/` directory (outside public web root), HttpOnly, SameSite=Strict, strict-mode, cookie-only sessions; Secure on HTTPS. Administrator access refuses non-HTTPS except loopback development requests. No proxy forwarding headers are trusted automatically.
- Sessions expire after 30 minutes idle or 12 hours absolute. Logged-in users are redirected away from the login form. Credentials are checked against installer-created password hashes, not stored as plaintext.
- Login attempts are counted with file locks by hashed account and IP (15 per IP and 8 per account within 15 minutes), with generic incorrect-credentials responses. This is a foundation only: add distributed abuse prevention, 2FA and monitoring before production.
- Sensitive database and app-key configuration must remain above the web root. No database details are printed on errors, and templates use HTML escaping for dynamic site and user strings.

## Known gaps

- No verified browser-based full install+login test against an actual shared-host Apache/cPanel environment yet. The opt-in MySQL HTTP CI test runs after local source changes are published and CI becomes available.
- No administrator password reset, MFA, account verification, email delivery, or audit viewing interface.
- No fine-grained, database-managed permissions/user-management UI yet; current role policy is static and explicit. Admin dashboard is informational and read-only.
- No content access enforcement, age/identity verification, performer consent records, moderation, payment compliance, storage protection, live video delivery, or production Pro agent yet.
- PHP session/file throttling assumes one server and a secure per-site filesystem. Future multi-node Pro setups need centralized session/rate-limit storage.
- Hosting configuration must guarantee that **only** `public/` is web-exposed; a host unable to provide that boundary is incompatible with this packaging.

## Security test cases

Run `php tests/run.php` and `python3 tests/http_smoke.py`. See `docs/TESTING.md` for opt-in disposable MySQL tests covering wrong credentials, role revocation, password verification, HTTP login/logout and HTML escaping. Never aim tests at a real customer database.

## M4 add-on and theme security

- `/admin/packages` requires installer cleanup, verified admin role, HTTPS and CSRF; no direct unauthenticated upload endpoint.
- Signed ZIP manifests must verify with explicitly provisioned `config/trusted-publishers/<publisher>.pem` public keys. Never place a **private** signing key in the repository or on the public web host. No web-based trust-on-first-use.
- Data-only asset file types, path traversal/symlink/unsigned-entry rejection, 8 MiB archive and 16 MiB total uncompressed size limits, private staging, and re-verification after write protect the package store.
- Activation remains gated on installed edition, current host capabilities and healthy enabled dependencies. The future runtime must call `PackageStore::isRunnable` and must **not** bypass it. No uploaded package code or theme assets are currently executed or served.
- Risk: the admin package screen has not yet had authenticated upload tests against MySQL/cPanel; trust-store replacement and safe update/rollback need further design. Malicious CSS can contain third-party resource URLs and should not be rendered until a future strict theme asset pipeline exists.
