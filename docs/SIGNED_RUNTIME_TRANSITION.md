# Signed runtime transition preflight (offline development tool)

This command connects the earlier private source-stage verification to a **trusted baseline of the currently installed release**. It is a read-only diagnostic. It does **not** perform an update, verify licensing, replace code, drain requests, run schema migrations, copy customer files, roll back a deployment, or authorize public launch.

## Trust requirements

Before running it, retain a separately authenticated source ZIP, detached manifest, detached signature, and pinned publisher public key for **both the installed baseline and the newer candidate**. The installed application must still match the signed baseline byte-for-byte for every selected runtime file. Local modifications or unknown executable PHP under `src/` or `public/` cause a refusal. A complete candidate must already have been copied to a separately verified, private staging directory outside all HTTP document roots.

```sh
php scripts/preflight-source-transition.php \
  /home/account/Adult_Core \
  /home/account/private-release-staging/next-release \
  /private/releases/installed.zip \
  /private/releases/installed.manifest.json \
  /private/releases/installed.manifest.sig \
  /private/releases/new-release.zip \
  /private/releases/new-release.manifest.json \
  /private/releases/new-release.manifest.sig \
  /trusted/keys/approved-source-public.pem
```

Only `VERSION`, `src/**`, `public/**`, and `config/editions/**` are included in the **proposed runtime inventory**. Other package files remain part of signed-release authentication, but are not proposed for this source transition. In particular, a removed `install/index.php` must **never** be recreated by an updater; local customer configuration (`config/license.php`, `config/database.php`, and other secrets), storage, themes, add-on modules, tests, documentation, and maintainer tooling are excluded. Modules and themes need their own independent signed lifecycle and migrations.

The check refuses same-version or older candidates, changes to current signed runtime files, path/symlink conflicts, unexpected executable PHP, and candidates that would remove a signed runtime file. It reauthenticates the candidate ZIP, reruns staged-tree verification, and reports exact prospective runtime additions and replacements. For customer privacy, it does not enumerate or inspect unrelated private storage files.

## Acceptance and limitations

```sh
php tests/signed_runtime_preflight_tests.php
php tests/private_source_stage_verifier_tests.php
php tests/release_distribution_tests.php
```

The report intentionally exposes `ready_for_activation: false`, `rollback_implemented: false`, `licensing_checked: false`, and `migration_compatibility_checked: false`. It is not an approval gate that can flip those flags. A fully supported updater still requires secure host maintenance locking, license and compatibility checks, source and database snapshots, commit/rollback coordination, safe source activation under active requests, deployment evidence and independent review. All ten production release workstreams remain open.

## Maintenance-owned installed database evidence (read-only)

A separate CLI, `scripts/check-source-maintenance-db.php`, checks the real installed MySQL/MariaDB migration ledger only while the current operator token owns an intact source HTTP maintenance marker. It holds a nonblocking exclusive **cooperating local PHP** request/receipt/worker lock for two ledger reads, rejects edition and ledger mismatches, and always reports `database_writers_quiesced: false`, `external_pro_workers_quiesced: false`, and `ready_for_activation: false`. See [SOURCE_MAINTENANCE_DATABASE_EVIDENCE.md](SOURCE_MAINTENANCE_DATABASE_EVIDENCE.md). This does not replace candidate signed-archive verification or real SQL writer shutdown.

## Optional joined installed database audit (read-only)

On an **already installed, signed** Lite or Pro website, append
`--verify-installed-db` to the same command. This opens the installed site's
private MySQL/MariaDB configuration only after validating its signed
installation gate, then runs `InstalledMigrationLedger::inspect()` and
`SignedCandidateDbContract::inspectInstalled()` after signed source integrity
checks. No credentials or private migration rows are printed.

```sh
php scripts/preflight-source-transition.php \
  /home/account/Adult_Core \
  /home/account/private-release-staging/next-release \
  /private/releases/installed.zip \
  /private/releases/installed.manifest.json \
  /private/releases/installed.manifest.sig \
  /private/releases/new-release.zip \
  /private/releases/new-release.manifest.json \
  /private/releases/new-release.manifest.sig \
  /trusted/keys/approved-source-public.pem \
  --verify-installed-db
```

Without the explicit flag, the preflight remains offline and reports
`installed_database_migrations_checked: false`. With it, the report verifies
the signed installed Lite/Pro identity matches the database edition and that
every declared baseline migration exists in the **actual** installed migration
ledger. It includes a redacted ledger SHA-256 and edition, or refuses a damaged,
mismatched or incomplete installation. The disposable MySQL/MariaDB fixture
tests this joined check and its CLI, including mismatched edition denial.

**The live database can change immediately after this SELECT-only audit.**
The flag does not hold a persistent database read transaction, drain database
writers, execute candidate migrations, prove schema compatibility, coordinate
external Pro jobs, activate a release or implement rollback. Both modes keep
`migration_compatibility_checked: false`, `database_writers_quiesced: false`,
`ready_for_activation: false`, and `rollback_implemented: false`.
All ten release workstreams remain open.

## Integrated signed database declaration review

The standard `SourceUpdatePreflight::inspect()` / `scripts/preflight-source-transition.php` output now always includes `SignedCandidateDbContract::compare()`. It authenticates the source-bundled database policy in both independent signed ZIPs and fails closed if either declaration is missing, invalid, changed outside its detached signature, or rewrites declared migration history. This prevents a source-only operator from accidentally omitting the separate migration declaration review.

The read-only JSON now exposes `signed_database_contract_checked`, `declared_database_contract_changed`, `new_declared_migration_ids`, `database_sensitive_source_changes` and `manual_database_review_required`. If *any* application PHP file changes, the reviewer requires manual database compatibility assessment, even when the contract itself is unchanged. The gate still returns `migration_compatibility_checked: false`, `installed_database_migrations_checked: false`, `ready_for_activation: false` and `rollback_implemented: false`. It does **not** execute SQL or prove the live SQL migration ledger is compatible. See [SIGNED_CANDIDATE_DB_CONTRACT.md](SIGNED_CANDIDATE_DB_CONTRACT.md) and [INSTALLED_MIGRATION_LEDGER.md](INSTALLED_MIGRATION_LEDGER.md).

`tests/signed_runtime_preflight_tests.php` checks the integrated fields and refuses an independently publisher-signed baseline with no database contract. Old distributions predating the contract require separately reviewed migration planning; no compatibility bypass is provided.
