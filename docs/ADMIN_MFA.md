# Administrator two-factor authentication — 0.8.7-mfa-dev

**Status:** development feature. MFA is available for the existing administrator account, subject to real-MySQL/browser CI. It is not production security certification. There is no self-service email/SMS bypass or vendor-mediated reset.

## Setup

1. Back up the database and private `config/` and `storage/` directories, including the application key, using a tested off-server backup.
2. On an existing installation, sign in with the current administrator credentials and apply `/admin/setup`. The additive `ac_admin_mfa` table uses a foreign key to the existing admin account; running the migration twice is safe. Fresh installs create the table automatically.
3. Open `/admin/security`. Re-enter the current admin password to start a 10-minute enrollment window.
4. Manually add the displayed Base32 key to an authenticator app as a time-based (TOTP) SHA-1, 6-digit, 30-second account. **No third-party QR generator or secret-handling service is used.**
5. Confirm a current authenticator code. The app generates ten 16-hex-character recovery codes; save them offline. They appear exactly once, not again in settings, backups displayed in browser, or email.
6. On subsequent sign-ins the password phase enters a 5-minute unprivileged challenge session at `/admin/mfa`. Enter a new six-digit code or one unused recovery code to obtain a normal admin session.

MFA applies to both Lite and Pro using the same installer. It does not change the Cajun Web Pro commercial license or billing term. Account recovery is not an adult-member password reset.

## Threat model, protections, limitations

- TOTP is standards-based RFC 6238 SHA-1 with a 30-second step and one-step drift allowance. Each accepted counter is recorded under an InnoDB `FOR UPDATE` row lock so the same code cannot be used again, including in a parallel request.
- Base32 secrets are encrypted with application-key-derived AES-256-GCM, using fresh 96-bit nonces and authenticated context. Changing or losing `config/app-key.php` invalidates stored MFA and recovery factors; it is a critical component of the off-server backup.
- Recovery codes are 64 random bits each, stored only as application-keyed SHA-256 HMAC digests. They are consumed once in the same locked database transaction. They are **not** passwords: a valid password is always required first at sign-in and again to disable MFA.
- Password authentication does not generate a privileged session if the account is enrolled. A successful second factor rotates the session ID. Existing password-only sessions are rejected after enrollment. All admin routes revalidate the administrator role and enrolled MFA status.
- Session cookie is HTTP-only, `SameSite=Strict`, Secure on HTTPS. Administrator HTTP is refused outside local loopback. CSRF is checked on setup, verification and disabling, and IP/account file-backed rate limits apply to challenges and settings.
- Enrollment requires current password confirmation. Disabling requires both the password and a fresh authenticator or unused recovery code. No remote disable, staff override or secret recovery endpoint is implemented.
- A missing MFA database table or record after prior enrollment fails closed using the separate `ac_settings.admin_mfa_enforced` marker. Older installs with no prior enrollment may sign in to apply the migration. Database administrators who can alter *all* tables and the app key are beyond the protection boundary.
- App clock must be reasonably synchronized. This release does not include passkeys/WebAuthn, backup-code regeneration without disable/re-enroll, QR scanning, phishing resistance, session-wide revocation after changing admin password, or shared-admin account management.
- If both device and recovery codes are lost, **restore a verified off-server backup or perform an audited manual owner recovery**. Do not remove a table, config, or installer folder to bypass the gate.

## Tests and operational verification

`php tests/mfa_tests.php` checks the RFC vector, clock window, counter replay, Base32 key length and authentication session transitions. Disposable MySQL integration tests exercise encryption and persistent one-time factor consumption, destructive-row detection and re-enrollment. Real HTTP tests cover password reauthentication, CSRF, secret enrollment, recovery-code display, password-only denial, challenged sign-in, code reuse, disable and recovery. CI requires an explicit disposable database; no live credentials/charges/identity providers are used.

Keep this feature behind a production security review including live HTTPS and actual host/backup certification. Always restore and test an off-site backup before modifying a real deployment.
