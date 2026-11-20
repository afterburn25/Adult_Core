# Member email confirmation — 0.8.3-dev

This feature establishes **inbox ownership**, not legal adult age, identity, performer consent, payment eligibility, or permission to view content. All new accounts remain `pending`. Registration and email confirmation cannot activate an account. An age assertion cannot bypass the email confirmation prerequisite.

## Deployment

1. Back up the database, then sign in to `/admin/setup` and apply the additive migrations. This creates `ac_member_email_confirmations` and migration `m9_member_email_v1`.
2. Optional outgoing email on shared hosting: copy `config/email.example.php` to private `config/email.php`; set `enabled=true`, a fixed HTTPS `base_url` (origin only, no path/query, no trailing slash), and a validated `from` address. Never derive the confirmation URL from the incoming Host header. Keep `config/email.php` out of source control and outside the public web root.
3. Ensure the host supports PHP `mail()` and reliable delivery. This implementation **does not configure SMTP**, guarantee inbox delivery, or automatically activate members. A proper SMTP/transactional email adapter and delivery metrics are future work.
4. Members can view `/members/email` and request a new link when delivery is enabled; successful signup attempts to email a link when configured. A new request invalidates the previous link and is limited to once every five minutes per member.
5. Confirmation links last 30 minutes. GET only presents a form; CSRF-protected POST consumes the token. Only a SHA-256 hash is stored. Expiration, replay, invalid tokens, revoked members, and unknown users fail closed.

## Security and limitations

- Disable or omit private `config/email.php` to keep sending disabled. A member cannot confirm an email without access to a delivery provider or approved administrative remediation. Do not fake confirmations or use the CLI fixture against real member databases.
- The site has not been audited on real shared hosting; `mail()` may be disabled and deliverability is host-dependent. Never send passwords, adult media, identity documents, or performer consent documents through this module.
- The repository's test-only CLI token issuer, `tests/mysql_http_fixture.php issue-email`, requires an explicitly disposable database confirmation and is not a public route.
- `Entitlements` now refuses member private content when `ac_member_email_confirmations.confirmed_at` is absent, even if age verification and entitlements are otherwise present. Existing active accounts must use the email flow; do not introduce a silent bypass.
- Users should never have to upload government identification to confirm an email address. Adult age/identity is a separate provider-dependent workflow requiring appropriate legal and privacy review.

## Verification

`php tests/site_member_tests.php`, `php tests/run.php`, and other local PHP suites can run without MySQL. `php tests/mysql_install.php` verifies one-use, cooldown, and expiration with disposable MySQL; `python3 tests/mysql_http_auth.py` checks GET preview, CSRF, valid POST, and replay in a browser-like session. GitHub Actions runs these with ephemeral MySQL. No real email is transmitted by CI.
