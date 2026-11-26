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

## Token-owned source-maintenance receipt snapshot (development)

`ProSourceMaintenanceReceiptSnapshot::inspect()` and the CLI
`scripts/check-pro-source-receipts.php` now provide a more restrictive,
**read-only local scan** while source HTTP maintenance is already active.
Unlike the ordinary receipt inventory, this rejects unfinished signed
`pending`/`processing` receipts and requires:

- The operator's **actual** source lease token to match both its private
  lease and its intact source-maintenance marker
- A signed installed **Pro** edition (Lite cannot claim this proof)
- An exclusive **nonblocking local SourceHttpRequestLock** during the scan,
  which refuses if any cooperating local PHP request, Pro receipt writer
  or execution guard is active
- An authenticated HMAC for every bounded local receipt

Both sensitive values arrive through **STDIN**, not arguments: first line
the 64-character source lease token, remainder the protected Pro receipt HMAC
secret. For example, with two separate 0600 private secret files:

```sh
{ cat /private/keys/source-lease-token; printf '\n';
  cat /private/keys/pro-job-hmac-secret; printf '\n'; } |
  php scripts/check-pro-source-receipts.php \
    /home/account/Adult_Core /private/source-coordination
```

A pending job, invalid signature, wrong token, altered marker, Lite site,
unavailable exclusive lock, or inconsistent recovery state returns a nonzero
exit and **no success JSON**. The success report contains only bounded
receipt counts and intentionally reports `external_pro_workers_quiesced: false`,
`database_writers_quiesced: false`, and `ready_for_activation: false`.
The scan is a momentary observation: once it releases the lock, other work
can start if maintenance is ended. There is **no** real worker shutdown,
distributed lock, SQL transaction drain, update activation or production
readiness certification. This does not alter any signed receipt.

Regression: `tests/source_http_maintenance_tests.php` includes a pending
receipt refusal, separately sealed synthetic completed receipts, wrong
token/CLI, forged marker digest, and competing shared-lock refusal.

## During source maintenance

The private inbox audit is read-only and can be run while `storage/.source-update-maintenance` is present. `ProJobAdmission` already prevents new signed receipt intake while maintenance and recovery markers are active. It does not mark existing records done, cancel jobs, drain a queue or release application locks. Before production updates, a real Pro agent must implement authenticated stop/drain acknowledgements and database-side quiescence, paired with a tested rollback strategy.

Regression: `php tests/pro_job_receipt_audit_tests.php`; existing signed envelope and maintenance intake regressions are `tests/pro_release_tests.php` and `tests/pro_job_maintenance_tests.php`. The unified Lite/Pro product remains key-edition selected and production video and streaming gates remain open.

## Local trusted-handler queue dispatch

`ProQueueRunner::runNext($secret,$trustedHandler)` selects one oldest authenticated pending receipt (receipt timestamp then job ID) using a bounded streaming directory scan. It holds the shared maintenance guard and rechecks the selected receipt, oldest-pending selection and absence of interrupted processing jobs under the lifecycle's exclusive worker mutex before claiming. Competing claims fail closed. An empty queue reports idle; a settled result reports done or failed; an uncertain result remains processing and blocks subsequent automatic dispatch until operator review. This is a CLI-only internal one-job dispatch API; trusted handlers remain responsible for asset authorization and side effects. It does not launch FFmpeg, publish media, schedule jobs, or certify external worker shutdown. No production gate is closed.
