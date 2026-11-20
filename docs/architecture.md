# Architecture — one core, two installation profiles

## Model

- `lite`: minimum capabilities verified on compatible PHP/MySQL shared hosting.
- `pro`: all Lite capabilities plus an authenticated, local Pro service check.
- `EditionSelector` uses `config/editions/*.json` and `HostInspector` capabilities. A VPS without the provisioned Pro service qualifies only for Lite when Lite checks pass.
- Pro service downtime after a completed installation will eventually degrade affected Pro modules, **not silently downgrade the persisted edition**.

## Installation and routing boundary

- Top-level `install/` is **outside** `public/` and holds installation-only code. `public/index.php` includes `install/index.php` only when setup is incomplete.
- The installer commits completion to private `storage/install-state.json` only after successful database migrations/admin bootstrap.
- The `InstallationGate` blocks both public and admin until the marker is valid **and** the install directory has been manually deleted. Removing the directory early leaves the site blocked.
- M3 administrator routes are handled by `src/Http/AdminController.php`, using `src/Auth/AdminAccounts.php` for MySQL credential and live role verification and `src/Auth/AdminSession.php` for hardened private sessions.
- See `docs/INSTALLATION_LIFECYCLE.md` for exact statuses and HTTP behavior. The public membership site remains a 503 placeholder; admin login and read-only dashboard are implemented but lack local real-MySQL verification.

## Module manifest model

Each `modules/<slug>/module.json` declares `id`, `version`, `minimum_core_version`, `editions`, `required_capabilities`, and `dependencies`.

`ModuleCompatibility` returns reasons for rejection and `assertInstallable()` refuses missing requirements. Package signatures, safe upload/unpack, runtime module loader and actual installation are future milestones. **Do not execute arbitrary uploaded PHP based on a manifest alone.**

## Pro service prototype

`ProAgentClient` probes a fixed loopback address `127.0.0.1:8787` using a configured bearer token, and checks service identifier, API version, health and an allowlist of capabilities. A demo agent is provided in `scripts/pro-agent-demo.py` solely for local validation. It does not transcode videos or provide streaming.

## Safety boundaries

- No privileged shell commands from browser input.
- No private config, database passwords or agent tokens inside the document root or repository.
- The public front controller enforces the installation gate before dispatching any administrator route. Future PHP entry points and API controllers must also enforce it.
- Adult-content services require age verification, consent/performer records, reporting, takedowns, and approved payment processing before public launch.

## M4 signed package management (partial)

`src/Package/PackageVerifier.php` reads signed data-only ZIPs via PHP Phar ZIP, checks operator-allowlisted public publisher signatures and hashes of every asset, and rejects executable payloads. `PackageStore.php` publishes verified packages to private `storage/packages/` using a file lock and atomic registry. The `/admin/packages` admin route performs upload/enable/disable with M3 session, role and CSRF protection. `isRunnable` denies enablement when edition, capabilities, signature or transitive dependencies are invalid. A selected theme is **only state**, not live rendering. See `docs/PACKAGES.md`.
