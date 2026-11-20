## 0.8.1-dev — content safety triage (test use only)

Member reporting is CSRF protected and authenticated. Reports atomically quarantine content and associated media; reviewers can reject/quarantine and resolve reports, but cannot approve or publish. Open reports deny all content entitlements. Reason text is escaped in admin output, actions are privately audited, and no identity-document upload exists. Fraudulent reporting by pending accounts can suppress content and requires rate limits, confirmed email, appeals and human investigation before production. Complete identity/performer consent verification and lawful recordkeeping are outstanding. See docs/MODERATION.md.

# Current security status — 0.8.0-dev

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
