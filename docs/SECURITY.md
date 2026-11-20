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
