## Optional read-only staging authority preflight (0.8.10)

Once the owner has provisioned an **actual staging-only** customer grant and reviewed test hostname, `php scripts/audit-cwp-staging.php` can test one SKU + registered hostname and two negative controls with three trusted HTTPS validation calls. See `docs/CWP_STAGING_PREFLIGHT.md`. It cannot create a product, key, paid invoice, subscription, or gateway receipt and cannot replace the real Authorize.Net sandbox lifecycle.

## 0.8.6.7 loopback subscription contract coverage

A stateful, real HTTP **local simulator** now verifies Adult_Core's client behavior for the four SKU products across simulated activation, paid-through expiry, renewal, cancellations, revocation, domain changes and 429/5xx outages. Run `python3 tests/cwp_subscription_contract.py`; see `docs/SUBSCRIPTION_ACTIVATION_TESTING.md`. The test does **not** seed live Cajun Web Pro catalog data, call its API, handle a real payment or check the actual authoritative checkout/renewal code. Explicitly verify a real sandbox issuer and payment renewal separately before launch. Swapping a site license does not cancel the original monthly billing agreement.

# Cajun Web Pro catalog setup for Adult_Core — staging checklist

**Status (2026-11-20):** Code-side integration is ready for staging; live Cajun Web Pro product catalog, payment enrollment and issuance have NOT been provisioned or verified. This guide makes no DB changes. Pricing, purchase terms and launch decisions require the Cajun Web Pro owner. No live card charging or background payments are authorized.

## Create four distinct product entries in the existing Cajun Web Pro catalog

Use **one downloadable Adult_Core installer**. Each key belongs to *one* SKU; the existing Cajun Web Pro validation API binds product, domain, and entitlement. In the owner catalog, create (or verify existing matching rows, never duplicate):

| Exact SKU | Title | `family` | `term` | Purpose |
|---|---|---|---|---|
| `SOFTWARE-ADULT-CORE-LITE` | Adult_Core Lite — One-time | `software` | `once` | Lite one-time |
| `SOFTWARE-ADULT-CORE-LITE-MONTH` | Adult_Core Lite — Monthly | `software` | `month` | Lite recurring |
| `SOFTWARE-ADULT-CORE-PRO` | Adult_Core Pro — One-time | `software` | `once` | Pro one-time |
| `SOFTWARE-ADULT-CORE-PRO-MONTH` | Adult_Core Pro — Monthly | `software` | `month` | Pro recurring |

- Start every new product as **draft**, USD. Supply the price, summary, legal terms and any update/installation-seat policies via the existing authenticated owner workflow. **No prices were invented by this project.** Do not publish until terms, eligibility, merchant configuration and real customer support are reviewed.
- Cajun Web Pro's published catalog requires a positive price, a nonempty summary and contract terms. Recurring products must have **zero one-time setup fee** for its software order flow. Confirm every edition/term SKU is unique and exact; the legacy `SOFTWARE-ADULT-CORE` SKU is not valid for the current unified installer.
- Review product policies, dependencies, allowed domains and seats with an owner. Keep master license/test issuance internal; never include real keys, card data or production customer details in source control, tickets, CI or documentation.
- An authorized paid invoice and matching software entitlement are required for ordinary customer issuance. Verify the monthly subscription's paid-through expiry and that failed payment, cancellation and revocation cause explicit license refusal when the entitlement lapses. Verify renewal restores authority only after Cajun Web Pro records an eligible verified payment.
- The installer calls `/api/validate.php` once per candidate SKU (maximum four) because this is **a per-product validation API, not a SKU discovery API**. Cajun Web Pro has an IP rate limit; avoid repeated attempts. All four keys map to the same codebase; the verified license product selects Lite/Pro.

## Acceptance matrix (staging only)

1. Confirm draft → published product records, exact `family`/`term`/unique SKU and owner-reviewed pricing; prepare authorized subscription sandbox. Do not enable production charging from this guide.
2. Issue *staging-only* customer entitlements via approved owner workflows. Confirm four key/product combinations independently authorize their registered domain, deny wrong SKU and wrong domain, and reject deactivated, expired and seat-exhausted grants.
3. Install the **same ZIP** with an authorized Lite one-time key, Lite monthly key, Pro one-time key and Pro monthly key on disposable compatible staging hosts. A Pro key on a Lite-only server must block installation. Confirm no edition form or URL overrides key authority.
4. On an installed staging site, use **Admin → License → Check license now**. This action sends the key to Cajun Web Pro over verified HTTPS, is rate limited and requires site-management permission and CSRF. Test valid, denied, HTTP 429/5xx and unreachable vendor responses. No key should appear in HTML/logs.
5. Verify a confirmed denial immediately locks normal access; a temporary outage allows no more than the existing signed 72-hour grace after last success. Fresh, inconclusive results are distinguished from an authoritative activation in the administrator interface.
6. Verify monthly renewal, expired paid-through term, cancellation, recovery after verified renewal, customer domain changes and Pro upgrade to the same installed site. Test no content/member loss and preserved private state.
7. Confirm independent signed add-on module SKUs have their own purchase and validation history. No cross-product reuse of a Lite, Pro, or unrelated add-on license.

## Release prerequisites

There is no automated live catalog seeding, production payment enablement or public upgrade sales URL in this milestone. The placeholder purchase/upgrade link remains `https://cajunwebpro.com`. See [Cajun Web Pro issue #399](https://github.com/afterburn25/Cajun_Web/issues/399) for owner-controlled staging work, and `docs/RELEASE_GATES.md` for the still-open Adult_Core 1.0 gates. Any external staging license and merchant integration must be tested without customer data and independently reviewed before sale.
