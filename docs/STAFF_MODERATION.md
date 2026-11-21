# Restricted moderation-team accounts (development)

This is a self-hosted, manager-controlled workflow. It is **not** an external staff identity provider, email-invitation service, age/performer verification provider or production security certification.

## Staying signed in while editing

Administrators use a single secure **session-ID cookie**, not a stored password or a separate browser credential. This cookie can survive closing and reopening the browser for up to **7 days**, but authentication ends earlier if idle for **24 hours**, the account is revoked, or you sign out. Cookies are HttpOnly, SameSite Strict and Secure on HTTPS.

Your password login automatically unlocks staff changes for **30 minutes**. While that window is active, adding, suspending and restoring moderators requires the normal form/CSRF checks but **no repeated manager-password entry**. If 30 minutes elapse, open **Admin → Moderation team**, enter your current password in the **Unlock staff changes** form once, then continue. A separate 30-minute window begins after verification.

This is a bounded convenience feature. It does **not** remove password or MFA checks for unrelated high-risk tasks, and never extends a revoked or expired session.

## Initial setup

Apply the additive migration in **Admin → Setup** after an off-server backup. `StaffSchema` adds `ac_staff_actions` (numeric actor/target/action/UTC metadata; no passwords) and `ac_users.staff_session_epoch` (an integer revocation counter). Both Lite and Pro use the same code and licensing; staff management does not affect edition capabilities.

## Provision a moderator

A signed-in owner/administrator with `members.manage` opens `/admin/staff` over HTTPS, enters the staff member's email and a **unique 16–72-byte initial password**, and confirms their own current manager password. The site stores only a password hash, assigns `role='moderator'` and `status='active'`, and writes an action record transactionally. A dedicated manager-password attempt limit and CSRF protection apply. There is no owner/admin promotion option, and existing member/manager accounts cannot be converted by this route.

**The site does not send an invitation email or independently establish staff-email ownership.** The manager must independently verify who receives the credentials and transmit the initial password through an appropriate secure channel; never use the manager's own password. Staff should change that initial password promptly in **Admin → Account security**; this change rotates their revocation counter and signs out their current browser.

The manager's account list shows each moderator's **Enrolled** or **Not enrolled** authenticator status without exposing MFA setup keys, TOTP codes or recovery credentials. This is a readiness indicator, not verification of the person's identity.

The moderator's menu contains **Moderation**, **Account security** and **Sign out** only. The route layer independently denies licensing, modules, public-page editing, edition upgrade, tier management and other manager-only areas.

## Suspend and restore

Only a manager can suspend an active moderator at `/admin/staff` during a current 30-minute manager authorization window. Re-confirm the manager password only once when the window has expired. Suspended staff cannot log in, and existing sessions are refused on their next request. Suspension increments the private account's session epoch.

A manager can restore a suspended moderator only while setting a **different** 16–72-byte password (the exact previous password is rejected) and confirming the manager password again. Restoration increments the epoch a second time. Even if an old browser never contacted the site while the account was suspended, its original authenticated session can **never** be revived by restoration. Pending moderator MFA challenges bind the epoch captured after password verification and likewise cannot survive the suspension/restoration cycle. Staff who have MFA enabled still need their valid authenticator/recovery codes after restoration; a credential change does not silently disable MFA.

This prototype intentionally provides **no account deletion** or promotion path. Retained audit rows and linked moderator content may have legal/security retention obligations.

## What this does not release

Real signed HTTPS deployment on a cPanel host or Pro VPS, independent security review, verified email deliverability, employer/offboarding policy, identity/age and performer consent service contracts, adult-approved payments, creator publishing, media processing and backups/offsite drills are **still separate open release gates**. Do not run a commercial adult-content site solely because development SQL and HTTP tests pass.
