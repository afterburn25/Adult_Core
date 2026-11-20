# Member password recovery — 0.8.4-dev

**Development feature, not a production-certified authentication system.** Recovery changes the account password and invalidates existing member sessions. It **does not** change `pending` membership status, confirm email, verify age/identity, grant access to content, or record performer consent. Administrator accounts are excluded: admin recovery and MFA remain separate work.

## Installation and configuration

1. On a **disposable** test site with a backup, run `/admin/setup` again to install the additive `m10_member_recovery_v1` migration (`ac_member_password_resets`).
2. Optionally configure `config/email.php` as documented in `docs/EMAIL_CONFIRMATION.md`. The same private fixed HTTPS origin and PHP `mail()` adapter are used for password-recovery emails. Actual mail deliverability is not certified on shared hosting.
3. Members use `/members/recover` to request a link. Only confirmed member email addresses can receive reset links. Both known and unknown addresses receive a neutral response; requests are throttled per client/email and per-account to once per five minutes.
4. Recovery links expire after 30 minutes. `GET /members/recover/reset?token=...` shows the reset form but does **not** consume the token; only a CSRF-protected POST with matching new passwords can use it. Invalid, expired and replayed tokens fail closed.
5. Successful reset stores a new `password_hash`, destroys the reset token, increments `session_generation`, and checks that generation on every member and private-media request. Existing sessions from other devices are rejected on the next request. The current browser session is revoked immediately.

## Security details

- Only a SHA-256 token digest is stored in MySQL; plaintext reset secrets are never persisted or logged. Public responses must not reveal whether an account exists or has a confirmed email.
- Passwords must be 12–72 **bytes**; reset passwords are hashed with PHP `password_hash()`. Existing administrator accounts cannot use this member-only flow.
- Reset tokens are issued only to `pending` or `active` members with independently confirmed email addresses. A reset never activates the member or overrides age/consent/paid entitlements.
- Strict HTTPS on non-loopback and the global `Referrer-Policy: no-referrer`, `Cache-Control: no-store`, CSRF and independent throttling protections remain required. Reset URLs can appear in browser history or email infrastructure; avoid analytics and third-party scripts on these pages.
- The database migration must exist before member routes work. Missing schema fails closed; do not bypass it by deleting installation files or manually forging migration rows.
- For a production release, add an independent security review, verified SMTP/transactional mail, improved delivery failure handling/alerting, and an administrative MFA and secure recovery mechanism.

## Verification

- Local tests: `php tests/site_member_tests.php`, `python3 tests/http_smoke.py` and the full PHP suite.
- Disposable MySQL integration: `php tests/mysql_install.php` covers issuance, cooldown, expiry, replay, password hash change, session generation, and pending status. `python3 tests/mysql_http_auth.py` covers browser GET preview, CSRF, neutral request response, successful reset, session invalidation, and old/new credentials.
- GitHub CI verification must be observed on the exact published commit before claiming MySQL-backed integration passed.
