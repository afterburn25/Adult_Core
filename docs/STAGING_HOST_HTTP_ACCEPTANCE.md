# Staging-host HTTPS acceptance — 0.8.12 development

**Purpose:** After `scripts/deployment-preflight.php` passes locally, check the **actual external HTTPS staging origin** for web document-root mistakes and disclosure of application files. This complements—but never replaces—the local host preflight, a real issued Cajun Web Pro staging license, or live cPanel/VPS operations and browser testing.

## Owner-only, read-only invocation

Run the command from a secure workstation or directly on the staging server, and supply only a domain you administer. No password, license key, API key, cookie or URL query string is needed. Do **not** target a customer or production site without the appropriate operator approval.

```sh
# Before installation (installer intentionally reachable)
python3 scripts/staging-host-audit.py --url=https://staging.your-domain.com --phase=preinstall --confirm-staging-readonly --json

# After successful installation, registration of its Cajun Web Pro license,
# removal of the install/ directory and database preparation
python3 scripts/staging-host-audit.py --url=https://staging.your-domain.com --phase=installed --confirm-staging-readonly --json
```

The command has **no install/upgrade/payment side effects**, uses at most 16 ordinary GET requests, does not follow HTTP redirects, never reads/prints response bodies, pins a public DNS answer before connecting, and uses verified TLS 1.2+/system certificate trust/hostname verification. It refuses HTTP, nonstandard ports, URL credentials, paths, queries, fragments, IP literals, reserved domains and any DNS response that contains a non-public address. An operator cannot disable TLS verification or supply a direct target IP. A private probe may still appear in the target web server's normal access log.

### Expected results and exit codes

| Exit | Outcome | Meaning |
| --- | --- | --- |
| `0` | `passed` | All **bounded** HTTP checks passed; production readiness NOT certified. |
| `2` | `blocked` | A required route, header, private-path denial or installer gate failed. |
| `3` | `inconclusive` | DNS, TLS, network, rate-limiting or other transport errors prevented full verification. |
| `64` | `refused` | Unsafe/invalid target or invocation; **no probing**. |

The JSON schema is `adult-core-host-https-audit-v1`. It reports each check as pass/block/inconclusive without private contents or keys. Report hostnames may still be sensitive staging information; store privately.

The audit requires these conditions:

- A new site redirects `/` and `/admin/login` to `/install/`, and `/install/` is accessible before completion. An installed site instead serves its public homepage and admin login, and denies `/install/` with 403/404.
- The site serves `/assets/style.css` with a CSS content type, returns app-controlled behavior on a nonexistent route, and returns CSP plus `X-Content-Type-Options: nosniff` headers.
- Private URL paths such as `/config/license.php`, `/config/database.php`, `/storage/license-cache.json`, `/src/Support/Autoload.php`, `/scripts/backup-site.php`, `/tests/run.php`, `/.env` and `/AGENTS.md` must never produce an HTTP 200 or unexpected redirect. Preinstall redirects to **only** `/install/` are acceptable. The tool never follows redirects.

**Important:** a passed HEAD-only inspection of a URL would not prove GET is safe, so the audit uses GET but intentionally closes each response before reading any body. It cannot prove the full contents of every private directory are inaccessible. Always also inspect the actual server virtual-host document root, permissions, Apache rewrite and PHP-FPM settings. This is a **read-only diagnostic**, not a vulnerability scanner or penetration test.

## Real Lite/shared and Pro/VPS acceptance checklist

For **Lite on shared hosting**, use a staging subdomain with `public/` as the actual document root. Compare the real server's PHP/extension settings to the CLI preflight; test session cookies, CSP, admin MFA, password reset emails, Cron availability and a complete empty-database recovery from an encrypted offsite backup. Run this command both before and after installation. Confirm a real staging-only Lite key against the Cajun Web Pro authority using `scripts/audit-cwp-staging.php`.

For **Pro on a VPS**, repeat all above, then confirm a real long-lived Pro service (systemd or equivalent) runs with restricted credentials and restart policy, is bound to a private listener, and performs real approved background work. The bundled `pro-agent-demo.py` **cannot** pass the production-supervision gate. Confirm a real staging-only Pro key. Require a successful restore drill with workers stopped and writes drained.

For **monthly license plans**, Cajun Web Pro must register the four Adult_Core products, their approved prices and authorized test subscriptions. Verify paid-through, renewal, failed-charge behavior, cancellation and restoration of paid access in the merchant sandbox *separately*. This HTTP tool cannot issue, revoke, renew, charge, or cancel licenses.

Do not enable production customer payments or adult content because this audit passes. Independent security, provider policy, age/identity/consent and legal acceptance remain open.

## Offline regression

```sh
python3 tests/staging_host_audit_tests.py
```

Tests cover unsafe URL/DNS handling, mixed private/public DNS answers, redirect traps, private exposure, MIME/header correctness, transport failures and a real PHP development-server **preinstall** HTTP round trip. They use a synthetic transport and localhost solely in the test harness; CI never contacts a real staging host. For real HTTPS verification you must run the opt-in command against an operator-owned host with a valid certificate.
