## Private review feedback history — m15 (2026-11-21)

On each self-hosted installation, a moderator's request-changes or reject decision now preserves a **private feedback snapshot** identified by the exact submitted text-plan revision, local reviewer ID, decision, note and UTC timestamp. Snapshots are written transactionally with the decision and remain available even after the creator updates or resubmits the plan. They do not duplicate private text in the numeric-only draft action history.

A currently enabled local creator sees the latest 100 feedback decisions for their **own** text plans on `/members/creator`; the local owner/admin sees the latest 100 on the protected read-only `/admin/creators/drafts/{creator_id}` view, including decisions for a suspended profile. Both pages escape text and disable caching/search indexing. The full timeline is **not** visible to moderators through their review queue, other members, or anonymous visitors. The system **never** approves, publishes or licenses a creator through this process.

An owner runs **Admin → Setup** after a backup to apply repeatable `CreatorFeedbackSchema` m15 following m14; history views and review writes fail closed until it is applied. Existing m14 `review_note` data stays intact, but older decisions completed before this migration are **not reconstructed** or assigned guessed reviewers. Only decisions occurring after m15 are captured. The history is backed up by the encrypted local database backup/recovery workflow; real production drills remain outstanding.

---

## Reversible private plan archives — 2026-11-21

A creator can archive **only an unsent private text plan** from their own authenticated workspace. The owner-installed software retains that plan, its numeric action/audit history, and its slot under the 50-plan per-creator limit; archiving is explicitly **not deletion**. Archived plans stay visible to their owner as read-only records with a **Restore archived text plan** action. Restoration returns an archived plan to private editing without clearing or bypassing any external media, identity, consent, or payment gates.

Both operations require the active site-local creator grant, authenticated member session and CSRF, owned draft ID, expected revision, transactional locks and numeric-only events. A creator cannot archive a rejected, submitted or revision-requested plan, archive someone else's drafts, or skip moderation. The site owner can see the retained status and audit events on the protected local plan-detail page.

---

## Owner-only text-review event history

The owner-only read-only private plans screen now shows the **latest 100 numeric text-review events** for that creator (draft reference, local actor reference, action and UTC timestamp). Feedback and private plan text stay exclusively in their protected draft records, not the event log. Moderators still cannot access this owner-only screen; they see only submitted text in the restricted queue. The owner screen requires the additive text-review migration and fails closed with a setup prompt until it is applied. These are records of local draft workflow actions, not legal content/identity clearance.

---

## Withdrawing a pending text plan — 2026-11-21

Creators can now **Withdraw submitted text plan** while the local moderator has not made a decision. Withdrawal reopens their own plan as a private editable draft and records a numeric-only `withdrawn` event. The action checks member authentication, CSRF, the active local creator permission, draft ownership, submitted status, and expected revision in a transaction. It cannot undo requested changes or a rejection, withdraw another creator's work, bypass age/consent checks, or publish media.

---

# Local creator workspaces — self-hosted only

Adult_Core is **one downloadable, owner-installed application**. Each customer manages their own installation. There is **no central creator network, SaaS signup, vendor-side application or Cajun Web Pro approval of creators**.

## Enable creator planning for a member

After applying the additive Site setup migration, sign in as the owner or a local administrator and open **Admin → Local creators** (`/admin/creators`). Enter the **existing registered member's email address** (which must already be email-confirmed) and a creator display name. This enables a private, text-only planning workspace for that member on **this installation only**. No new login or password is needed; the member uses their existing authenticated account. This local assignment never changes `ac_users.role` or age-verification, consent, paid-entitlement or billing records.

The enabled member opens **Member account → Manage your private creator drafts** (`/members/creator`). They can create or revise a limited number of plain-text private planning drafts using protected forms with CSRF and version checks. Creator drafts are stored only in the new `ac_creator_drafts` table. They are **not** entries in `ac_content`, are never rendered on public pages, have **no upload endpoint**, and cannot be displayed through any member gallery/media URL. Nothing is published by saving a creator draft.

