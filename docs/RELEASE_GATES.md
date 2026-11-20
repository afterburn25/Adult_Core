# Release gates — proposed versions 0.6 through 1.0

**This is a development snapshot, NOT Adult_Core 1.0.** No user account, adult content or card processing should be launched from this code. `php scripts/release-check.php` intentionally exits nonzero until all production gates are closed.

## Completed in this development iteration

- `0.6` **alpha**: additive `ac_pages` schema, safe plain-text editor, draft/published page lifecycle, published public pages with HTML escaping, header/navigation; selected signed theme CSS applied. Only the application core's trusted template code renders content. No arbitrary uploaded PHP/JavaScript.
- `0.7` **alpha**: separate member sessions, CSRF, password hashing and rate limiting, registration and login. All new accounts are `pending`. No age verification is inferred from registration; there is no self-approval route.
- `0.8` **foundation + triage**: additive private content tables, default-deny `Entitlements`, moderation and consent evidence predicates, private image key and storage logic, guarded `/media/{id}` endpoint. Quarantine and report review exist, but no adult content upload, verification or publication approval workflow or end-to-end consent verification; do not use for public adult media.
- `0.9` **foundation inherited from 0.5**: verified-event inbox and HMAC utility. No approved processor, checkout, recurring billing, refunds, settlement reconciliation or payment webhook endpoint.
- `0.10` **foundation**: signed HMAC job envelopes, replay-safe private Pro job receipt with narrow payloads. No FFmpeg worker, VPS provisioning, streaming or WebSocket runtime.
- `1.0` **release gate**: `ReleaseReadiness` and `scripts/release-check.php` intentionally block claims of a certified production release.

## Before release 1.0

- Independent assessment of every route and session boundary (CSRF, auth, role checks, IDOR, SSRF, media delivery, upload processing, secrets, logs, database errors).
- Mandatory adult age/identity verification for all applicable jurisdictions, records and consent workflow for every depicted performer, moderation, takedown and complaint workflows, retention, audit and review.
- Compatible adult-content payment provider approval, correct processor-specific signed webhooks/replay windows, refunds and chargebacks, reconciliation and entitlement revocation.
- Full Pro deployment, transcode workers, adaptive streaming, authentication on media segments and live-stream security.
- Reliable package update/rollback, integrity-protected encrypted backups, restore drills and installer recovery across shared hosting and VPS.
- Mail verification, administrative MFA/recovery, abuse controls, accessibility, privacy, logging and performance tests.
- Reproducible distribution package (assets, dependencies and checksums), Apache/cPanel installations, shared-hosting limitations and real VPS tests.

## Manual verification

`php tests/run.php`  
`php tests/package_tests.php`  
`php tests/advanced_tests.php`  
`php tests/site_member_tests.php`  
`php tests/pro_release_tests.php`  
`php tests/moderation_tests.php`  
`python3 tests/http_smoke.py`  

For disposable MySQL CI, see `docs/TESTING.md`. Testing a localhost PHP development server is not proof of Apache/cPanel compatibility.

Never add payments or adult uploads until the relevant requirements are implemented and independently verified.
