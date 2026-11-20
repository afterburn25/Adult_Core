# Adult_Core roadmap — current iteration

**2026-11-20, version 0.8.1-dev (development only).** The project has not reached production 1.0.

| Target | Current status | Verified work | Unfinished prerequisites |
|---|---|---|---|
| 0.6 Public frontend & themes | **ALPHA** | Publish/draft plain-text pages, browser editor, public navigation and selected signed CSS | Full layout templates, signed theme updates/rollback, cPanel validation |
| 0.7 Membership accounts | **ALPHA** | Separate sessions and login, CSRF/rate limits, pending registration, admin member listing | Email confirmation, MFA/recovery, real age/identity verification, profile and tier UX |
| 0.8 Private content | **FOUNDATION + TRIAGE** | Media authorization guard; private drafts, member reports, auto-quarantine, admin rejection and audit | Adult uploads, independent verification, appeals and lawful publication workflow |
| 0.9 Payments | **FOUNDATION ONLY** | Existing generic verified event inbox | Approved provider, real processor webhook adapter and checkout, renewals/refunds/disputes |
| 0.10 Pro streaming | **FOUNDATION ONLY** | Signed job envelope and non-executing replay-safe inbox | Verified VPS, FFmpeg worker, segment authorization, live/realtime streaming |
| 1.0 Production release | **BLOCKED** | Explicit fail-closed release gates and source ZIP tooling | Signed updates and backup/restore, audits, host certification, all above |

See [`RELEASE_GATES.md`](RELEASE_GATES.md). This iteration does **not** claim versions 0.6–1.0 are all completed.

## Immediate engineering priorities

1. Run new disposable MySQL CI browser tests for `/admin/setup`, page editor, registration and status; fix defects. Repeat on actual cPanel Apache HTTPS.
2. Complete safe content publishing/consent review and age-verification adapter before allowing media uploads or paid access.
3. Finish the signed theme/template runtime and rollback-capable package upgrades.
4. Obtain payment-provider approval and build provider-specific checkout/webhooks, then finish the Pro worker and production-hardening gates.

---

## Previous milestone tracking (historical)

# Adult_Core roadmap (maintained)

Status as of **2026-11-20**, developer snapshot **0.5.0-dev**. Update statuses and acceptance criteria after every milestone. Do not mark code production-ready merely because the unit tests pass.

| ID | Scope | Status | Acceptance criteria |
|---|---|---|---|
| M1 | Core layout, Lite/Pro manifests, capability checks, example module compatibility, preflight | DONE (prototype) | Edition and module decision paths tested |
| M1.1 | Removable `install/`, mandatory folder deletion gate, continuity docs | DONE (prototype) | Cleanup/ready/missing/invalid states tested via PHP + HTTP |
| M2 | Real browser installer: DB credentials, migrations, admin bootstrap, edition persistence, CSRF, signed state, retries/recovery | IMPLEMENTED; GitHub CI disposable MySQL PASS, cPanel pending | PHP/HTTP tests pass locally; disposable real-MySQL CI and cPanel integration remain unverified |
| M3 | Admin runtime: login, static roles/permissions and read-only dashboard | IMPLEMENTED; GitHub CI MySQL-backed HTTP PASS, cPanel pending | Authenticated dashboard; persistent sessions, CSRF/HTTPS/rate limits, revocation; installation gate before every route |
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

## GitHub CI verification — 2026-11-20

GitHub Actions run [44707148841](https://github.com/afterburn25/Adult_Core/actions/runs/44707148841) passed: PHP lint, 62 core tests, 29 package tests, 8 advanced foundation tests, 23 HTTP smoke checks, a real disposable MySQL installation test, and a real MySQL-backed administrator login/logout integration test. This verification runs on GitHub Ubuntu/MySQL; Apache/cPanel, public release packaging, adult compliance workflows, real billing, member media authorization, and Pro streaming remain unverified/incomplete.


## Verified GitHub CI for 0.8.1-dev foundation

- Source commit: [`4fec09071fda8ea517fe84b5f44a4bb348986b1c`](https://github.com/afterburn25/Adult_Core/commit/4fec09071fda8ea517fe84b5f44a4bb348986b1c).
- GitHub Actions: [run 44714670111](https://github.com/afterburn25/Adult_Core/actions/runs/44714670111) **passed** on PHP 8.3 + disposable MySQL. Includes unit checks, signed-package tests, Pro/release tests, HTTP smoke tests, MySQL installer and MySQL-backed admin/page/member browser tests.
- Real Apache/cPanel deployment, actual age/consent/payment integrations, video streaming, and production release gates **remain unverified or unimplemented**.
