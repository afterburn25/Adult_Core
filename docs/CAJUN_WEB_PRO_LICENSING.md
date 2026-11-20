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
