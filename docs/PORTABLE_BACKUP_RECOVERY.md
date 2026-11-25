## New in 0.8.9 — verify/copy and coordinated maintenance

The existing archive format and this empty-target restore command are unchanged. Optional read-only `scripts/verify-backup.php`, `scripts/ship-backup.php` and coordinated `scripts/backup-job.php` are described in [Backup Operations](BACKUP_OPERATIONS.md). A private vault path is **not** proof of offsite replication. Pause in-flight writes and Pro workers at the host before running the job; the new fail-closed maintenance marker only blocks new HTTP requests. Always rehearse a full isolated restore.

---

# Encrypted portable backup and disaster restore — 0.8.8-backup-dev

**Development safety milestone, not a production certification.** Lite and Pro use the same CLI tools and the installed license remains bound to its original Cajun Web Pro SKU/domain. No recurring payment, CWP license, billing record or identity verification is created by restoration. No browser download/upload interface is provided.

## What is protected

- **Database:** every `ac_` InnoDB base table, its `SHOW CREATE TABLE` DDL, and all rows including admin MFA configuration, members, themes, site pages, entitlement records and migration history.
- **Private files:** uploaded design and private media, trusted installed CSS-only themes/packages, module license records, signed install state/cache, private app key, license key configuration, optional email/verification/Pro-agent config and trusted publisher certificates.
- **Excluded deliberately:** live administrator/member sessions, login rate-counter files, temporary lock files, backup archives, installer source, application code, generated public assets and DB connection settings. Restore uses the operator's *new* `config/database.php` to target a fresh dedicated database; no source MySQL password is copied to the new host.

Records are streamed through AES-256-GCM with a random salt/unique nonce per record and authenticated sequencing, plus a mandatory authenticated final record. Media is chunked in 64 KiB blocks with per-file SHA-256 verification and full length checks. This **does not** guarantee point-in-time consistency across MySQL and mutable files during a live upload or Pro job. Pause traffic, workers, uploads, package changes, migrations and writing processes before taking a backup.

The current safety caps are **20 GiB of private files, 50,000 private files, 3 million database rows, and about 1.5 MiB per encrypted database record**. Larger installations need a separately engineered and tested streaming database-export strategy. Perform scheduled offsite encrypted copies and independent recovery drills using your hosting provider's backup/PITR facilities too.

## Backup (source hosting)

From the *installed* Adult_Core root, with `install/` already physically removed and all site writes quiesced:

```bash
# Generate with a cryptographically secure random 32-byte key; never commit or email it.
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'

# Set AC_BACKUP_KEY only in this shell or a secret manager; DO NOT put it in scripts or web config.
# First stop independent SQL/filesystem writers and uploads; for Pro also stop workers.
export AC_BACKUP_WRITES_PAUSED=YES
# On Pro only, after stopping all Pro workers: export AC_PRO_JOBS_STOPPED=YES
AC_BACKUP_KEY="$OFFLINE_KEY" php scripts/backup-site.php /private/offsite-backups/adult-core-2026-11-20.acfull
```

The manual command now requires the same token-owned local maintenance admission as the scheduled backup job. It refuses active cooperating local requests/receipts/workers, confirms the signed installed edition matches MySQL, authenticates the completed archive against this site's app key, and reports its SHA-256 only after verified cleanup. Operator acknowledgments are still required; this does not independently stop external writers or workers. A hard crash retains the marker for operator review.

The destination must be a **new file in an owner-only directory outside the entire site root**. The file is permission-restricted; copy it to separately controlled off-server storage. Check it can be decrypted in a nonproduction drill. Without the offline key it is not recoverable. Never use `config/app-key.php` as the backup key.

## Restore (never on a live site)

1. Provision an isolated **empty, dedicated MySQL/MariaDB database** and install the exact same Adult_Core source version (Lite and Pro use the same code ZIP). Do not run the browser installer. Preserve `install/index.php` until the recovery finishes.
2. Supply only the *new* private `config/database.php` connection config; do not create `config/app-key.php`, `config/license.php`, `storage/install-state.json` or anything else in `storage/` except `.gitkeep`. Keep `public/` as the web document root.
3. Put the encrypted archive outside the new site root and supply the separately stored key.
4. Run from the new source root:

```bash
AC_BACKUP_KEY="$OFFLINE_KEY" php scripts/restore-site.php --confirm-empty-target /private/offsite-backups/adult-core-2026-11-20.acfull
```

5. Check database rows, photos, member access restrictions, admin MFA recovery, chosen theme, Pro worker compatibility, file permissions, and the site's current CWP domain registration. Only **after** successful verification, manually remove `install/` as required by the original installer security policy. Renew/re-register a changed domain via the proper Cajun Web Pro account, not by editing a license cache.

The tool refuses any existing installed site or any nonempty target database. The restore also obtains the same nonblocking database-scoped advisory lock as the installer before staging or modifying the database. A contending cooperating provisioning session is refused without staging files or writing SQL. The lock is held across preflight, import and private file publication. This does not exclude independent SQL clients or older application versions that do not use this lock; an isolated empty target remains mandatory. It preflights authenticated archive records, path validation, file hashes, exact source version and signed install state **before** importing database tables. It imports schema/rows and then publishes staged private files. An interrupted or failed import can leave partial MySQL DDL (MySQL DDL auto-commits). The persistent `storage/.backup-restore-pending` file blocks all site/admin HTTP routes: **never delete it to bypass recovery**. Wipe the failed, isolated target, create a new empty database/source tree and retry from the encrypted archive; do not attempt to patch or merge into an existing installation.

## Authenticated stream identity and restore substitution protection

The archive reader now computes SHA-256 directly over the encrypted bytes it authenticates, instead of reopening the filename after verification. It refuses a replaced inode or detectable size/timestamp change during reading. New archives are created owner-only, flushed and synced where supported; a failed write does not unlink a replacement file owned by a different operation. The archive format remains unchanged.

Restore binds the database row import to the exact authenticated archive fingerprint established during preflight. A different archive, even one independently authenticated with the same key, cannot commit imported rows. If replacement is discovered after DDL began, InnoDB row changes are rolled back and the recovery marker is retained; no staged private files are published. Reinitialize the disposable failed target as described above. This is not filesystem snapshot isolation or a protection against arbitrary noncooperating same-account writers; keep archives and their containing directories private and unchanged throughout restore.

## Verification and outstanding work

- Local: `php tests/backup_tests.php` and the existing test suites (archive cryptography, tampering/truncation/replay and unsafe-path handling).
- Disposable MySQL CI: `php tests/mysql_backup_restore.php` creates a throwaway site, migrates page/design/member data, stores private photos, exports, rejects wrong-key/truncated restores, restores to a fresh target, compares exact private bytes and data, rejects a second restore, and requires manual installer deletion. This does **not** touch a real site.
- **Still open:** independent operator drill on cPanel/shared hosting and a Pro VPS, remote offsite storage and rotation/retention, real large media sets, high-availability write quiescence, binlog/PITR, security review, application source/package restore across upgrades and disaster recovery RTO/RPO guarantees. These are required before a production 1.0 claim.
