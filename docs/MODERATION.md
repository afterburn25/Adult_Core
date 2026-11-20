# Moderation and member safety reports — 0.8.1-dev

Development-only. This is not an adult publishing or identity verification system.

## Administrator setup and triage

After database backup, visit /admin/setup and re-apply additive migrations. /admin/moderation lets authorized administrators create unpublished private content drafts, inspect reports, quarantine or reject items, and resolve or dismiss reports with reasons. Routes require the existing installation cleanup gate, HTTPS, authenticated administrator role and POST CSRF. Each decision is appended to private ac_moderation_actions.

## Member reporting

Logged-in members visit /members/report or /members/report?id=N. Reasons require 10–1000 bytes and a CSRF token. A report locks the content row, suppresses duplicates by the same member for 24 hours, and atomically quarantines the content and resets associated media review states. A still-open complaint also denies media access through Entitlements. Closing the report does not reapprove or publish content.

## Critical limitations

No approval/publish button, media upload UI, independent age/identity check, performer verification, completeness proof, payment gateway, or Pro video runtime exists. Pending members can report, so spam/quarantine abuse is a serious unresolved issue. Production needs email verification, abuse controls, appeals, investigation, record retention, urgent takedown and legal review. Never enter ID documents or private evidence in the free-text report field. Use fictional accounts and placeholder content only.

## Tests

php tests/moderation_tests.php checks input validation locally. Disposable MySQL installer/browser suites validate the migration, private drafts, CSRF, quarantine, duplicate suppression, audit and lack of approval. GitHub CI is required because the local CLI lacks pdo_mysql.
