# Adult_Core ↔ Cajun Web Pro software licensing

**Integration build: `0.8.6.3-license-dev`. Not yet a licensed production release.**

The **sole licensing authority** is the existing implementation in [`afterburn25/Cajun_Web`](https://github.com/afterburn25/Cajun_Web): `site/api/validate.php`, `site/api/handlers/license.php`, and the `site/app/licenses/` software-entitlement and domain lifecycle. Adult_Core does **not** create its own vendor license table, sell licenses, modify the CWP catalog, or silently mint keys.

## Required Cajun Web Pro owner setup (not automated)

The reviewed `Cajun_Web` source provides product-bound software licenses, but no existing Adult_Core catalog registration was found. Before using this integration for customers:

1. Register/publish a **software-family catalog product** with the exact unique SKU **`SOFTWARE-ADULT-CORE`** in Cajun Web Pro. Its normal catalog, release, licensing policy, and customer-entitlement workflows must be configured and independently tested there.
2. Issue an **ordinary product-bound license** to a customer with that SKU. Reserve master licenses for owner testing, never commercial customer distribution. Do not issue keys without satisfying Cajun Web Pro's billing/authorization workflow.
3. Register the customer's actual installation hostname in their Cajun Web Pro license/domain management workflow. Apex and `www.` are equivalent; additional domains and transfers remain subject to the license's existing seat and change policy. **Adult_Core never adds domains automatically.**
4. Test a staging key through the actual hosted authority before any live deployment. This repository's test doubles prove only the client-side contract, not a live Cajun Web Pro purchase or activation.

## API contract verified in the Cajun_Web repository

Fixed production endpoint: **`POST https://cajunwebpro.com/api/validate.php`** with JSON:

```json
{
  "license_key": "REDACTED-DO-NOT-COMMIT-REAL-KEYS",
  "domain": "example.com",
  "product_id": "SOFTWARE-ADULT-CORE"
}
```

- HTTP **200 + `{"authorized":true}`**: definitive positive authorization. Requires active license, correct SKU, domain, grant expiry/seat and applicable financing status.
- HTTP **200 + `{"authorized":false}`**: **definitive denial**; site access blocks immediately at the next validation. Never treat it as a network outage.
- HTTP **non-200**, timeout, malformed JSON, invalid body or TLS failure: **inconclusive/transient**, not an affirmative denial. First install still fails closed; an installed site uses a bounded 72-hour grace **only if** a valid HMAC-sealed positive check was already retained.
- The optional `version` request parameter is intentionally omitted in this **developer build**. Cajun Web Pro requires registered published software versions to authorize update entitlements. Add enforced version checks only after the Adult_Core product/release metadata is published and the CWP contract is tested; this build does **not** enforce maintenance/update eligibility.
- The client uses the **fixed HTTPS host**, verifies TLS peer and hostname, never follows redirects, uses a four-second timeout and a 4 KiB response ceiling. PHP's HTTPS stream transport and outbound HTTPS access are necessary. No user-selected licensing service URL is supported.

## First install

1. Serve **only `public/`** as the web root and launch `/install/` over HTTPS. A local loopback PHP server is permitted for development only.
2. Enter the license key and **registered domain** (hostname only) together with the normal database, site and administrator fields. The installer validates the vendor response **before attempting any MySQL connection or schema creation**.
3. A valid license is stored privately as `config/license.php` with owner-only permissions. The app key signs `storage/license-cache.json` so a browser cannot forge the last positive result. Neither key nor license credential belongs in Git, logs, public files or support screenshots.
4. The initial database/admin setup, private license state and signed installation marker must all succeed. **The marker remains the last installation write.** The entire top-level `install/` directory must then be **manually deleted**, as before.
5. Previously installed development sites do not have a license: when upgraded, regular site routes stay locked until a logged-in administrator activates one through `/admin/license`.

## Runtime and recovery

- Each request verifies the mandatory existing signed installation/cleanup gate **first**, then the license. An installed but unlicensed site does not serve the public pages, member endpoints, private media, admin dashboard or other feature routes.
- License state is cached for **one hour** to avoid hammering the Cajun Web Pro API. Successful checks refresh the signed state. Explicit denials block immediately. A rejected key can be rechecked after an hour or revalidated through authenticated `/admin/license`.
- **Temporary service failures** can preserve use for at most **72 hours since the last verified positive response**; no initial success or expired grace means HTTP 503 (fail closed). A signed previous denial **never** receives positive grace.
- A `www.` alias is treated as the same licensed hostname. Other hostnames (including alternate domains) require Cajun Web Pro domain registration and an administrator license update. The web server must enforce its configured canonical virtual host; the HTTP Host header alone is not a substitute for TLS/vhost isolation.
- Only **`/admin/login`, POST `/admin/logout` and `/admin/license`** are reachable while license enforcement is blocking. License repair requires an authenticated, database-revalidated administrator, existing HTTPS policy, POST CSRF, a bounded attempt limiter and online Cajun Web Pro reauthorization. License keys are never rendered or echoed to the browser after submission. No anonymous online activation endpoint exists.
- Keys must be safeguarded in hosting backups. `config/license.php`, `config/app-key.php` and `storage/license-cache.json` must be outside the document root. The first two are gitignored and the third is inside excluded runtime storage.
- This licensing gate protects the HTTP runtime and browser installer. Nonexistent production Pro workers and other future CLI jobs must explicitly check license validity before enabling user-facing functionality.

## Local and CI verification

```sh
php tests/licensing_tests.php
php tests/run.php
python3 tests/http_smoke.py
# GitHub CI also runs disposable MySQL install + authenticated browser tests.
```

The tests inject an explicit local in-process fake authority, **not a real key or a public service override**. The real client can only call the fixed Cajun Web Pro HTTPS endpoint. Do not include testing keys/fixtures as actual customer entitlements.

## Still to verify before shipping a paid product

- Actual staging license issuance, SKU creation, published release and host/domain registration in Cajun Web Pro; live HTTP TLS access from representative cPanel/hosting providers.
- Suspension, expiration, reinstatement and active-domain replacement against a real test account; provider timeouts and rate limiting.
- License-key support and migration UX for existing customers, key rotation, host migration, hosting backups and restore safety.
- Version-based update entitlements and edition-specific commercial policy, if Lite vs Pro are sold separately. This integration currently uses **one** `SOFTWARE-ADULT-CORE` SKU for both editions; edition selection remains based on actual hosting capabilities.
- Independent review of copy-protection limitations and the licensing service as a commercial dependency. License checks do **not** make Adult_Core's unfinished adult-content, payment, verification or streaming features production ready.


## 0.8.6.3 licensing verification (2026-11-20)

Source [`5b800dd4704b2bdf8b99f8ce51bdd3e70803689e`](https://github.com/afterburn25/Adult_Core/commit/5b800dd4704b2bdf8b99f8ce51bdd3e70803689e) passed [GitHub Actions run 44832503540](https://github.com/afterburn25/Adult_Core/actions/runs/44832503540), including 52 licensing contract/runtime checks, PHP/HTTP tests, disposable real-MySQL installation and authenticated browser/administrator license recovery flows. **This is synthetic Cajun Web Pro authority testing, not live CWP issuance or a production certification.** The required software product/release registration and real staging activation are tracked in [Cajun_Web issue #399](https://github.com/afterburn25/Cajun_Web/issues/399).
