# Adult_Core — ten-workstream implementation and release acceptance

**Status: development. Production launch is blocked.** The unified Lite/Pro application remains one installable source package, with Cajun Web Pro license-key selection. This document is a release engineering checklist, **not** a substitute for an external signoff, a hosting test or a merchant/identity provider contract.

Show the canonical machine-readable blockers from the project root:

```sh
php scripts/release-matrix.php --json
php scripts/release-check.php
```

Both intentionally exit nonzero while production is not certified. Never bypass the checks by editing status JSON, creating fake keys or injecting an administrator approval.

| # | Workstream | Development progress | Acceptance evidence still needed |
|---|---|---|---|
| 1 | Cajun Web Pro software sales/licensing | Four exact license SKUs, one installer, domain-bound edition checks and local subscription simulations | Owner-approved catalog products/pricing, real staging entitlements, sandbox checkout/renewal/cancel/failure, correct domain/SKU rejections |
| 2 | Lite cPanel and Pro VPS | Capability-aware browser installer, CLI host preflight and HTTPS private-path checks | Two real isolated disposable hosts, actual DNS/TLS/Apache document root and installed Lite/Pro, Pro background service proof, failed-host rejection |
| 3 | Identity and performer consent | Signed assertion contract, private hashed evidence receipts, default-deny performer roster and admin evidence summary | Contracted age/identity provider integration, performer consent and records policy, actual provider lifecycle, jurisdictional legal review |
| 4 | Member billing | Verified-event inbox and conflict-replay-denying MySQL test; paid access policy defaults to deny | Approved processor, provider-specific signed webhooks, active subscriptions, renewal, refund, dispute, chargeback, settlement and cancellation |
| 5 | Private creator content | Quarantined draft/report/moderation foundation, protected media, entitlement-filtered member library with no unsafe previews | Controlled uploader, permissioned review/publish/takedown, full content state lifecycle, media scanning and lawful verification |
| 6 | Pro video/streaming | Narrow signed HMAC jobs, replay-conflict protected receipt and agent preflight | Production FFmpeg worker/queues, secure segmented playback, live streaming, authorization expiration, VPS load/soak tests |
| 7 | Add-ons and updates | Signed data-only packages, edition compatibility, paid module activation recovery | Trusted execution API/sandbox design, signed version updates with tested rollback and migration compatibility |
| 8 | Design and member UX | Responsive admin, design studio, draft pages, member accounts and private library | Complete page builder, tier/creator dashboard, usable mobile/accessibility and cross-browser acceptance |
| 9 | Operations and disaster recovery | Authenticated encrypted archive/vault, CLI restore fixture, host preflight and maintenance gates | Real cPanel/VPS restore drills, monitoring, incident response, offsite retention, tested source/package updates |
| 10 | Security, compliance, release | Automated regressions, ten-workstream blocker CLI and fail-closed readiness checker | Independent security assessment, privacy/consent/legal review, accessibility, performance, incident and support signoff |

## Operator-controlled actions

For **#1**, complete the owner-only checklist in [CWP_CATALOG_SETUP.md](CWP_CATALOG_SETUP.md) and track the external work in [Cajun_Web issue #399](https://github.com/afterburn25/Cajun_Web/issues/399). Do not commit license keys, billing tokens, card data or staging customer records. The existing read-only staging probe is useful only after the owner has created **real** products and ordinary customer keys.

For **#2 and #9**, first run the read-only checks in [DEPLOYMENT_PREFLIGHT.md](DEPLOYMENT_PREFLIGHT.md) and [STAGING_HOST_HTTP_ACCEPTANCE.md](STAGING_HOST_HTTP_ACCEPTANCE.md), then independently witness a fresh HTTPS install and a restore drill on **each actual hosting category**. CI's localhost PHP server and disposable MySQL do not certify cPanel or VPS.

For **#3 and #4**, record the chosen contracted third-party providers and review their actual signed callback protocols and regulatory/acceptable-use terms before enabling uploads, subscriptions or adult-media access. No generic HMAC utility, local sample callback or signed receipt should count as provider integration. Apply the correct legal and operational controls before public access.

For **#5 through #8**, continue implementation in small reviewable commits with executable tests. The private library is text-only for permitted records; no creator upload, external verification, adult membership checkout or executable third-party add-on capability is implied. No publication or billing is enabled by this matrix.

For **#10**, require independent, documented acceptance of the other nine tracks and the controls in [RELEASE_GATES.md](RELEASE_GATES.md). Keep the release checker nonzero until those gates have verifiable signoffs and production incident-recovery procedures.

## Required safety rules

- Use a **disposable** development database for tests that create or remove tables. Production/staging customer databases must never be passed to CI test scripts.
- Keep private keys, API secrets, license snapshots, performer records, backups and payment metadata out of GitHub source, Actions logs and public web paths.
- A green CI workflow proves only that the tested local contract passed. It does **not** imply valid payments, performed identity checks, lawful consent or actual production deployment.
- Maintain the installed software's fail-closed routes when age, consent, moderation, payment or license state is incomplete. Neither member registration nor an uploaded file authorizes adult delivery.
