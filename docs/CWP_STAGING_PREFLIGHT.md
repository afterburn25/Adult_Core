# Cajun Web Pro: read-only staging acceptance probe

**Adult_Core 0.8.10-cwp-staging-dev — development only.** This milestone adds an opt-in operational diagnostic; it does **not** register a Cajun Web Pro product, issue a license, enroll a subscription, process payments, verify renewals in Authorize.Net, or certify deployment. [Cajun_Web #399](https://github.com/afterburn25/Cajun_Web/issues/399) still tracks the actual catalog and merchant staging work.

## Purpose and ownership

The authoritative issuer is the existing Cajun Web Pro POST /api/validate.php. It returns a product- and domain-bound Boolean authorized, not a subscription transaction, customer identity, plan price or next billing date. The installed Lite/Pro edition is decided by the verified license SKU. No new payment or license-issuing logic belongs in Adult_Core. Keep this tool outside the public document root and run it only with a **synthetic or owner-approved staging key** for an already-registered test domain.

## Private setup

Create the four software-family catalog products **through Cajun Web Pro's owner UI** using the exact SKUs in docs/CWP_CATALOG_SETUP.md, with actual reviewed price/term metadata. Prepare a staging license registered for a test hostname. Use a non-master customer grant; a master key authorizes unrelated domains and is expected to fail the negative-domain control. Never put a license key in Git, CI variables, web requests, command-line arguments, public HTML or screenshots.

Put the one staging key into a **private file outside the Adult_Core checkout and any public webroot**, owned by the operator and chmod 600 on Unix:

    chmod 600 /private/staging-adult-core-license.txt
    php scripts/audit-cwp-staging.php \
      --endpoint=https://staging.cajunwebpro.com/api/validate.php \
      --domain=staging-customer.example \
      --sku=SOFTWARE-ADULT-CORE-LITE-MONTH \
      --key-file=/private/staging-adult-core-license.txt \
      --confirm-readonly

Replace the sample hostname, endpoint and SKU with the **real, explicitly approved staging values**. The staging host shown is a placeholder, **not a claim that it exists**. HTTPS TLS peer and hostname validation are mandatory; redirects, queries, credentials, non-Cajun-Web-Pro hosts, nonstandard ports and arbitrary paths are refused. The production https://cajunwebpro.com/api/validate.php host additionally needs --allow-primary-host when a qualified operator has approved that read-only endpoint. There is no fallback to HTTP or arbitrary IPs.

## What one run does

Exactly **five** HTTP POST validation requests, no retry loop:

1. The supplied key/hostname with its specified Lite/Pro one-time/monthly SKU must return HTTP 200 authorized:true.
2. The same key/domain must be denied with HTTP 200 authorized:false against **each of the other three Adult_Core SKUs**. All other product grants must be checked; one negative check is insufficient.
3. The same key/SKU and the fixed `unregistered-audit-domain.invalid` hostname must be denied.

The audit must use a non-master license and the negative hostname must **never be registered** to the grant. Five requests stay within Cajun Web Pro's default ten-requests-per-minute per-IP limiter when no other validation calls are in flight; nevertheless, running multiple concurrent probes or repeating the audit within one minute may hit that limit. Any HTTP 429, malformed response, redirect, HTTP error or network failure is classified **inconclusive**, never an authoritative license denial. Output contains the SKU, edition, term, pass/fail/inconclusive checks and call count, but not the key or domain. Keys are not accepted on argv or via HTTP uploads.

**Exit codes:** 0 expected answers, 1 unexpected authorization/denial, 2 any transient/inconclusive answer, 64 invalid or missing invocation. Do not interpret a successful check as evidence of paid-through renewal or financial processing: Cajun Web Pro's software-commerce billing/entitlement data and actual gateway sandbox must be tested separately, with readback and cancellation. API validation may update successful-use telemetry/rate-limit state on the issuer; it does not intentionally mutate invoices, payments or subscription status.

## Repeatable offline checks

    php tests/cwp_staging_audit_tests.php
    python3 tests/cwp_subscription_contract.py

These use synthetic keys and injected responses, with **no network to Cajun Web Pro**. The suite checks key-file confinement/permissions, trusted endpoint policy, exact five-call negative controls across all four product SKUs, cross-SKU overauthorization, product and domain mismatch, malformed vendor replies, rate-limit/outage classification and CLI opt-in. Real HTTPS acceptance requires an authorized staging host and license, and is not executed in public GitHub Actions CI. No actual live subscription/payment should be claimed on the basis of these fixtures.
