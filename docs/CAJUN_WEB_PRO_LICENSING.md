## Paid add-on license defense in depth — 2026-11-21

- Paid modules retain **separate Cajun Web Pro module-SKU license keys** and HMAC-signed receipts. A module's successful receipt does **not** override an incomplete installation or an expired, revoked, wrong-domain, or unavailable **core** license.
- Before paid module activation or runtime entitlement checks, the module manager now requires a **ready signed installation** and a currently authorized core license (including the core's signed-cache, denial and bounded-outage policies). This also protects backend service calls not routed through the HTTP controller. The module's own entitlement must independently remain authorized.
- The module manager also refuses symlinked storage parents, its private `.lock`, and symlinked module license configuration/cache files. Both private destinations are preflight-checked under the module lock before activation rewrites either one. The two writes are not one atomic filesystem transaction; external trusted-backup recovery may still be necessary after an unexpected mid-write failure.
- `tests/package_tests.php` exercises these failure modes with fixture license keys and signed local installation state, including core revocation and interrupted replacement. These are **not live Cajun Web Pro addon purchases**, real renewal events, or real host deployments.

---

## 0.8.6.7 subscription-lifecycle verification boundary

Automated isolated HTTP contract simulation exercises product binding, initial monthly activation, paid-through expiration, renewal/restoration, cancellation, definitive revocation, wrong domains, rate limits and transient service failures with the actual Adult_Core resolver and signed license cache. `tests/cwp_subscription_contract.py` **does not use the actual Cajun Web Pro issuer server**. The license screen now explicitly states that key replacement cannot cancel monthly payments and that Adult_Core does not know the next bill date; the billing link remains `https://cajunwebpro.com`. Real Cajun Web Pro catalog and approved merchant sandbox acceptance are still required. See `docs/SUBSCRIPTION_ACTIVATION_TESTING.md`.

## Manual license health check (0.8.6.6-license-health-dev)

The administrator's `/admin/license` screen now has **Check license now**: an authenticated CSRF-protected, rate-limited POST, available only to `site.settings.manage`. It bypasses the usual one-hour signed verdict cache while keeping single-flight locking, exact SKU/domain and 72-hour grace semantics. It displays `valid`/`denied`/`transient` *for the current attempt* without exposing keys or treating an outage as revocation. The same edition remains selected by the already-validated key; check now never changes edition. Four catalog entries, pricing and real subscription staging work remain pending at Cajun Web Pro; see `docs/CWP_CATALOG_SETUP.md`.

# Adult_Core licensing — Cajun Web Pro integration (0.8.6.5-upgrade-dev)

**One universal Adult_Core installer. The verified license KEY determines Lite/Pro and billing term.** Never choose an edition from host type, a browser dropdown, or a query string. Licensed Pro still requires the actual Pro agent/persistent process capabilities; a Pro key on a Lite-only server blocks installation, never silently downgrades. Licenses are issued and billed exclusively in [Cajun_Web](https://github.com/afterburn25/Cajun_Web) (`site/api/validate.php`, software catalog and license/recurring billing modules).

## Four required Cajun Web Pro catalog products

| SKU | Installed edition | Cajun Web Pro catalog term |
|---|---|---|
| `SOFTWARE-ADULT-CORE-LITE` | Lite | `once` |
| `SOFTWARE-ADULT-CORE-LITE-MONTH` | Lite | `month` |
| `SOFTWARE-ADULT-CORE-PRO` | Pro | `once` |
| `SOFTWARE-ADULT-CORE-PRO-MONTH` | Pro | `month` |

**None of these products/prices were created or activated in the live Cajun Web Pro service by this source change.** The owner must create the four software-family SKUs, configure prices, plan/installation-seat policies and versions, verify approved payment gateway recurring settings and publish eligible product offerings. Do not issue keys before valid paid orders or authorized owner-controlled tests. Monthly renewals, cancellations, paid-through entitlement expiry, refunds and status changes live in Cajun Web Pro, not in the installed Adult_Core site's checkout. Adult_Core sends no card details and does not attempt to bill customers.

The previous single SKU `SOFTWARE-ADULT-CORE` **is not accepted** as an edition selector by this build. Existing development site owners must obtain a new product-specific key and use `/admin/license`; the signed installed edition cannot be changed by replacing the key. Moving from Lite to Pro requires a separately implemented/tested edition migration, verified Pro hosting, and updated signed installation state; until then the **Upgrade to Pro** link is a placeholder pointing to https://cajunwebpro.com.

## API contract

The fixed HTTPS service accepts `POST https://cajunwebpro.com/api/validate.php` with JSON:

```json
{"license_key":"REDACTED-KEY","domain":"example.com","product_id":"SOFTWARE-ADULT-CORE-LITE-MONTH"}
```

The installer checks the four recognized SKUs, requiring an unambiguous authorized result before any DB work; transient errors or conflicting positive results cause fail-closed installation. Because the existing authority validates one SKU per call, a first-time install may make up to four requests, using Cajun Web Pro's existing 10/minute/IP rate limit. Host requirements are checked *after* the verified SKU determines the edition, *before* the database is accessed. Installed sites recheck their exact stored SKU approximately hourly.

- HTTP **200, `authorized:true`**: license validated for this exact SKU/domain. The authority must have checked status, seat limit, paid-through term/expiry, and any financing restrictions.
- HTTP **200, `authorized:false`**: explicit denial; no install or runtime feature access.
- HTTP **non-200**, malformed response, TLS or network failure: inconclusive, not a revocation. The first install fails closed; existing signed last-positive evidence provides at most 72 hours of outage grace. Explicit denial never gains grace.
- Development build deliberately omits `version`: Cajun Web Pro's update-entitlement check requires registered published software release metadata. Add it only after release publishing and full end-to-end tests.

Private state: `config/license.php` contains the key and SKU, `storage/license-cache.json` contains an HMAC-protected, SKU/key/domain-bound receipt. These files must remain private and never enter Git, ZIP archives, screenshots or logs. The `/admin/license` page displays SKU, edition and billing term but never the key. Its replacement action rejects cross-edition key changes, preserving the signed state and DB identity.

## Individually licensed paid modules

An offline publisher-signed **module** manifest may optionally declare `"license_sku":"SOFTWARE-ADULT-CORE-MODULE-CHAT"`. Paid modules use a **separate** Cajun Web Pro SKU and license key (a distinct `-MONTH` SKU can be used for recurring products). Module activation validates the module key against the same core installation domain; the key is stored in private `storage/packages/module-licenses/<id>.php`, and a separate HMAC-sealed receipt supports hourly rechecks and bounded outage grace. A missing, invalid, expired, or revoked module license blocks that module's runtime eligibility; unrelated free modules remain usable. Signed themes cannot claim a module SKU. Keys are private and not reused from the core installer.

The package engine still accepts **data-only packages**. This integration does NOT make uploaded PHP executable, implement an arbitrary module runtime, enable paid private galleries or authorize adult bookings.

## Administrators on Lite

A Lite site shows disabled **Video streaming**, **Live broadcasts**, **Real-time chat** and **Video processing** in the sidebar with the exact label **Pro Version Only** and an **Upgrade to Pro** button. The URL is intentionally https://cajunwebpro.com until a product-specific upgrade page exists. Pro users see the same *unimplemented* features labelled **In development**, never misleading working links. These are presentation affordances, not authorization; server-side edition/capability checks remain mandatory.

## Required production verification

Register all four SKUs and sample module SKU in Cajun Web Pro; verify actual issuance/checkout/renewal/expiry/cancellation/domain movement and event propagation against a staging domain, with billing approval. Validate real cPanel Lite and separately provisioned Pro hosts, and test wrong SKU, wrong edition, outage/rate-limit and revoked module keys. Complete verified edition upgrade migrations and independent security audit before a 1.0 claim. This is **development-only**.

## Safe Lite-to-Pro upgrade (0.8.6.5-upgrade-dev)

The original `SOFTWARE-ADULT-CORE-LITE` or `SOFTWARE-ADULT-CORE-LITE-MONTH` key cannot itself unlock Pro. Purchase/issue a `SOFTWARE-ADULT-CORE-PRO` or `SOFTWARE-ADULT-CORE-PRO-MONTH` license on Cajun Web Pro, register the **same existing domain**, provision a compatible host with a verified Pro agent, then use the authenticated `/admin/upgrade` form on that existing Adult_Core installation. The Cajun Web Pro upgrade product page is **not yet assigned**; the purchase link is the placeholder `https://cajunwebpro.com`. No user profile, theme, media or page is recopied. The upgrade changes only DB edition metadata and HMAC-signed private edition/license records with a recovery sentinel. Unlike the prior cross-edition replacement prohibition, `/admin/license` still rejects cross-edition keys; only `/admin/upgrade` performs the guarded transition. Full off-site backup and restore test required before a real upgrade. See `docs/LITE_TO_PRO_UPGRADE.md`.
