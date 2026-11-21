# Cajun Web Pro catalog setup — Adult_Core

**Status:** This is a source-controlled staging checklist. It does not create live catalog records, issue keys or enable any merchant payments. Owner must approve prices, product terms, issuance policy and supported billing before publication.

## Four catalog entries, no duplicate SKUs

Adult_Core has a **single installer**; the validated product key determines the edition and term.

| Exact SKU | Suggested title | Family | Term |
|---|---|---|---|
| SOFTWARE-ADULT-CORE-LITE | Adult_Core Lite — One-time | software | once |
| SOFTWARE-ADULT-CORE-LITE-MONTH | Adult_Core Lite — Monthly | software | month |
| SOFTWARE-ADULT-CORE-PRO | Adult_Core Pro — One-time | software | once |
| SOFTWARE-ADULT-CORE-PRO-MONTH | Adult_Core Pro — Monthly | software | month |

Create or verify one unique product per SKU in the **existing authenticated Cajun Web Pro owner catalog**. Start as draft, currency USD. Owner must supply a positive amount, product summary, contract terms, update entitlement and installation-seat policy before publishing. Recurring software offers must use **zero** one-time setup fee under the existing software-order contract. Do not silently accept the older SOFTWARE-ADULT-CORE SKU for modern installations. Use no fake sales prices.

## Staging acceptance checklist

1. Confirm the existing Cajun Web Pro software catalog, licensing tables and subscription system are prepared, with actual owner permission and an approved sandbox merchant configuration. No production charging by default.
2. Issue authorized staging-only customer licenses for each SKU, register staging domains and verify product-specific authorization and refusal for wrong SKU, wrong domain, revoked/expired entitlements and exhausted seats.
3. Install the **same Adult_Core ZIP** using each key: one-time Lite, monthly Lite, one-time Pro and monthly Pro. Require independent Pro host capability checks and prevent edition selection by any untrusted browser field.
4. On the installed staging sites, use /admin/license → Check license now. Test confirmed valid, denied, HTTP 429/5xx and unavailable issuer. Never display or log keys. Confirm that vendor outages are not revocation and that signed grace remains bounded.
5. Verify paid-through expiry, verified monthly renewal, failed billing/cancellation, supported recovery after payment, domain move and in-place Lite-to-Pro upgrade without lost content.
6. Verify separate signed paid-module product entitlements and no cross-product license reuse.

Live CWP product records, prices, license issuance and recurring subscription acceptance are **not yet verified or activated**. The upgrade purchase link stays at https://cajunwebpro.com until the owner assigns an exact URL.

Track the external work at https://github.com/afterburn25/Cajun_Web/issues/399. Adult_Core 1.0 still requires independent security and adult verification/consent, actual streaming/billing integrations and hosting certification.
