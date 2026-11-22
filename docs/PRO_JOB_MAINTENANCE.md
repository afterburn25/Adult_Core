# Pro signed job intake during maintenance (development foundation)

Adult Core Pro currently provides a signed, replay-resistant **job receipt inbox**. It does not run FFmpeg or any executable video worker. This change ensures that new signed job receipts are refused when the local site is in source-update maintenance, backup maintenance, backup restore recovery, edition-upgrade recovery, or license-update recovery.

The `ProJobAdmission` policy checks local private `storage/` and these block markers without reading untrusted marker contents:

- `.source-update-maintenance`
- `.backup-maintenance`
- `.backup-restore-pending`
- `.edition-upgrade-pending`
- `.license-update-pending`

Each marker blocks if it exists, even if it is an incomplete file, symlink or directory. A symbolic link at the storage root also blocks. `JobInbox::accept()` checks admission after verifying the signed envelope and again just before attempting a receipt file. While blocked, neither first-time acceptance nor idempotent replay is permitted. Existing stored receipt files are left untouched.

## Important security and deployment limitations

**This policy blocks new Pro job receipts only.** It does not stop an already running worker, drain a queue, cancel active FFmpeg processing, revoke an already issued segment, lock database writes, or guarantee that another independent process observes the site maintenance state. It is also vulnerable to a time-of-check/time-of-use race unless the eventual worker and job producer integrate shared maintenance/locking. No production Pro worker is shipped by this feature.

The read-only local `ProJobAdmission::status($siteRoot)` returns `job_receipt_admission_open` and always reports `existing_jobs_drained: false`, `pro_worker_quiesced: false`, and `production_ready: false`. Do not use an open intake status as evidence that a video worker is safe to run.

See [SOURCE_HTTP_MAINTENANCE.md](SOURCE_HTTP_MAINTENANCE.md) for operator HTTP maintenance. Signed Pro job envelope receipt contracts are covered by `tests/pro_release_tests.php`; new offline maintenance cases run through `php tests/pro_job_maintenance_tests.php` and GitHub Actions.

The one self-hosted Lite/Pro application remains key-selected by Cajun Web Pro licensing. Production video, streaming, workers, real VPS acceptance and legal/consent/payment workstreams remain blocked.
