# Offline signed Pro inbox receipt audit — development only

Adult Core Pro currently stores signed and replay-safe **job receipts**, not a production video queue or FFmpeg worker. This operator tool audits those local receipt files without modifying them. It is intended as a diagnostic input for future source maintenance, not as permission to start an update.

## Run privately

Use the actual Pro receipt HMAC secret from a separately protected key source. **Do not place it in a URL, command-line argument, public log, GitHub issue or release ZIP.** Save a copy in an operator-only 0600 file outside the web document root. For example:

```sh
php scripts/audit-pro-job-inbox.php \
  /home/account/Adult_Core \
  < /private/keys/pro-job-hmac-secret
```

The tool inventories `storage/pro-jobs` only. It refuses a symlink or non-private inbox, unknown entries, oversized receipts, insecure file modes, malformed JSON, mismatched hashed filenames, invalid HMAC receipts, or a job envelope whose original signature does not match the key. Each receipt is bounded to 20 KiB and the scan to at most 2,000 receipts. It never prints the HMAC secret, identifiers, private filesystem paths, job payloads, member content or media bytes.

The versioned report contains counts for `pending_receipts`, `processing_receipts`, `done_receipts`, `failed_receipts` and `signed_receipts_verified`, plus `no_locally_open_receipts`. Even when the last flag is true, **`external_workers_quiesced`, `database_writes_quiesced` and `ready_for_activation` must remain false**. A clean local receipt inventory cannot prove that a separately hosted worker or preexisting FFmpeg task has stopped.

## During source maintenance

The private inbox audit is read-only and can be run while `storage/.source-update-maintenance` is present. `ProJobAdmission` already prevents new signed receipt intake while maintenance and recovery markers are active. It does not mark existing records done, cancel jobs, drain a queue or release application locks. Before production updates, a real Pro agent must implement authenticated stop/drain acknowledgements and database-side quiescence, paired with a tested rollback strategy.

Regression: `php tests/pro_job_receipt_audit_tests.php`; existing signed envelope and maintenance intake regressions are `tests/pro_release_tests.php` and `tests/pro_job_maintenance_tests.php`. The unified Lite/Pro product remains key-edition selected and production video and streaming gates remain open.
