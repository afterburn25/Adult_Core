# Adult_Core roadmap (maintained)

Status as of **2026-11-20**, developer snapshot **0.5.0-dev**. Update statuses and acceptance criteria after every milestone. Do not mark code production-ready merely because the unit tests pass.

| ID | Scope | Status | Acceptance criteria |
|---|---|---|---|
| M1 | Core layout, Lite/Pro manifests, capability checks, example module compatibility, preflight | DONE (prototype) | Edition and module decision paths tested |
| M1.1 | Removable `install/`, mandatory folder deletion gate, continuity docs | DONE (prototype) | Cleanup/ready/missing/invalid states tested via PHP + HTTP |
| M2 | Real browser installer: DB credentials, migrations, admin bootstrap, edition persistence, CSRF, signed state, retries/recovery | IMPLEMENTED; DB integration pending | PHP/HTTP tests pass locally; disposable real-MySQL CI and cPanel integration remain unverified |
| M3 | Admin runtime: login, static roles/permissions and read-only dashboard | IMPLEMENTED locally; real MySQL HTTP/cPanel tests pending | Authenticated dashboard; persistent sessions, CSRF/HTTPS/rate limits, revocation; installation gate before every route |
| M4 | Signed add-on package lifecycle + themes | PARTIALLY IMPLEMENTED (signed packages; signed CSS preview route) | Trusted publisher signature and asset hashes verified; admin upload/enable/disable; Lite/Pro and dependency guards. **Pending:** real MySQL-admin HTTP verification, executable plugin runtime, theme renderer, updater/rollback |
| M5 | Membership, content management, private galleries, consent and safety workflows | STARTED (draft schema and authorization predicate, not wired to runtime) | Authorized protected media, adult age/identity controls, consent and moderation features |
| M6 | Payment-provider integrations, renewals and entitlements | STARTED (generic verified-event inbox and HMAC utility, no processor integration) | Provider approval, idempotent webhooks and tested authorization/refunds |
| M7 | Production Pro agent, video/transcoding/streaming and realtime services | STARTED (HMAC job envelope contract only) | Authenticated production agent, worker health, access-controlled delivery and recovery tests |
| M8 | Release packaging, backups, updates, hosting certification | STARTED (developer ZIP packaging script, no signed updates/backups) | ZIP deploy on compatible shared hosting, VPS provisioning, restore and signed-release tests |

## Immediate next task

1. **Verify M2 on a disposable MySQL database** (CI is preconfigured in `.github/workflows/php-tests.yml`; see `docs/TESTING.md`). Test under Apache/cPanel as well as PHP's dev server. Fix any integration defects discovered before declaring M2 complete.
2. **Verify M3** authenticated HTTP login/logout with the new disposable MySQL suite and Apache/cPanel HTTPS hosting tests. Fix any discovered issues before production use.
3. **Verify M4 administrator package upload** on a disposable MySQL HTTPS/cPanel host. No executable extensions or live themes exist yet. Finish versioned module runtime, theme renderer, update/rollback, and trust-key administration before claiming M4 is complete.
4. Improve installer diagnostics, backup/recovery and add administrator MFA/recovery without changing mandatory `install/` cleanup.

## Explicit non-features

No functioning membership website, subscription billing, video streaming, real Pro provisioning, verified age/consent workflows, executable extension runtime, live theme renderer or production-ready update system exists yet. Admin login is implemented but real hosting verification is pending.

## Developer snapshot 0.5.0-dev additions

- Signed theme CSS delivery path (`/theme.css`) behind the installation gate, including runtime package integrity checks. Full frontend theme rendering is not implemented.
- Draft MySQL membership/content/performer-record/report tables and a default-deny entitlement predicate. Migration is not run by the existing installer pending disposable DB validation and recovery design. No public member routes.
- Billing inbox scaffold for verified third-party events and duplicate event IDs. No actual payment-provider adapter, webhook endpoint or settlement workflow.
- HMAC-signed Pro job envelope protocol with narrow job allowlist. No live worker, video transcoding or streaming implementation.
- Developer source-release packager; not a production installer package or updater.

**Milestones 4–8 are NOT complete.** Completion needs real MySQL/cPanel tests, usable galleries/membership UX, legal/compliance checks, payment provider approval and certification, production Pro worker and streaming, encrypted/verifiable backups, signed rollback-capable updates, security audit, load and hosting tests.
