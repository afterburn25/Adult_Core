# Adult_Core ↔ Cajun Web Pro subscription activation tests

**Version:** `0.8.6.7-subscription-contract-dev`, development-only. **Status:** automated contract simulation, **not live commerce testing, not merchant authorization, and not a completed Cajun Web Pro product onboarding.** No live Cajun Web Pro server, customer account, license key, issuer DB or payment gateway is contacted by these tests.

## What this adds

`python3 tests/cwp_subscription_contract.py` starts an ephemeral `127.0.0.1` HTTP server, modeling Cajun Web Pro's existing `POST /api/validate.php` contract. It calls a dedicated PHP CLI fixture, `tests/fixtures/cwp_subscription_client.php`, via real loopback HTTP and exercises **actual Adult_Core `CajunWebClient::resolve()`, `validate()`, `LicenseManager::verifyForInstall()`, `persistInitial()`, `status()`, and `replaceLicense()`**. The PHP fixture's test-only transport closure sends POST JSON to loopback and parses HTTP response status; it does **not** modify the shipping production HTTPS endpoint. Synthetic keys and signed caches live in disposable temporary directories; fixtures do not create a real Adult_Core MySQL installation. Tests require Python 3 and PHP (8.3+); there are no third-party Python dependencies.

Tests include:

- Four product-bound SKUs: Lite one-time, Lite monthly, Pro one-time and Pro monthly. The sole valid product determines edition and term, not an installer dropdown. An incorrect key/domain never activates.
- Initial monthly payment expired/unpaid → no license installation; a subsequently simulated paid-through period → activation.
- Cancellation at the end of the paid-through period: valid until expiry, followed by definitive issuer denial; neither local key replacement nor a cached result can fabricate payment.
- Renewal after issuer-side simulated payment: a signed previous denial remains until forced (or scheduled) revalidation; positive confirmation restores access. Revocation remains authoritative even after payment.
- HTTP `429`, `503`, `400`, `302` and malformed `200` bodies are inconclusive, not authoritative denial. Previously confirmed access receives only a bounded outage grace. A **previously denied** key does not gain grace from an outage.
- Host mismatch refused without an issuer call; vendor-approved domain transfer rebinds a new signed license and rejects the old host.
- Same-edition monthly→one-time key replacement is permitted; cross-edition replacement is refused and leaves the installed state unchanged. **Replacing a key never terminates an existing paid subscription.**

The administrator license view now explicitly discloses that Adult_Core only receives `authorized`/`denied`, cannot show the charge date, and does not cancel subscriptions. For now billing links point to `https://cajunwebpro.com`; no specific customer billing URL has been approved.

## Scope of verification and open gates

This exercise **does not prove** that the live Cajun Web Pro database, commerce workflows or API are deployed, that an actual card payment completed, that the Authorize.Net sandbox/production webhook and subscription reconciliation work, or that four catalog SKUs/prices exist. It tests a compatible **simulator**, not the actual Cajun Web Pro implementation. No Adult_Core production payment is enabled.

Before customers can subscribe, an authorized owner must complete [CWP_CATALOG_SETUP.md](CWP_CATALOG_SETUP.md): register four existing-system products exactly once with prices, `software` family, `once`/`month` term; configure an authorized sandbox; issue staging-only entitlements; test initial payment and renewal reconciliation against the **actual** issuer API, negative responses, trial/expiry, chargeback/revocation, domain and seat policies; verify a real HTTPS staging install and Pro upgrade; and independently review merchant/PCI/identity obligations. See [Cajun_Web issue #399](https://github.com/afterburn25/Cajun_Web/issues/399) for tracking. The external live stages require credentials, explicit authorization and owner decisions and are not performed automatically by CI.

Run full local tests from the repository root:

```sh
python3 tests/cwp_subscription_contract.py
php tests/licensing_tests.php
python3 tests/http_smoke.py
```

Disposable MySQL installer and authenticated browser checks run separately in CI. The owner must still commission a real commerce staging acceptance and production hosting/security review.
