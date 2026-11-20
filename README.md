# Adult Core — Self-Hosted Lite & Pro Membership Platform

**Current development status: 0.5.0-dev — M4–M8 foundations in progress, not production-ready; MySQL integration passed in GitHub CI; Apache/cPanel remains unverified.**  
**GitHub repository target:** [afterburn25/Adult_Core](https://github.com/afterburn25/Adult_Core)

Adult Core is a planned **self-hosted** adult membership platform, **not SaaS**. One core and universal browser installer support two installation profiles:

- **Lite:** compatible PHP 8.3+/MySQL shared hosting, without persistent service workers.
- **Pro:** independently provisioned VPS with authenticated local Pro service and compatible background processing capabilities verified during installation.

Edition detection examines real capabilities rather than guessing whether hosting is shared or VPS. Module manifests declare allowed editions, minimum core versions, dependencies and capabilities; incompatible add-ons are refused by the manifest checker and M4 package manager. The package manager supports signed data-only ZIPs; executable plugins, package upgrades, and frontend theme rendering are future work.

> **DO NOT deploy this as a live adult membership website.** Administrator login is implemented but has not yet passed an end-to-end MySQL/cPanel host test in this development environment. There are no production member accounts, content access controls, identity/age/consent records, moderation, payment processing, or video streaming.

## What currently works (M1–M4 partial)

- Browser installation preflight and automatic Lite/Pro eligibility check; secure minimum requirements.
- Top-level removable `install/` folder (outside web root), served exclusively through `public/index.php` at `/install/` while setup is required.
- MySQL installer form with input validation, CSRF, installer rate limiting, concurrency lock, fresh database verification, initial schema, hashed first admin password, private config and signed completion marker.
- **Mandatory manual deletion:** valid installation stays locked for ALL routes until `install/` is deleted. Early deletion or tampered state never unlocks.
- Private MySQL runtime connection, admin password verification, and a deny-by-default role policy for admin access. Current first admin role is `admin`.
- Hardened private file sessions with HTTP-only/Strict cookies, session-ID rotation on login, idle/absolute expiry, login throttling and CSRF on sign-in/sign-out.
- Routes: `GET/POST /admin/login`, authenticated `GET /admin` read-only dashboard, and `POST /admin/logout`. The dashboard shows escaped site name, installed edition, account count and current admin. Role authorization re-queries MySQL on each request.
- Protected `/admin/packages` upload/install/enable/disable interface for cryptographically signed **data-only** ZIP extensions and themes; publisher public keys must be explicitly provisioned outside the web root. Lite/Pro/active-dependency checks enforced on install, activation and runtime eligibility. **No executable plugin runtime or live theme renderer yet.**
- Intentionally unavailable public `/` (503) and unimplemented membership/content/billing modules.
- Automated local PHP/HTTP tests and opt-in real MySQL installation + auth/HTTP integration tests in GitHub Actions.

## Development installation (do not use on production websites)

1. Extract project **above** the web directory and set the virtual host's document root to **`Adult_Core/public/`**. Never expose `install/`, `src/`, `config/` or `storage/` directly to the web.
2. Use PHP 8.3+, `pdo_mysql`, `openssl`, `json`, `fileinfo`, `session`, MySQL/MariaDB, writable private `config/`/`storage/`, and HTTPS on non-loopback hosts.
3. Create a fresh empty MySQL database and a dedicated restricted account in cPanel.
4. Open `https://your-test-domain.example/install/`, review detected edition, and enter DB/site/first-admin information. The installer writes a private signed completion record only after verified setup.
5. **Delete the entire top-level `install/` folder manually** through cPanel/SSH. Until deleted, even authenticated admin pages stay blocked.
6. Go to `/admin/login` and use the administrator email/password created during installation. `/admin/packages` provides signed package management; see [`docs/PACKAGES.md`](docs/PACKAGES.md). The public site intentionally remains disabled.

If MySQL schema creation fails partway, MySQL DDL may leave partial tables. The installer will refuse to overwrite existing `ac_` tables. Recover only from a trusted backup or an explicitly fresh database; never delete customer data casually. See `docs/INSTALLATION_LIFECYCLE.md`.

## Source layout

```text
Adult_Core/
├── install/                 # Removable browser installer (NOT web-accessible directly)
├── public/                  # ONLY web document root; routing, .htaccess, CSS
├── src/
│   ├── Installer/           # Setup flow, signed marker and gate
│   ├── Database/            # MySQL schema and private connection
│   ├── Auth/                # Admin accounts, permissions, sessions and throttling
│   ├── Http/                # Admin login/logout/dashboard/package manager
│   ├── Capability/          # Host and Pro agent probes
│   ├── Edition/             # Lite/Pro selection
│   ├── Module/              # Add-on compatibility contract
│   ├── Package/             # Signed ZIP verifier, private package store
│   └── Support/             # Autoloading/manifests
├── modules/                 # Legacy example manifest definitions
├── config/editions/         # Lite / Pro JSON requirements
├── storage/                 # Private runtime state/sessions/package store, gitignored
├── config/trusted-publishers/  # Explicit, offline-provisioned PUBLIC signing keys
├── examples/                # Data-only example module + theme manifests/assets
├── tests/                   # PHP/HTTP and optional disposable MySQL integration tests
├── scripts/                 # Offline signed-ZIP builder + NONPRODUCTION Pro demo
├── docs/                    # Roadmap, session handoff, security, decisions, history
├── AGENTS.md                # Read first for new developer / AI sessions
└── .github/workflows/       # PHP lint/tests and MySQL CI test configuration
```

## Run local tests

```sh
php tests/run.php
php tests/package_tests.php
python3 tests/http_smoke.py
find src install public tests scripts -type f -name '*.php' -exec php -l {} \;
php -S 127.0.0.1:8080 -t public
```

To run real MySQL tests, use the **explicitly disposable database-only** instructions in [`docs/TESTING.md`](docs/TESTING.md). Those tests are not runnable in the current local environment because `pdo_mysql` and MySQL server are unavailable.

## Next milestones / continuity

Read [`AGENTS.md`](AGENTS.md), [`docs/SESSION_HANDOFF.md`](docs/SESSION_HANDOFF.md), [`docs/ROADMAP.md`](docs/ROADMAP.md) and [`docs/SECURITY.md`](docs/SECURITY.md). Update these along with [`docs/CHANGELOG.md`](docs/CHANGELOG.md) as changes are made.

Next: verify M2/M3/M4 MySQL and cPanel HTTPS/install/upload routing, then implement a safe versioned extension API and frontend theme renderer; add update/rollback, backup/recovery and administrative MFA. See [`docs/PACKAGES.md`](docs/PACKAGES.md) for signing, trust anchors and package limitations. Future milestone functionality must not be represented as already delivered.

**Publishing:** files in a local ZIP do not imply a GitHub commit. No remote push was verified during this session. Repository publishing steps are in [`docs/publishing.md`](docs/publishing.md).

## 0.5.0-dev snapshot (2026-11-20)

This development update adds a signed theme CSS route, draft member/content/consent schema and private entitlement predicate, billing webhook-inbox primitives, a signed Pro job-envelope protocol, and a source-release ZIP builder. **No payment processing, public adult media publishing, live streaming, verified age/consent records, or production-ready theme runtime is available yet.** Do not enable public content or billing on this build.

Run the new tests with `php tests/advanced_tests.php`. See `docs/ROADMAP.md` and `docs/SESSION_HANDOFF.md`.

## GitHub CI verification — 2026-11-20

GitHub Actions run [44707148841](https://github.com/afterburn25/Adult_Core/actions/runs/44707148841) passed: PHP lint, 62 core tests, 29 package tests, 8 advanced foundation tests, 23 HTTP smoke checks, a real disposable MySQL installation test, and a real MySQL-backed administrator login/logout integration test. This verification runs on GitHub Ubuntu/MySQL; Apache/cPanel, public release packaging, adult compliance workflows, real billing, member media authorization, and Pro streaming remain unverified/incomplete.
