## Apache routing hardening and disposable container verification (2026-11-21)

The bundled `public/.htaccess` now denies all unexpected direct PHP/script files, including `public/router.php` (which is only for PHP's development server) and `assets/index.php`; root `public/index.php` is the Apache front controller. A disposable `php:8.3-apache` container CI test checks these web-server rules with `mod_rewrite` and `AllowOverride All` enabled, verifies the preinstall wizard/static assets and ensures private-root URL guesses cannot bypass the installation gate. It creates no production data and uses only a loopback ephemeral port.

This evidence does **not** prove the operator's actual Apache vhost configuration, customer hosting document root, cPanel PHP-FPM behavior, HTTPS certificate, module availability, or real Lite/Pro license activation. Run the full real-host staged checklist below before production acceptance.

---

# Lite shared-host and Pro VPS deployment preflight — 0.8.11-host-preflight-dev

**Development-only, non-destructive host triage.** This check does **not** install the site, issue or discover a license key, upgrade an edition, contact Cajun Web Pro, prove the host's effective web configuration, certify Pro workers, change customer data, or approve adult-content/payments. It is a preparatory gate before independently witnessed staging tests on actual shared hosting and VPS infrastructure.

## One codebase, license-selected edition

Before sending a license key to the vendor, the installer now checks that the actual public document root, bundled routing files and public-tree hygiene are safe. Unsafe layouts are rejected without issuer contact, installation state or MySQL schema changes. This is still a local-only check and is not proof that Apache enforces its rules.

The universal installer validates a **Cajun Web Pro** key against four product-bound SKUs (`SOFTWARE-ADULT-CORE-LITE`, `SOFTWARE-ADULT-CORE-LITE-MONTH`, `SOFTWARE-ADULT-CORE-PRO`, `SOFTWARE-ADULT-CORE-PRO-MONTH`). The **verified license key**, not this script, chooses Lite/Pro and one-time/monthly term. A Pro key must meet additional authenticated local agent and persistent-process requirements; the installer must not silently fall back to Lite. A separate edition-upgrade workflow handles an existing site; switching the script's `--expected-edition` cannot upgrade it.

## Execute in an isolated staging deployment

Transfer a **verified source ZIP** with the project root outside the web-serving directory. Keep `install/`, `src/`, `config/`, `storage/`, `scripts/`, `tests/` and the rest of the application **outside** the web document root. Configure the host's domain root to the application's `public/` directory. Run the command from the project root using the same PHP runtime/extensions that the web server uses (if they differ, inspect both). The document-root argument must be the **real host-configured path**, not a path typed just to make the check pass.

For a **new Lite shared-host installation**:

```sh
php scripts/deployment-preflight.php \
  --document-root=/home/account/adult-core/public \
  --expected-edition=lite --phase=preinstall
```

For a **new Pro VPS installation**:

```sh
php scripts/deployment-preflight.php \
  --document-root=/srv/adult-core/public \
  --expected-edition=pro --phase=preinstall --json=1
```

For an **already installed site**, pass `--phase=installed` after manually deleting `install/`. This mode checks the HMAC-signed installation marker and private SKU/edition mapping **locally** without querying the license authority or showing the license key. It never verifies payment standing or current online authorization. Do **not** edit these private values to satisfy the report; use `/admin/license` or the documented Lite-to-Pro migration.

The command requires explicit `--document-root`, `--expected-edition` and `--phase`; unknown switches or relative document roots are rejected. Exit codes: **0** local checks passed (external tests still required), **2** blockers detected, **64** invalid invocation/setup. Use `--json=1` for a stable `adult-core-deployment-preflight-v1` report containing required checks, blockers and required external verifications; neither JSON nor plain text includes license keys, DB credentials or TOTP secrets. Store detailed reports privately; path names can themselves be sensitive in a real host inventory.

## Exactly what it checks

- PHP >= 8.3, required PHP extensions, writable private `storage/` and `config/`, and the declared document root equal to the real `public/` directory. The optional archive helper remains optional.
- A Pro expected deployment additionally requires a **successful token-authenticated loopback status call** from the local Pro service and its declared persistent-process capability; this is not a video-transcoding or production worker check. The included `scripts/pro-agent-demo.py` is a DEVELOPMENT-ONLY test responder, **never a production background daemon**.
- Presence of the bundled front controller, router and Apache `.htaccess`; unexpected public executables, dotfiles, symbolic links or a private directory accidentally copied or linked into `public/` block the local check. The **browser installer and Lite-to-Pro host prerequisites now reuse the same public-tree scan** before accepting the existing `public_docroot` capability; this prevents installation on a correct-looking document root containing unsafe files. A real HTTPS routing/privacy audit is still required, because PHP cannot prove the effective Apache configuration. **Apache `mod_rewrite`, FPM identity, symlink behavior and request routing must be checked on the actual host**—the existence of `.htaccess` does not prove it is applied.
- `--phase=preinstall` requires `install/` present and no generated database/app-key/license/cache/signed install files. `--phase=installed` requires a valid signed completion marker, physically removed `install/`, and edition consistency with the local recorded licensed SKU. Both phases preserve the fail-closed installation policy.

## Additional remote HTTPS acceptance

After the local report, run `python3 scripts/staging-host-audit.py --url=https://YOUR-STAGING-HOST --phase=preinstall --confirm-staging-readonly` on the **actual owned staging domain** and repeat with `--phase=installed` after successful installation and `install/` removal. See [HTTPS staging host acceptance](STAGING_HOST_HTTP_ACCEPTANCE.md). This remote check never selects an edition or accesses payment endpoints; passing it is not hosting certification.

## Real hosting acceptance still required

On **Lite shared hosting**: confirm HTTPS/TLS, your actual domain's document root, Apache rewrite behavior, real PHP-FPM extension versions, that `/config/`, `/storage/`, `/install/`, `/.env`, and private data cannot be fetched by HTTP, session cookie security, mail and Cron limitations, backups, and reinstallation/restore with isolated MySQL. Do not point a shared-host domain at the checkout root.

On **Pro VPS**: additionally confirm the authenticated production Pro agent is managed as a restricted system service with restart policy, persistent worker supervision, networking/loopback isolation, process resource limits, logs and actual video/WebSocket capability tests. The bundled demo cannot certify these. Test the server twice—before and after actual license activation—and rehearse a full encrypted offsite backup/restore.

For **both**: verify one authorized staging Cajun Web Pro SKU/domain with `scripts/audit-cwp-staging.php` using an owner-approved staging-only key; test revocation and subscription paid-through behavior on the issuer, and independently review adult age/identity/consent, hosting terms, permitted billing and security. No sandbox merchant credential, live customer card or adult content is required for this preflight. Production releases remain locked by `ReleaseReadiness` until separate certification.

## Reproducible tests

```sh
php tests/deployment_preflight_tests.php
python3 tests/deployment_agent_contract.py
php scripts/deployment-preflight.php --document-root="$(pwd)/public" --expected-edition=lite --phase=preinstall --json=1
```

The localhost Pro test starts `scripts/pro-agent-demo.py` with a **fresh synthetic token** and proves that a wrong token does not grant Pro capability. It does not establish a secure production daemon. GitHub Actions repeats these tests with the disposable MySQL environment; it is **not** a real cPanel/Apache or VPS deployment test.
