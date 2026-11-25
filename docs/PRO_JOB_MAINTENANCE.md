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

**This policy blocks new Pro job receipts only.** It does not stop an already running worker, drain a queue, cancel active FFmpeg processing, revoke an already issued segment, lock database writes, or guarantee that another independent process observes the site maintenance state. Signed receipt producers now use the shared local lock; every future actual worker must also integrate the execution lifetime guard below. No production Pro worker is shipped by this feature.

The read-only local `ProJobAdmission::status($siteRoot)` returns `job_receipt_admission_open` and always reports `existing_jobs_drained: false`, `pro_worker_quiesced: false`, and `production_ready: false`. Do not use an open intake status as evidence that a video worker is safe to run.

See [SOURCE_HTTP_MAINTENANCE.md](SOURCE_HTTP_MAINTENANCE.md) for operator HTTP maintenance. Signed Pro job envelope receipt contracts are covered by `tests/pro_release_tests.php`; new offline maintenance cases run through `php tests/pro_job_maintenance_tests.php` and GitHub Actions.

## Cooperative local Pro execution lifetime guard (development only)

`ProWorkerExecutionGuard::enter($installedRoot)` is a **new opt-in guard for future
local executable Pro job workers**. The caller must retain its returned PHP
flock handle for the full job lifetime, including media processing, database
writes and cleanup, and close it only after all work is finished. The guard
acquires the existing nonblocking `SourceHttpRequestLock` shared barrier
**before** checking a signed completed installation, Pro edition, and active
maintenance/recovery markers. Lite sites, incomplete installations, recovery
markers, and operator-exclusive source/backup maintenance refuse a new worker.

```php
$guard = \AdultCore\Pro\ProWorkerExecutionGuard::enter($installedRoot);
try {
    // Future isolated Pro executor: perform one already-authorized job here.
    // DO NOT start a worker from an unverified receipt or skip compliance.
} finally {
    fclose($guard);
}
```

An existing guard keeps source-update and backup maintenance from obtaining
their exclusive local request lock **while that cooperating job is active**.
Tests include an independently running PHP worker holder that denies both
maintenance entry points until it releases the guard, a Lite-key denial,
exclusive-lock refusal, and rejection during an existing backup marker.

**This is not a runnable job executor or worker shutdown mechanism.** No
production Pro video worker is currently integrated with this class, and a
noncooperating/remote FFmpeg process, external database writer, static media
service or distributed VPS node can still run. `ProJobReceiptAudit` may still
show pending jobs. Do not mark `pro_worker_quiesced` or any production
readiness/activation flag true. A production executor must integrate and test
this guard at every entry point; operator verification of actual worker
shutdown, database quiescence and source rollback remains mandatory.

The one self-hosted Lite/Pro application remains key-selected by Cajun Web Pro licensing. Production video, streaming, workers, real VPS acceptance and legal/consent/payment workstreams remain blocked.

## Audit existing signed receipts without mutating them

`ProJobReceiptAudit` and `scripts/audit-pro-job-inbox.php` can check every local private Pro receipt's HMAC, original signed envelope and status, then report pending/processing counts. The key comes only from stdin, and counts never prove external workers drained. See [PRO_JOB_RECEIPT_AUDIT.md](PRO_JOB_RECEIPT_AUDIT.md).

## Private receipt persistence and replay (shared-host security)

`JobInbox::accept()` now **requires**, rather than merely attempts, private
`0700` permissions on its `storage/pro-jobs` directory before it creates
or reads any receipt. After exclusive creation it verifies the new file is
an owner-only `0600` regular file with the same inode/device as the opened
handle **before writing** the signed job metadata. Persistence flushes and
fsyncs where supported; a failure rejects intake and removes the incomplete
new receipt. Existing receipts with insecure modes cannot be accepted as
idempotent replays, even if their contents have valid HMACs.

The tests check a newly written receipt's private modes, refusal of a
world-readable replay, and correction of an existing overly permissive
private inbox directory before reading. The tests run only in disposable
directories, not on customer receipts. This is filesystem integrity for
**signed job receipts only**; it does not launch a Pro worker, enable
FFmpeg/streaming, authorize private media publication, or satisfy
production compliance and security signoff.

## Shared source-operation admission lock — local host only

`JobInbox::accept()` now obtains the **same** private shared advisory flock held by dynamic `public/index.php` requests before its first admission-marker check. It holds this lock throughout receipt file creation, data persistence and duplicate-replay verification. `SourceUpdateMaintenance::begin()` and `end()` require an exclusive nonblocking lock on the same persistent `storage/.source-http-requests.lock` file. Whichever operation acquires its lock first wins: the operator refuses to publish the source marker while a signed receipt is being written, and an incoming receipt refuses when maintenance has exclusive control. `ProJobAdmission::status()` also reports closed under an operator's exclusive lock.

`tests/pro_job_maintenance_tests.php` covers intra-process and cross-process exclusive contention, persistence/refusal, symlink lock denial and restored admission; `tests/source_http_maintenance_tests.php` covers a real token-owned source marker with preexisting/blocked/resumed signed Pro receipts.

This is **not** a worker drain, daemon pause, database transaction gate, distributed lock or source updater. A signed pending receipt may still exist when maintenance begins; actual VPS Pro workers may run independently. Do not deploy source code just because this shared lock is functioning. Do not delete/replace the zero-byte owner-only lock file on a running installation.

## Local signed execution lifecycle (development integration API)

`ProJobLifecycle::execute($jobId, $secret, $trustedHandler)` provides a CLI-only execution boundary. It requires a signed installed Pro state, the shared source-maintenance lifetime guard and an exclusive owner-only local worker mutex. It verifies the receipt MAC, job signature, original receipt time and allowed payload before atomically publishing a signed `processing` receipt. Only a pending receipt may execute; done, failed and processing receipts are never automatically retried. A trusted application handler must independently authorize the asset, settle all work and cleanup, and return true for known completion or false for a known settled failure. Exceptions and uncertain results leave the processing receipt intact for review. The persistent `storage/.pro-worker-execution.lock` must not be deleted or replaced while the site is running. This is a single local worker boundary, not a daemon, scheduler, distributed queue, media processor or authorization system. No actual FFmpeg job is dispatched by this API, and no production gates are closed. Tests use synthetic handlers only, verify authenticated transitions, and check maintenance and duplicate execution refusal.
