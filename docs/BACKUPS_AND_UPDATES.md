# Release engineering foundations — 0.8.2-dev

**Not a production backup, recovery, or updater system.** This stage adds authenticated cryptographic primitives and a small-database backup export only. Production 1.0 remains blocked.

## Encrypted database snapshot (CLI-only, small databases)

`src/Backup/EncryptedSnapshot.php` uses AES-256-GCM authenticated encryption with a unique random nonce and a domain-specific AAD. Only a 32-byte random hex key is accepted. The backup key must be stored **offline**, not committed, not in `config/app-key.php` and not placed on the same server as the backups. File formats are versioned and authenticated. Tests check roundtrip, wrong key, modified ciphertext, weak keys and oversized input.

`AC_BACKUP_KEY=... php scripts/backup-db.php /secure/offsite/location.acbak` exports the `ac_` MySQL table rows and encrypts the JSON before writing it to a new file with restricted permissions. The script refuses output under `public/` and refuses overwrite. It is intentionally bounded by size/memory; **no restore is implemented** and media files, server settings, encryption keys and complete DDL are not included. It is only a development snapshot and must not be the only disaster-recovery mechanism.

For real production backups, use provider-native database backups (including DDL), encrypted offsite object storage with key rotation, media/private evidence backups, tested restore to a separate server, point-in-time recovery, retention, and an operator-approved recovery runbook.

## Signed release manifest

`src/Release/SignedManifest.php` verifies a detached RSA-SHA256 signature over exact JSON bytes with a trusted offline public key, validates each file's relative path and SHA-256 digest metadata, and rejects duplicated or traversal paths. It does **not** download releases, verify actual archive file contents or execute an update. Release signing private keys must never be distributed with the app.

Production update tooling still needs secure pinning of the release trust anchor, safe extraction, per-file digest validation, atomic staging, DB migration/backup compatibility, coordinated maintenance mode, rollback and failure injection tests.

## Release gates

Run `php scripts/release-check.php`: a nonzero exit means the build is **not eligible for a production 1.0 label**. Never change the gate just to make CI green. Use [`RELEASE_GATES.md`](RELEASE_GATES.md) as the checklist of externally verified acceptance requirements.
