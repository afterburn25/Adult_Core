# Milestone 4 — signed data-only package management (partial)

2026-11-20 | Target GitHub repository: `afterburn25/Adult_Core` | Source ZIP prepared locally; **not pushed**.

## Implemented

- Admin `/admin/packages` route for signature-checked ZIP upload and activation/deactivation; accessible only after valid completed installation, manual deletion of `install/`, successful admin login and `modules.manage` permission. PHP sessions, HTTPS (outside loopback) and POST CSRF remain enforced.
- Trusted public publisher keys at `config/trusted-publishers/<publisher>.pem`, **never** uploaded and trusted automatically. Offline package builder: `scripts/build-signed-package.php`.
- Strict archive verification: signatures, per-file hashes, allowed paths/types and size limits, private staging, atomic registry and rollback of failed install publication.
- Compatibility guard for Lite and Pro, minimum core version, available host capabilities and active dependencies. Rechecks for eligibility through `PackageStore::isRunnable`. Themes have one selected state.
- New docs: `docs/PACKAGES.md`, updated architecture decisions, security, tests, roadmap, session handoff and developer instructions.
- Test additions: `tests/package_tests.php`; CI updated to include package tests.

## Not completed

- These are **data-only** packages. A runtime plugin API, live theme rendering, upgrades/uninstall, billing, gallery UI and all content/membership features remain unimplemented. There is no automatic execution of uploaded PHP, JS or templates.
- Real MySQL-backed admin upload has **not** been integration-tested here. Actual cPanel/Apache hosting and Pro service provisioning are also not certified.
- Nothing has been committed/pushed directly to GitHub. Import the release archive into the repository and let GitHub Actions run the disposable MySQL tests.

## Developer handoff

Start with `AGENTS.md`, then `docs/SESSION_HANDOFF.md`, `docs/ROADMAP.md`, `docs/PACKAGES.md`. Do not erase the roadmap history or report unverified integrations as complete. See the release test results in the final user-facing message.
