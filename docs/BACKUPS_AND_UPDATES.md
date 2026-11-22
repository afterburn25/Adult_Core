# Backup and update engineering

## Development full-site recovery tool — 0.8.8-backup-dev

`src/Backup/{StreamArchive,SiteBackup}.php` and the CLI-only `scripts/{backup-site,restore-site}.php` add a streaming encrypted `.acfull` archive of the Adult_Core database DDL/rows and private config, signed state and media, with fresh-empty-target restore. Read [PORTABLE_BACKUP_RECOVERY.md](PORTABLE_BACKUP_RECOVERY.md) carefully before use. Do not run restoration on a live database; MySQL DDL is not rollback-safe. The archive excludes code binaries, database connection settings, login sessions and temporary locks and requires an independently stored offline key. This is a development disaster-recovery capability, **not production-grade certification**.

## Legacy small database snapshot — 0.8.2-dev

`src/Backup/EncryptedSnapshot.php` and `scripts/backup-db.php` remain available for backwards-compatible small-database **row-only** exports. They do not include DDL, private files or a restore command; never mistake `.acbak` for `.acfull`. These artifacts are independently keyed and versioned. AES-256-GCM authenticates the bounded JSON (max 16 MiB unencrypted), and the output path must be new and outside the public document root.

## Update and release signing

`src/Release/SignedManifest.php` verifies detached RSA-SHA256 against an offline trusted public key and validates path and SHA-256 digest metadata. It does **not** download, verify or apply full release archives, or execute an updater. Release signing private keys never belong in the distributed app.

Before production 1.0: finish independent hosting-specific backup restoration, offsite retention/rotation, integrity monitoring, point-in-time recovery and full release/update rollback with separately reviewed signatures and migration boundaries. `php scripts/release-check.php` must remain a genuine blocking gate.

## Backup maintenance admission now shares the request / Pro receipt barrier — 2026-11-22

`scripts/backup-job.php` now uses `BackupMaintenanceAdmission::begin()` and `::finish()`. Before publishing `storage/.backup-maintenance`, it must obtain the same nonblocking **exclusive** local `SourceHttpRequestLock` used by source maintenance. READY dynamic PHP requests and signed Pro job RECEIPT writers hold shared locks through their operations; this avoids beginning backup while a cooperating request or receipt writer is still active. The backup marker is created exclusively, saved in private storage (0600) and flushed before the interlock is released. If the marker is corrupted, replaced, or another higher-priority recovery is active, cleanup **refuses to reopen** the site and requires operator recovery.

This does **not** stop Pro transcode workers or independent database and filesystem writers. The existing explicit operator `AC_BACKUP_WRITES_PAUSED=YES` and (for Pro) `AC_PRO_JOBS_STOPPED=YES` requirements remain, but are declarations rather than technical worker quiescence proofs. Local advisory `flock` is not distributed. Do not claim backup consistency or live cutover based only on the interlock. The existing real encrypted backup verification and isolated restore exercises remain separate safety checks.

`tests/source_http_maintenance_tests.php` tests marker-free refusal with an in-flight shared reader, private marker storage, signed Pro receipt refusal, corrupt-marker fail-closed, and safe reopening after ownership verification. See also [SOURCE_HTTP_MAINTENANCE.md](SOURCE_HTTP_MAINTENANCE.md) and [PRO_JOB_MAINTENANCE.md](PRO_JOB_MAINTENANCE.md). Production update and restore gates remain open.
