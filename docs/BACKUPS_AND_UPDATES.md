# Backup and update engineering

## Development full-site recovery tool — 0.8.8-backup-dev

`src/Backup/{StreamArchive,SiteBackup}.php` and the CLI-only `scripts/{backup-site,restore-site}.php` add a streaming encrypted `.acfull` archive of the Adult_Core database DDL/rows and private config, signed state and media, with fresh-empty-target restore. Read [PORTABLE_BACKUP_RECOVERY.md](PORTABLE_BACKUP_RECOVERY.md) carefully before use. Do not run restoration on a live database; MySQL DDL is not rollback-safe. The archive excludes code binaries, database connection settings, login sessions and temporary locks and requires an independently stored offline key. This is a development disaster-recovery capability, **not production-grade certification**.

## Legacy small database snapshot — 0.8.2-dev

`src/Backup/EncryptedSnapshot.php` and `scripts/backup-db.php` remain available for backwards-compatible small-database **row-only** exports. They do not include DDL, private files or a restore command; never mistake `.acbak` for `.acfull`. These artifacts are independently keyed and versioned. AES-256-GCM authenticates the bounded JSON (max 16 MiB unencrypted), and the output path must be new and outside the public document root.

## Update and release signing

`src/Release/SignedManifest.php` verifies detached RSA-SHA256 against an offline trusted public key and validates path and SHA-256 digest metadata. It does **not** download, verify or apply full release archives, or execute an updater. Release signing private keys never belong in the distributed app.

Before production 1.0: finish independent hosting-specific backup restoration, offsite retention/rotation, integrity monitoring, point-in-time recovery and full release/update rollback with separately reviewed signatures and migration boundaries. `php scripts/release-check.php` must remain a genuine blocking gate.
