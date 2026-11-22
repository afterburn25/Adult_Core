# Encrypted recovery evidence for a future source update

This is a **read-only, CLI-only development gate**, not a backup creator, database migration, site restore, or production updater. It proves an existing `.acfull` encrypted archive is authentic and belongs to the **same installed Adult Core instance**, not merely the same release/edition.

## Supply a separately protected recovery archive and key

Create a full encrypted backup using the existing operator backup tools **before** source maintenance (a backup export requires eligible READY state). Store it in an owner-only 0700 vault outside the installed app, webroots, and any source staging directories; restrict the archive itself to 0600. Keep the offline 256-bit hex backup key in a separately protected 0600 file, and never put the key in command-line arguments, a web form, GitHub, a chat log or the unified source ZIP.

```sh
php scripts/check-update-backup.php \
  /home/account/Adult_Core \
  /home/account/private-recovery-vault/full-site.acfull \
  < /private/keys/offline-backup-key
```

`SourceBackupEvidence::inspect()` checks the signed installed state and source `VERSION`, authenticates the entire AES-256-GCM archive and its database/private-file contents through `BackupVerifier`, confirms the archived signed installation **random app key** matches the current app key, and rejects mismatched Lite/Pro edition or source version. The app/backup keys and private data never appear in output. The archive must be outside the application root and in a private vault. A valid archive from another independently installed site is refused, even if release and edition match. The audit can run while the source HTTP maintenance marker is present, but it cannot be used to bypass pending backup restores, edition/license recovery, or incomplete installation cleanup.

## What a positive result does NOT certify

The report always includes `backup_age_certified: false`, `restore_drill_completed: false`, `database_writers_quiesced: false`, `source_activation_authorized: false`, and `production_ready: false`. It does not prove that the backup is current enough for a business recovery objective, that it has been restored into a fresh independent MySQL/MariaDB environment, that the external key is recoverable by the owner, or that the website is safe to switch. Those must be verified separately. All ten production release gates remain open.

Run `php tests/source_backup_evidence_tests.php` for a disposable suite of AES-GCM, site identity, edition, version, private vault, recovery marker, tampering and no-live-write tests. See also [PORTABLE_BACKUP_RECOVERY.md](PORTABLE_BACKUP_RECOVERY.md), [SOURCE_HTTP_MAINTENANCE.md](SOURCE_HTTP_MAINTENANCE.md), and [SIGNED_RUNTIME_TRANSITION.md](SIGNED_RUNTIME_TRANSITION.md).
