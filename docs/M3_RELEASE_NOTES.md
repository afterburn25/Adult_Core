# Adult Core — Milestone 3 (2026-11-20)

## New functionality

- `/admin/login` — private administrator login with server-side credential verification, CSRF protection, credential throttling and session ID rotation.
- `/admin` — authenticated read-only administrator dashboard showing installed site name/edition/user count. Requires live `admin`/`owner` role validation on every request.
- `/admin/logout` — POST-only, CSRF-protected sign out that invalidates the session.
- Private PHP session storage, strict session cookie options, 30-minute idle and 12-hour maximum session lifetime. Unauthenticated administrators redirect to login.
- Public website remains a deliberate 503 placeholder. Mandatory install-folder deletion and signed install marker enforcement remain intact.

## Verification

- 62 PHP tests passed locally.
- 20 HTTP smoke checks passed locally (without a real MySQL connection).
- 25 PHP files passed lint; Python test sources compiled.
- Opt-in disposable MySQL installation and HTTP auth tests are included in CI **but were not executed locally** because the environment lacks PDO MySQL and a MySQL server.

## Important limitations

This is **not production-ready** and has no real member signup, payments, content access, age/consent compliance, moderation or video streaming. The read-only admin UI is a development foundation. Real MySQL auth, Apache/cPanel deployment, security review and MFA still require testing/development.

## Next task

Validate M2/M3 on disposable MySQL and Apache/cPanel HTTPS, then begin M4 signed add-on package installation and optional administrator MFA/recovery. Future agents: see `AGENTS.md`, `docs/SESSION_HANDOFF.md`, `docs/ROADMAP.md` and `docs/SECURITY.md` first.
