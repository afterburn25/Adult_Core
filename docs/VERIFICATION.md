# Independent verification adapter — 0.8.2-dev (NOT production verification)

This is a **protocol scaffold**, not a configured age-verification vendor and not legal compliance by itself. No provider is bundled. The `/webhooks/verification` endpoint is **disabled by default** until `config/verification.php` exists, has a verified adapter configuration, and `enabled` is explicitly true. Never use a self-issued HMAC secret as a substitute for an approved independent provider.

## Provider requirements before enabling

- Contract with a provider whose API and jurisdictional coverage meets the site's requirements. Obtain written specifications and validate the provider's genuine signature format, adult-age rules, performer identification, consent and revocation semantics, retention, and response times. The current HMAC envelope is *Adult Core's own proposed transport*, not any vendor's official webhook format.
- Build and review a vendor-specific adapter that converts authenticated provider results into the six-field internal claim format below. The provider must issue the results; a website operator must **not** be able to self-issue a successful verification event. Never log the raw provider body, documents, legal names or biometric identifiers.
- Store the adapter secret only in `config/verification.php` outside `public/`, owned by the PHP user, with restricted permissions. Use HTTPS and rotate shared keys through a coordinated maintenance window. `config/verification.example.php` is intentionally disabled.

## Internal signed claim contract

POST JSON (max 8 KiB) to `/webhooks/verification`, with `X-AC-Timestamp: <Unix seconds>` and `X-AC-Signature: sha256=<64-lowercase-hex>`. Signature: HMAC-SHA256(secret bytes, `timestamp + '.' + exact_raw_body`). Timestamp must be within 5 minutes of server time.

```json
{
  "event_id": "provider_event_123456",
  "claim_type": "member_age",
  "subject_id": 123,
  "decision": "verified",
  "reference": "opaque-provider-ref-123456",
  "occurred_at": 1795100000
}
```

- `claim_type`: `member_age`, `performer_age`, or `performer_consent`.
- `subject_id`: an existing member ID or an existing **individual performer record ID**, not a content ID. The site admin may declare a private roster in `/admin/moderation` but cannot mark that evidence approved.
- `decision`: `verified` or `revoked`; use revocations to immediately remove prior assertions and re-quarantine affected media.
- `occurred_at`: provider event UNIX time, no more than one day old or five minutes in the future. Events are applied only if newer than the last event for that subject and claim type; duplicate event IDs are idempotent. Provider reference is stored as SHA-256 only.

**Do not send real identity documents in webhooks or HTTP logs.** Subject-specific consent must include an independent binding to the exact content/media, and authorization for all depicted people. The draft protocol above is not sufficient to prove that binding yet.

## Fail-closed semantics

`VerificationSchema` adds `ac_verification_receipts` and `ac_content.expected_performers` via additive `/admin/setup` migration. An unknown expected performer count (0) or mismatch with fully approved performer records **denies protected media access**. New roster records begin pending. Verification callbacks do **not** activate members, approve content, approve performer records, or publish media.

Age assertion revocation removes the prior `ac_age_verifications` row; performer assertion changes reset record review status to `pending`, invalidate media review, and quarantine already published content. Moderation reports remain independent blockers. Only a separately built and legally reviewed approval flow could ever publish real adult media.

## Tests

`php tests/verification_tests.php` exercises signature/timestamp/body validation. The disposable MySQL integration in `tests/mysql_install.php` checks replay prevention, stale-event rejection, age revocation, performer consent revocation, and incomplete-roster denial. CI is required for the MySQL tests; the local environment has no `pdo_mysql`.
