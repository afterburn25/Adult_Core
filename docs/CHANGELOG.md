# Latest changelog — 2026-11-20 / 0.8.0-dev

- Created safe page schema and public renderer with plain-text escaping, draft/published management, `/admin/setup`, `/admin/pages`, `/admin/page` and an admin members list.
- Created pending member signup, login, independent member sessions and CSRF/rate limits. Accounts cannot self-activate or self-verify age.
- Added idempotent private-content schema and verified-age/consent/moderation entitlement policy, private file key checking and guarded image delivery without public uploads.
- Added signed, deduplicated Pro job inbox **without executing code** and explicit production release blockers.
- Expanded pure PHP, HTTP and disposable MySQL tests; updated CI and handoff docs. Production release remains blocked.

---

## 2026-11-20 — 0.5.0-dev foundation expansion (not completed M4–M8)

- Serve selected signed theme CSS through installation-gated `/theme.css` while still refusing executable upload code.
- Add draft member/content/consent/report schema and default-deny private entitlement policy, not activated in production.
- Add replay/idempotency-ready billing inbox draft and HMAC check (no real payment adapter).
- Add allowlisted HMAC Pro job contract (no real streaming worker).
- Add developer source ZIP script and new unit tests.
- Update roadmap, CI workflow and session handoff.

# Changelog / implementation record

## 2026-11-20 — Milestone 1.1: removable installer and access gate

- Added **top-level `install/`** and moved the browser preflight view from `public/index.php` to `install/index.php`. The URL `/install/` is still served by the safe public front controller.
- Added `src/Installer/InstallationGate.php`, a fail-closed state machine checking installation completion and the physical presence of `install/`.
- Added `src/Installer/LockedScreen.php` for cleanup-required, missing-installer and invalid-state responses.
- Updated `public/index.php` to block site/admin, redirect incomplete installs to `/install/`, and reject installation access after completion.
- Added `public/.htaccess` for Apache/cPanel route rewriting.
- Expanded PHP tests with temporary fixture directories for required cleanup and negative cases; added 11 isolated HTTP smoke tests and GitHub Actions coverage.
- Added `AGENTS.md`, `docs/SESSION_HANDOFF.md`, `docs/ROADMAP.md`, `docs/DECISIONS.md` and `docs/INSTALLATION_LIFECYCLE.md` for future-session continuity.
- **Not implemented:** credentials, database setup, admin creation, real installation finalization, full site runtime.

## 2026-11-20 — Milestone 1: foundation prototype

- Added minimal PHP preflight frontend, Lite/Pro manifests, PHP host checks and authenticated loopback Pro-agent probe.
- Added module compatibility engine and example photo-gallery, streaming and real-time-chat manifests.
- Added PHP unit-style test runner, CI lint/test workflow and setup/hosting documentation.

## 2026-11-20 — Milestone 2: MySQL installer implementation (integration pending)

- Added full browser `install/` form for site name, new database credentials, administrator email and password, with automatic Lite/Pro profile selection.
- Added installer session cookie hardening, CSRF protection, HTTPS enforcement on real domains, server-side field validation, rate limiting and a concurrency lock.
- Added MySQL bootstrap schema (`ac_users`, `ac_settings`, `ac_schema_migrations`, `ac_installations`), prepared inserts, hashed first administrator password and edition persistence.
- Added private configuration writes using owner-only atomic files; completion marker is now an HMAC-signed schema-v2 JSON record written **last**. `InstallationGate` refuses unsigned, tampered or incomplete configuration.
- Kept mandatory manual deletion of `install/` before any site/admin route is accessible. No automatically deleting installation files.
- Added filesystem-redacted installation auditing and explicit refusal to overwrite an existing Adult Core database schema.
- Extended PHP and HTTP test coverage, created opt-in real-MySQL integration test and configured GitHub Actions MySQL service.
- **Not verified locally:** real database installation; PHP environment lacks `pdo_mysql` and MySQL. **Not implemented:** production app/admin login and membership runtime.

## GitHub CI verification — 2026-11-20

GitHub Actions run [44707148841](https://github.com/afterburn25/Adult_Core/actions/runs/44707148841) passed: PHP lint, 62 core tests, 29 package tests, 8 advanced foundation tests, 23 HTTP smoke checks, a real disposable MySQL installation test, and a real MySQL-backed administrator login/logout integration test. This verification runs on GitHub Ubuntu/MySQL; Apache/cPanel, public release packaging, adult compliance workflows, real billing, member media authorization, and Pro streaming remain unverified/incomplete.
