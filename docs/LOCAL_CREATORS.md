# Local creator workspaces — self-hosted only

Adult_Core is **one downloadable, owner-installed application**. Each customer manages their own installation. There is **no central creator network, SaaS signup, vendor-side application or Cajun Web Pro approval of creators**.

## Enable creator planning for a member

After applying the additive Site setup migration, sign in as the owner or a local administrator and open **Admin → Local creators** (`/admin/creators`). Enter the **existing registered member's email address** (which must already be email-confirmed) and a creator display name. This enables a private, text-only planning workspace for that member on **this installation only**. No new login or password is needed; the member uses their existing authenticated account. This local assignment never changes `ac_users.role` or age-verification, consent, paid-entitlement or billing records.

The enabled member opens **Member account → Manage your private creator drafts** (`/members/creator`). They can create or revise a limited number of plain-text private planning drafts using protected forms with CSRF and version checks. Creator drafts are stored only in the new `ac_creator_drafts` table. They are **not** entries in `ac_content`, are never rendered on public pages, have **no upload endpoint**, and cannot be displayed through any member gallery/media URL. Nothing is published by saving a creator draft.

## Owner suspension and audit

The same local administrator can suspend or restore a creator's private planning workspace. Every status action is checked against the current revision, and suspension immediately denies creator workspace access without deleting saved private drafts or blocking ordinary member sign-in. Owner/admin actions and creator draft edits write numeric-only records to the local `ac_creator_actions` table, avoiding draft content and user emails in the audit.

The owner sees private draft **counts** on the creator-management page. By selecting **View private plans**, a local owner/admin can inspect read-only saved draft titles and descriptions through an authenticated, no-store, noindex URL; suspended creators' retained plans remain available to local owners for incident review. This view cannot publish or modify drafts. One creator cannot read or edit another creator's drafts. No vendor shares this local database or issues a cross-installation account. The role capability is managed in the local MySQL/MariaDB database under an active local owner/admin session and CSRF token, **without requiring the admin password on every change**.

## What this does not authorize

This is an early local planning workspace, **not** a creator-verification or publishing system. Email confirmation does not prove legal age, identity, performer rights or consent. Real video/image uploads, image re-encoding and malware scanning, independent age/identity/consent provider checks, content review, safe publish and takedown controls, approved adult-content payment fulfillment, signed upgrades and real cPanel/VPS deployments **remain separate release blockers**. Do not expose adult media merely because a member has creator planning tools.

## Database and tests

`CreatorSchema::migrate()` runs from Admin → Setup after the existing member and email migrations. It adds `ac_creator_profiles`, `ac_creator_drafts` and `ac_creator_actions`; it does not alter software licensing or introduce a remote service. Both the Lite and Pro source ZIP retain these classes. Run `php tests/local_creator_tests.php` for input/control tests; the disposable `php tests/mysql_install.php` and `python3 tests/mysql_http_auth.py` exercise real database and protected HTTP behavior against MySQL 8 and MariaDB 10.11 in CI. Production release acceptance is still blocked.

## Many local creators

The owner listing is paginated in descending local creator ID order, 50 per page, with an **Older local creators** link when more records exist. Cursor pagination never adds cross-site access or bypasses manager permissions, and prevents older profiles from becoming unmanageable under the per-installation catalog limit.