## Owner suspension and audit

The same local administrator can suspend or restore a creator's private planning workspace. Every status action is checked against the current revision, and suspension immediately denies creator workspace access without deleting saved private drafts or blocking ordinary member sign-in. Owner/admin actions and creator draft edits write numeric-only records to the local `ac_creator_actions` table, avoiding draft content and user emails in the audit.

The owner sees private draft **counts** on the creator-management page. By selecting **View private plans**, a local owner/admin can inspect read-only saved draft titles and descriptions through an authenticated, no-store, noindex URL; suspended creators' retained plans remain available to local owners for incident review. This view cannot publish or modify drafts. One creator cannot read or edit another creator's drafts. No vendor shares this local database or issues a cross-installation account. The role capability is managed in the local MySQL/MariaDB database under an active local owner/admin session and CSRF token, **without requiring the admin password on every change**.

## Submit a private plan for local text review

After creating or revising a private planning draft, the locally enabled creator can choose **Submit text plan for private review**. This sets its *planning* status to `submitted` and blocks further editing while it awaits attention. It remains separate from the public content/media system and is visible to no ordinary subscriber or public guest.

A signed-in **local** owner/admin/moderator uses `/admin/creator-review` to view **submitted text-only plans** and select either **Request changes** or **Reject this text plan**, supplying 10–500 bytes of feedback. There is **no approval/publishing action**. Feedback stays in the creator's private draft row; the associated numeric-only audit records do not contain the private text. The creator sees escaped feedback only in their own private workspace, and the local owner can inspect it in the protected read-only plan view.

A request for changes reopens an **editable** plan, but the creator must save a new private revision before the submit button appears again. Rejected plans remain retained and cannot be edited/resubmitted from the creator account. This text-only triage does not verify the legal age or identity of anyone involved, does not verify performer consent and must not be used to publish or monetize adult content.

The additive `CreatorReviewSchema` migration runs after the local creator schema and requires no central SaaS service. Deploy the updated ZIP, back up the site's DB, and apply **Admin → Setup** to enable text review. The new `ac_creator_draft_events` table has foreign keys and must be included in independent database backups and disposable-test cleanup.

---

## What this does not authorize

This is an early local planning workspace, **not** a creator-verification or publishing system. Email confirmation does not prove legal age, identity, performer rights or consent. Real video/image uploads, image re-encoding and malware scanning, independent age/identity/consent provider checks, content review, safe publish and takedown controls, approved adult-content payment fulfillment, signed upgrades and real cPanel/VPS deployments **remain separate release blockers**. Do not expose adult media merely because a member has creator planning tools.

## Database and tests

`CreatorSchema::migrate()` runs from Admin → Setup after the existing member and email migrations. It adds `ac_creator_profiles`, `ac_creator_drafts` and `ac_creator_actions`; it does not alter software licensing or introduce a remote service. Both the Lite and Pro source ZIP retain these classes. Run `php tests/local_creator_tests.php` for input/control tests; the disposable `php tests/mysql_install.php` and `python3 tests/mysql_http_auth.py` exercise real database and protected HTTP behavior against MySQL 8 and MariaDB 10.11 in CI. Production release acceptance is still blocked.

## Many local creators

The owner listing is paginated in descending local creator ID order, 50 per page, with an **Older local creators** link when more records exist. Cursor pagination never adds cross-site access or bypasses manager permissions, and prevents older profiles from becoming unmanageable under the per-installation catalog limit.

## Updating a creator's display name

The owner/admin can rename a local creator display label from the same `/admin/creators` listing. The change requires the current local manager role, CSRF token and matching profile revision; concurrent stale edits fail closed. It does not change the member's sign-in email, password, role, age/consent state or any saved private planning draft. There is no password prompt for each routine name change while the administrator's normal secure session remains active.
