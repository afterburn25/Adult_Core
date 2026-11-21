# Backup operations, verification and private vault rotation — 0.8.9-backup-ops-dev

**Development safety milestone — NOT a production backup SLA or certified offsite solution.** This extends, but does not change, the original `.acfull` format or the intentionally **fresh/empty-database-only** restore procedure in [Portable Backup Recovery](PORTABLE_BACKUP_RECOVERY.md). All commands are CLI-only and valid for either edition; the installed edition and Cajun Web Pro subscriptions are **not** altered by making a backup. Real host disaster drills remain required.

## 1. Prepare two existing private directories

Prepare a staging directory and a **separate** vault directory **outside the entire Adult_Core application root**, both restricted to the backup operator (`chmod 0700`). The vault is only offsite when **you** supply an authenticated separately hosted/mounted volume or independently replicate its contents: naming a directory `offsite` does not make it remote. A real vault needs private credentials, immutability, encryption-at-rest as applicable, monitoring and an independently tested restore. The software does **not** configure cloud storage or mount a volume.

The backup key must be a separate, securely generated **64-character lowercase hex string** held offline or in a protected secret store, **not** in the code, web config, command-line arguments, logs, or crontab. Keep key recovery separate from archive recovery. Never reuse the Adult_Core application key.

## 2. Verify an existing backup (read-only)

```bash
AC_BACKUP_KEY="$OFFLINE_KEY" php scripts/verify-backup.php /private/staging/saved.acfull
```

This streams every authenticated record, verifies schema/row order, database-field encoding, private paths, per-file length/SHA-256, mandatory files, the signed installation marker, final totals, edition and source-version metadata. It prints **only counts, edition/version and an archive digest**, never recovered rows, photos, the app key, MFA seeds or license keys. It neither writes data nor restores the website. A `0` exit means the archive was verified at that moment; a nonzero exit means it cannot be trusted. It is not proof of successful restoration on the destination host.

## 3. Transfer and rotate an already completed backup

```bash
AC_BACKUP_KEY="$OFFLINE_KEY" php scripts/ship-backup.php \
  /private/staging/saved.acfull /private/mounted-vault 7
```

The sender verifies the source *before* copying, creates a randomized private staging file (`0600`), flushes and synchronizes the copied bytes, verifies every record in the destination, compares SHA-256 digests, then atomically renames to a timestamped vault archive. Only **after** success does it retain the newest 7 named archives. It never replaces an existing archive, never removes the source or unrelated files, refuses symlinked entries, refuses a destination inside the installed site, refuses a source already in the destination vault and refuses concurrent/stale vault locks. Corrupted/unreadable older archives are **preserved for operator investigation**, not silently deleted. Retention is a count of eligible verified copies, not a fixed-day or PITR policy.

The vault must have `0700` permissions. It may be a secured remote mount, but the tool cannot prove the remote host is reachable or that a provider has replicated the bytes; verify that separately. Monitor return codes, free space, skipped invalid archives and stale locks.

## 4. Controlled scheduled backup job

```bash
# FIRST stop/route away all ongoing site writes, uploads, cron/queue jobs and Pro agents.
# These variables are explicit operator acknowledgments, NOT automatic detection.
export AC_BACKUP_KEY="$OFFLINE_KEY"
export AC_BACKUP_WRITES_PAUSED=YES
# On Pro only, after stopping Pro workers:
export AC_PRO_JOBS_STOPPED=YES
php scripts/backup-job.php /private/staging /private/mounted-vault 7
```

The job requires an already valid signed installation, an **empty backup-job lock**, an out-of-root private staging/vault pair, an external-write pause acknowledgment and, on Pro, a **stopped-Pro-worker acknowledgment**. It installs a private `storage/.backup-maintenance` marker that makes **all public and admin HTTP routes return 503**, performs an encrypted backup of the current schema/rows and files, verifies the archive, ships a verified copy, rotates only old verified vault archives, and then clears only the maintenance marker and job lock that it created. During a normal caught failure, the archive may remain in staging for salvage; the job exits nonzero and releases its own marker. After a hard crash, the maintenance marker/lock can persist and **must not be removed until an operator verifies no writer/export is active and the site state is sound**.

A maintenance marker blocks *new* web requests but **cannot stop** an in-flight PHP request or Pro worker that started earlier. Therefore an uncoordinated ordinary cron run on a busy production website is unsafe and does **not** create a consistent snapshot. Arrange actual traffic draining, paused scheduled writes and Pro-worker shutdown with the hosting provider before scheduling this job. The code does not detect or pause external processes. The job keeps its staging archive rather than risking deletion before remote confirmation, so separately monitor/prune staging storage using a documented, owner-approved procedure.

Only after arranging that host-level quiescence, a hosting scheduler/cron can call the same command at a chosen time. Do not place the offline key in a world-readable script, command line, or shared hosting web directory. Treat any backup/sync failure as an actionable monitoring alert.

## 5. Recovery drills, known gaps and operating requirements

Every vault archive should periodically be restored into a **new isolated same-version source + dedicated empty MySQL database**, following the strict runbook above. Never restore into a live installation. Restore still requires manual removal of the top-level `install/` after independent checks. Reconfirm that the original Cajun Web Pro license is valid for the recovered hostname and that administrator MFA recovery works. Private content and subscriber data remain subject to applicable consent/retention laws.

This milestone adds read-only integrity checks, private copy verification, fail-closed maintenance and verified count-based retention. It **does not** add real cloud-provider upload APIs, key management, automated Pro-agent shutdown, point-in-time transactional consistency between mutable files and MySQL, verified cPanel/VPS deployment, disaster-recovery RTO/RPO, immutable provider snapshots, customer payments, adult-content compliance, or permission to launch 1.0. Independent hosting drills and recovery exercises are still release blockers.
