## Private draft image uploads — 2026-11-21

An authenticated `owner` or `admin` with content moderation permission can now use `/admin/moderation` to select an **existing unpublished, unreviewed, private draft** and upload a JPEG, PNG or WebP image (maximum 8 MiB, or a lower PHP hosting upload limit). This uses real HTTP upload validation, binary signature/image inspection, an unguessable key, and `storage/private-media` outside `public/` with private filesystem permissions. The content is locked during the operation, only up to 40 images per draft are permitted, and the new `ac_media` record is **always pending**. The action is audited. If a database write fails after moving a file, the service attempts to remove the unreferenced private blob. No public URL or identity/performer evidence is accepted by this form.

The moderator queue shows the pending image count without filenames, private storage keys or unapproved previews. Uploaded images are **not published or approved**; even a logged-in member cannot view them through `/members/library`, `/members/library/ID` or the guarded `/media/ID` endpoint. A real independent verification, review/approval, media sanitization and publication workflow remains necessary. Do not use this as a production adult-content ingestion pipeline: scanning, EXIF/geolocation metadata removal, moderation appeal/review, performer legal recordkeeping and provider/merchant contracts are outstanding.

The disposable real-HTTP test uploads a test PNG through a multipart form, rejects invalid CSRF and non-images, checks the admin pending count and verifies that anonymous/unverified members cannot fetch the private image. The separate disposable gallery test creates a controlled, synthetic approved record for regression testing and then revokes consent; it is **not** the moderation UI and cannot be used as genuine verification or live publishing.

---

## 0.8.2-dev addition: pending performer roster

The admin moderation form may declare the number of depicted performers on an unreviewed draft and creates one pending record per performer. It does not authorize any content or collect identifying documents. Declaring a roster is one-time; corrections require a new review workflow. The private content entitlement engine now rejects `expected_performers=0` or any mismatch between expected count and complete approved records. Performer identity/consent callbacks do not mark records approved. See `docs/VERIFICATION.md`.

# Content safety / report triage — 0.8.1-dev (not a live publishing workflow)

## Current capabilities

- An authenticated administrator opens `/admin/setup` to apply the idempotent `ModerationSchema` migration **after** member and private-content tables. Back up databases before applying DDL; shared-host MySQL DDL does not roll back.
- Administrators open `/admin/moderation` to create **private, unpublished** content metadata drafts, review the current queue and incoming reports, quarantine/reject content, and close/dismiss reports. Every mutation requires POST + admin CSRF + current `admin`/`owner` role. Actions are appended to `ac_moderation_actions` with actor, reason and time.
- Logged-in members (including pending users) open `/members/report` or `/members/report?id=N` to report a content ID. Requires a valid CSRF token and a 10–1000-byte reason. At most one report per member per content per 24 hours, serialized by row locking. A report atomically quarantines the content AND resets all associated media review states to `pending`.
- The entitlement policy additionally denies all access whenever a report is open. Closing a report does **not** automatically restore quarantined or rejected content; a fresh external verification and fully designed publication workflow will be required in a later release.
- User-provided report reasons appear only in the authenticated admin queue, HTML escaped. Never put real ID documents or unredacted identity/performer evidence in report reasons; no upload endpoint for those materials exists.

## Deliberate limitations / security review

- No `approve` or `publish` handler is provided in this iteration. A moderation decision is not a substitute for age/identity verification, performer consent, or legal recordkeeping. The private draft **image staging** UI does not approve or publish any uploaded content; creator-controlled publication and video uploads remain unimplemented.
- Any pending account can report a content ID; this makes it easier to surface urgent safety issues but can be abused to quarantine content. Production needs email confirmation, signup/report abuse detection, trusted moderation escalation, appeal workflows, record retention and incident response.
- `report_id`/`content_id` values are not sensitive, but repeated missing IDs receive a generic success response to minimize enumeration. Secure uploaded evidence should eventually be stored with strong access and retention controls, never exposed in the admin HTML or logs.
- A closed report remains in the database; admin decisions are append-only. No deletion, automated moderation, account reinstatement, independent verification provider, or provenance completeness proof is available.
- This is an engineering prototype for use with disposable test accounts/content only. **Do not deploy for public adult-content use.**

## Tests

`php tests/moderation_tests.php` performs local validation checks. Disposable MySQL integration in `tests/mysql_install.php` checks migration idempotency, private drafts, automatic quarantine, duplicate suppression, audit, report closure, and denial of unsafe approval. Browser integration in `tests/mysql_http_auth.py` covers CSRF, admin draft/rejection, member safety reporting, and the queue. Local CLI lacks `pdo_mysql`, so these must be verified in GitHub CI before declaring database integration passed.
