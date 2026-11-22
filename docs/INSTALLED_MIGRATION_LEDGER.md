# Installed database migration ledger audit (read-only)

This CLI checks the **existing** Adult Core MySQL/MariaDB database against its signed locally installed edition, base schema, migration history and table engines. It does not compare any newer candidate release's schema requirements, run migrations, execute DDL, pause database writers, import a backup or authorize an update.

## Run from the private server terminal

```sh
php scripts/check-installed-migrations.php /home/account/Adult_Core
```

Credentials come **only** from the installed site's private `config/database.php`; no DB passwords or public input are accepted in command arguments or response JSON. The operator must be authorized for local database inspection and must not run this command against a server they do not control.

`InstalledMigrationLedger::inspect()` requires a completed signed installation (or the operator's existing source HTTP maintenance marker). It compares the signed Lite/Pro installation edition against `ac_installations`, invokes existing `Schema::verify()` without writes, inventories up to 250 `ac_` InnoDB tables, and reads up to 500 versioned entries in `ac_schema_migrations`. A stable SHA-256 fingerprint of the sorted migration ledger is returned without database credentials, customer identities, row data or private filesystem paths. Unknown or malformed entries, unsupported table types and edition mismatches are refused.

## Why a passing audit is not a migration plan

Even a healthy installed database cannot prove that a separately signed **candidate release** has safe, reversible schema changes. The JSON intentionally reports `candidate_schema_compatibility_verified: false`, `database_writers_quiesced: false`, `migration_rollback_implemented: false`, `ready_for_activation: false` and `production_ready: false`. MySQL DDL auto-commits, so a source rollback may not undo a schema migration. Before live updates, implement publisher-signed migration inventory, schema compatibility negotiation, worker/transaction drain, tested restore points and platform-specific deployment drills.

The test is run against **real disposable MySQL 8 and MariaDB 10.11 databases** by `tests/mysql_install.php`, including deliberately mismatched edition and malformed migration-entry tests with fixture cleanup. Never run the integration suite against a customer database. See [SOURCE_UPDATE_BACKUP_EVIDENCE.md](SOURCE_UPDATE_BACKUP_EVIDENCE.md) for separate recovery archive evidence and [SOURCE_HTTP_MAINTENANCE.md](SOURCE_HTTP_MAINTENANCE.md) for the existing local request gate. All ten production release workstreams remain open.
